<?php
/**
 * ScraperAPI Adapter (Value Tier)
 * Uses ScraperAPI for proxy rotation and captcha bypass.
 * 
 * @package RawWire_Dashboard
 * @subpackage Toolbox_Core
 */
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../class-adapter-base.php';
require_once dirname(dirname(__DIR__)) . '/interfaces/interface-scraper.php';

class RawWire_Adapter_Scraper_API extends RawWire_Adapter_Base implements RawWire_Scraper_Interface {
    
    protected $name = 'ScraperAPI';
    protected $version = '1.0.0';
    protected $tier = 'value';
    protected $capabilities = array('html_parse', 'proxy_rotation', 'captcha_bypass', 'javascript_render');
    protected $required_fields = array('api_key');

    const API_BASE = 'https://api.scraperapi.com';

    /**
     * Test connection with API
     */
    public function test_connection() {
        $validation = $this->validate_config();
        if (is_wp_error($validation)) {
            return array(
                'success' => false,
                'message' => $validation->get_error_message(),
            );
        }

        try {
            // Test with a simple request
            $url = add_query_arg(array(
                'api_key' => $this->get_config('api_key'),
                'url' => 'https://httpbin.org/ip',
            ), self::API_BASE);

            $response = $this->http_request($url, array('timeout' => 30));

            if (is_wp_error($response)) {
                return array(
                    'success' => false,
                    'message' => 'API connection failed: ' . $response->get_error_message(),
                );
            }

            $body = wp_remote_retrieve_body($response);
            $code = wp_remote_retrieve_response_code($response);

            if ($code === 200) {
                $this->log('ScraperAPI connection test passed', 'info');
                return array(
                    'success' => true,
                    'message' => 'ScraperAPI connection successful',
                    'details' => array(
                        'capabilities' => $this->capabilities,
                        'response' => json_decode($body, true),
                    ),
                );
            }

            return array(
                'success' => false,
                'message' => "API returned status $code",
            );
        } catch (Exception $e) {
            $this->set_error('test_failed', $e->getMessage());
            return array(
                'success' => false,
                'message' => 'Exception: ' . $e->getMessage(),
            );
        }
    }

    /**
     * Scrape content from a URL
     */
    public function scrape(string $url, array $options = array()) {
        $validation = $this->validate_config();
        if (is_wp_error($validation)) {
            return array('success' => false, 'error' => $validation->get_error_message());
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $this->set_error('invalid_url', 'Invalid URL provided');
            return array('success' => false, 'error' => 'Invalid URL');
        }

        // Build API request
        $params = array(
            'api_key' => $this->get_config('api_key'),
            'url' => $url,
        );

        // Add optional parameters
        if ($this->get_config('render_js', false) || ($options['render_js'] ?? false)) {
            $params['render'] = 'true';
        }

        if ($country = $this->get_config('country_code')) {
            $params['country_code'] = $country;
        }

        if (isset($options['premium'])) {
            $params['premium'] = $options['premium'] ? 'true' : 'false';
        }

        if (isset($options['session_number'])) {
            $params['session_number'] = intval($options['session_number']);
        }

        $api_url = add_query_arg($params, self::API_BASE);

        $this->log('ScraperAPI request', 'info', array('target_url' => $url, 'render_js' => $params['render'] ?? 'false'));

        $response = $this->http_request($api_url, array(
            'timeout' => $options['timeout'] ?? 60,
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message(),
            );
        }

        $html = wp_remote_retrieve_body($response);
        $headers = wp_remote_retrieve_headers($response);

        // Extract data if selectors provided
        $data = array();
        if (!empty($options['selectors'])) {
            $data = $this->extract($html, $options['selectors']);
        }

        $this->log('ScraperAPI scrape completed', 'info', array('url' => $url, 'size' => strlen($html)));

        return array(
            'success' => true,
            'html' => $html,
            'data' => $data,
            'meta' => array(
                'content_length' => strlen($html),
                'scraped_at' => current_time('mysql'),
                'adapter' => 'scraperapi',
            ),
        );
    }

    /**
     * Scrape multiple URLs
     */
    public function scrape_batch(array $urls, array $options = array()) {
        $results = array();
        
        foreach ($urls as $url) {
            $results[$url] = $this->scrape($url, $options);
            
            // Small delay between requests to be respectful
            if ($url !== end($urls)) {
                usleep(500000); // 500ms
            }
        }

        return $results;
    }

    /**
     * Extract structured data from HTML
     */
    public function extract(string $html, array $selectors) {
        $data = array();

        if (empty($html)) {
            return $data;
        }

        $prev_use_errors = libxml_use_internal_errors(true);

        try {
            $dom = new DOMDocument();
            $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            $xpath = new DOMXPath($dom);

            foreach ($selectors as $field => $selector) {
                $data[$field] = $this->query_selector($xpath, $selector);
            }
        } catch (Exception $e) {
            $this->log('DOM parsing failed', 'warning', array('error' => $e->getMessage()));
        }

        libxml_clear_errors();
        libxml_use_internal_errors($prev_use_errors);

        return $data;
    }

    /**
     * Query using CSS selector
     */
    private function query_selector(DOMXPath $xpath, $selector) {
        $xpathQuery = $this->css_to_xpath($selector);
        
        try {
            $nodes = $xpath->query($xpathQuery);
            
            if ($nodes === false || $nodes->length === 0) {
                return null;
            }

            if ($nodes->length === 1) {
                return trim($nodes->item(0)->textContent);
            }

            $values = array();
            foreach ($nodes as $node) {
                $values[] = trim($node->textContent);
            }
            return $values;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Convert CSS to XPath
     */
    private function css_to_xpath(string $selector) {
        $selector = trim($selector);
        
        if (preg_match('/^#([\w-]+)$/', $selector, $m)) {
            return "//*[@id='{$m[1]}']";
        }
        if (preg_match('/^\.([\w-]+)$/', $selector, $m)) {
            return "//*[contains(@class, '{$m[1]}')]";
        }
        if (preg_match('/^([\w]+)\.([\w-]+)$/', $selector, $m)) {
            return "//{$m[1]}[contains(@class, '{$m[2]}')]";
        }
        if (preg_match('/^[\w]+$/', $selector)) {
            return "//{$selector}";
        }

        return $selector;
    }

    /**
     * Get rate limit status (ScraperAPI handles this internally)
     */
    public function get_rate_limit_status() {
        return array(
            'remaining' => -1, // Unknown - managed by ScraperAPI
            'limit' => -1,
            'reset_at' => 0,
            'note' => 'Rate limiting handled by ScraperAPI',
        );
    }
}
