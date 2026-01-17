<?php
/**
 * Search Modules Tests
 */

class RawWire_Search_Modules_Test extends WP_UnitTestCase {
    public function setUp(): void {
        parent::setUp();
        // Load search modules
        require_once plugin_dir_path(__DIR__) . 'includes/search/search-module-base.php';
        require_once plugin_dir_path(__DIR__) . 'includes/search/modules/class-keyword.php';
        require_once plugin_dir_path(__DIR__) . 'includes/search/modules/class-category.php';
        require_once plugin_dir_path(__DIR__) . 'includes/search/modules/class-date.php';
        require_once plugin_dir_path(__DIR__) . 'includes/search/modules/class-relevance.php';
    }

    public function test_keyword_module_basic_search() {
        $module = new RawWire_Search_Keyword_Module();
        $state = ['where' => '1=1', 'args' => []];
        $params = ['q' => 'test query'];

        $result = $module->apply($state, $params);

        $this->assertStringContains('title LIKE %s OR content LIKE %s OR summary LIKE %s', $result['where']);
        $this->assertCount(3, $result['args']);
        $this->assertStringStartsWith('%test query%', $result['args'][0]);
    }

    public function test_keyword_module_empty_query() {
        $module = new RawWire_Search_Keyword_Module();
        $state = ['where' => '1=1', 'args' => []];
        $params = ['q' => ''];

        $result = $module->apply($state, $params);

        $this->assertEquals('1=1', $result['where']);
        $this->assertEmpty($result['args']);
    }

    public function test_keyword_module_no_query_param() {
        $module = new RawWire_Search_Keyword_Module();
        $state = ['where' => '1=1', 'args' => []];
        $params = [];

        $result = $module->apply($state, $params);

        $this->assertEquals('1=1', $result['where']);
        $this->assertEmpty($result['args']);
    }

    public function test_keyword_module_validation() {
        $module = new RawWire_Search_Keyword_Module();

        // Valid params
        $this->assertTrue($module->validate(['q' => 'test']));

        // Invalid params (but keyword module accepts anything)
        $this->assertTrue($module->validate([]));
        $this->assertTrue($module->validate(['invalid' => 'param']));
    }

    public function test_category_module_basic_filter() {
        $module = new RawWire_Search_Category_Module();
        $state = ['where' => '1=1', 'args' => []];
        $params = ['category' => 'tech'];

        $result = $module->apply($state, $params);

        $this->assertStringContains('category = %s', $result['where']);
        $this->assertEquals('tech', $result['args'][0]);
    }

    public function test_category_module_empty_category() {
        $module = new RawWire_Search_Category_Module();
        $state = ['where' => '1=1', 'args' => []];
        $params = ['category' => ''];

        $result = $module->apply($state, $params);

        $this->assertEquals('1=1', $result['where']);
        $this->assertEmpty($result['args']);
    }

    public function test_date_module_date_range() {
        $module = new RawWire_Search_Date_Module();
        $state = ['where' => '1=1', 'args' => []];
        $params = ['date_from' => '2024-01-01', 'date_to' => '2024-12-31'];

        $result = $module->apply($state, $params);

        $this->assertStringContains('created_at >= %s AND created_at <= %s', $result['where']);
        $this->assertEquals('2024-01-01 00:00:00', $result['args'][0]);
        $this->assertEquals('2024-12-31 23:59:59', $result['args'][1]);
    }

    public function test_date_module_partial_range() {
        $module = new RawWire_Search_Date_Module();
        $state = ['where' => '1=1', 'args' => []];
        $params = ['date_from' => '2024-01-01'];

        $result = $module->apply($state, $params);

        $this->assertStringContains('created_at >= %s', $result['where']);
        $this->assertEquals('2024-01-01 00:00:00', $result['args'][0]);
    }

    public function test_relevance_module_sorting() {
        $module = new RawWire_Search_Relevance_Module();
        $state = ['where' => '1=1', 'args' => [], 'orderby' => ''];
        $params = ['sort' => 'relevance', 'q' => 'test'];

        $result = $module->apply($state, $params);

        $this->assertStringContains('ORDER BY relevance_score DESC', $result['orderby']);
    }

    public function test_relevance_module_default_sorting() {
        $module = new RawWire_Search_Relevance_Module();
        $state = ['where' => '1=1', 'args' => [], 'orderby' => ''];
        $params = ['sort' => 'date'];

        $result = $module->apply($state, $params);

        $this->assertStringContains('ORDER BY created_at DESC', $result['orderby']);
    }

    public function test_module_priorities() {
        $keyword = new RawWire_Search_Keyword_Module();
        $category = new RawWire_Search_Category_Module();
        $date = new RawWire_Search_Date_Module();
        $relevance = new RawWire_Search_Relevance_Module();

        $this->assertEquals(10, $keyword->get_priority());
        $this->assertEquals(10, $category->get_priority());
        $this->assertEquals(10, $date->get_priority());
        $this->assertEquals(5, $relevance->get_priority()); // Higher priority
    }

    public function test_module_names() {
        $keyword = new RawWire_Search_Keyword_Module();
        $category = new RawWire_Search_Category_Module();
        $date = new RawWire_Search_Date_Module();
        $relevance = new RawWire_Search_Relevance_Module();

        $this->assertEquals('keyword', $keyword->get_name());
        $this->assertEquals('category', $category->get_name());
        $this->assertEquals('date', $date->get_name());
        $this->assertEquals('relevance', $relevance->get_name());
    }
}