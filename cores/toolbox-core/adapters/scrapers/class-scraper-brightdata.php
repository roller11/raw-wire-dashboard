<?php
/**
 * BrightData Scraper Adapter (Flagship Tier)
 * Enterprise-grade scraping with residential IPs and browser automation.
 * 
 * @package RawWire_Dashboard
 * @subpackage Toolbox_Core
 */
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../class-adapter-base.php';
require_once dirname(dirname(__DIR__)) . '/interfaces/interface-scraper.php';

class RawWire_Adapter_Scraper_BrightData extends RawWire_Adapter_Base implements RawWire_Scraper_Interface {
    
    protected $name = 'BrightData Scraper';
    protected $version = '1.0.0';
    protected $tier = 'flagship';
    protected $capabilities = array('html_parse', 'proxy_rotation', 'captcha_bypass', 'javascript_render', 'residential_ips', 'browser_automation');
    protected $required_fields = array('customer_id', 'zone_username', 'zone_password');

    /**
     * Test connection with BrightData
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
            // Test by making a request through BrightData proxy
            $proxy = $this->build_proxy_url();
            
            $response = wp_remote_get('https://lumtest.com/myip.json', array(
                'timeout' => 30,
                'proxy' => $proxy,
            ));

            if (is_wp_error($response)) {
                return array(
                    'success' => false,
                    'message' => 'BrightData connection failed: ' . $response->get_error_message(),
                );
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            $this->log('BrightData connection test passed', 'info', array('ip' => $data['ip'] ?? 'unknown'));

            return array(
                'success' => true,
                'message' => 'BrightData connection successful',
                'details' => array(
                    'capabilities' => $this->capabilities,
                    'proxy_ip' => $data['ip'] ?? 'unknown',
                    'country' => $data['country'] ?? 'unknown',
                ),
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
     * Build BrightData proxy URL
     */
    private function build_proxy_url() {
        $username = $this->get_config('zone_username');
        $password = $this->get_config('zone_password');
        $datacenter = $this->get_config('datacenter', 'dc');

        // Different hostnames for different zone types
        $hosts = array(
            'dc' => 'brd.superproxy.io:22225',
            'residential' => 'brd.superproxy.io:22225',
            'mobile' => 'brd.superproxy.io:22225',
        );

        $host = $hosts[$datacenter] ?? $hosts['dc'];

        return "http://{$username}:{$password}@{$host}";
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

        $proxy = $this->build_proxy_url();

        // Add session for consistent IP if needed
        if (!empty($options['session_id'])) {
            $proxy = str_replace('@', "-session-{$options['session_id']}@", $proxy);
        }

        // Add country targeting
        if (!empty($options['country'])) {
            $proxy = str_replace('@', "-country-{$options['country']}@", $proxy);
        }

        $this->log('BrightData scrape request', 'info', array('url' => $url));

        $args = array(
            'timeout' => $options['timeout'] ?? 60,
            'proxy' => $proxy,
            'headers' => array(
                'User-Agent' => $options['user_agent'] ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
            ),
        );

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            $this->set_error('scrape_failed', $response->get_error_message());
            return array(
                'success' => false,
                'error' => $response->get_error_message(),
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 400) {
            $this->set_error('http_error', "HTTP $code received");
            return array(
                'success' => false,
                'error' => "HTTP error: $code",
            );
        }

        $html = wp_remote_retrieve_body($response);
        $headers = wp_remote_retrieve_headers($response);

        // Extract data if selectors provided
        $data = array();
        if (!empty($options['selectors'])) {
            $data = $this->extract($html, $options['selectors']);
        }

        $this->log('BrightData scrape completed', 'info', array('url' => $url, 'size' => strlen($html)));

        return array(
            'success' => true,
            'html' => $html,
            'data' => $data,
            'meta' => array(
                'content_length' => strlen($html),
                'scraped_at' => current_time('mysql'),
                'adapter' => 'brightdata',
                'datacenter' => $this->get_config('datacenter', 'dc'),
            ),
        );
    }

    /**
     * Scrape multiple URLs
     */
    public function scrape_batch(array $urls, array $options = array()) {
        $results = array();
        $session_id = $options['session_id'] ?? wp_generate_uuid4();

        foreach ($urls as $index => $url) {
            // Use same session for batch to maintain IP
            $opts = array_merge($options, array('session_id' => $session_id));
            $results[$url] = $this->scrape($url, $opts);
            
            // Delay between requests
            if ($index < count($urls) - 1) {
                usleep(($options['delay'] ?? 1000) * 1000);
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
     * Query using selector
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
     * CSS to XPath conversion
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
     * Get rate limit status
     */
    public function get_rate_limit_status() {
        return array(
            'remaining' => -1,
            'limit' => -1,
            'reset_at' => 0,
            'note' => 'Rate limiting managed by BrightData account',
        );
    }
}
