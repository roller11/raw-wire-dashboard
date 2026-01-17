<?php
/**
 * Native PHP Scraper Adapter (Free Tier)
 * Uses WordPress HTTP API and DOMDocument for basic scraping.
 * 
 * @package RawWire_Dashboard
 * @subpackage Toolbox_Core
 */
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../class-adapter-base.php';
require_once dirname(dirname(__DIR__)) . '/interfaces/interface-scraper.php';

class RawWire_Adapter_Scraper_Native extends RawWire_Adapter_Base implements RawWire_Scraper_Interface {
    
    protected $name = 'Native PHP Scraper';
    protected $version = '1.0.0';
    protected $tier = 'free';
    protected $capabilities = array('html_parse', 'basic_http');
    protected $required_fields = array();

    /**
     * Rate limiting tracker
     * @var array
     */
    private $rate_limit = array(
        'requests' => 0,
        'window_start' => 0,
        'limit' => 60, // requests per minute
    );

    /**
     * Test connection by making a simple request
     */
    public function test_connection() {
        try {
            $response = $this->http_request('https://httpbin.org/get', array('timeout' => 10));
            
            if (is_wp_error($response)) {
                return array(
                    'success' => false,
                    'message' => 'Connection test failed: ' . $response->get_error_message(),
                );
            }

            return array(
                'success' => true,
                'message' => 'Native scraper is operational',
                'details' => array(
                    'capabilities' => $this->capabilities,
                    'rate_limit' => $this->rate_limit['limit'] . ' requests/minute',
                ),
            );
        } catch (Exception $e) {
            $this->set_error('test_failed', $e->getMessage());
            return array(
                'success' => false,
                'message' => 'Exception during test: ' . $e->getMessage(),
            );
        }
    }

    /**
     * Scrape content from a URL
     */
    public function scrape(string $url, array $options = array()) {
        // Validate URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $this->set_error('invalid_url', 'Invalid URL provided');
            return array('success' => false, 'error' => 'Invalid URL');
        }

        // Append query parameters if provided
        if (!empty($options['params']) && is_array($options['params'])) {
            $separator = (strpos($url, '?') === false) ? '?' : '&';
            $url .= $separator . http_build_query($options['params']);
        }

        // Check rate limit
        if (!$this->check_rate_limit()) {
            return array(
                'success' => false,
                'error' => 'Rate limit exceeded. Please wait before making more requests.',
            );
        }

        // Build request args with default headers
        $default_headers = array(
            'User-Agent' => $options['user_agent'] ?? 'RawWire-Bot/1.0 (WordPress)',
            'Accept' => 'application/json,text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.5',
        );
        
        // Merge with custom headers if provided
        if (!empty($options['headers']) && is_array($options['headers'])) {
            $default_headers = array_merge($default_headers, $options['headers']);
        }
        
        $args = array(
            'timeout' => $options['timeout'] ?? 30,
            'headers' => $default_headers,
        );

        $this->log('Scraping URL', 'info', array('url' => $url));

        $response = $this->http_request($url, $args);

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message(),
            );
        }

        $body = wp_remote_retrieve_body($response);
        $headers = wp_remote_retrieve_headers($response);
        $content_type = $headers['content-type'] ?? 'unknown';

        // Check if response is JSON
        $data = array();
        $is_json = strpos($content_type, 'application/json') !== false || 
                   strpos($content_type, 'text/json') !== false;
        
        // Also try to detect JSON by content
        if (!$is_json) {
            $trimmed = ltrim($body);
            $is_json = ($trimmed[0] ?? '') === '{' || ($trimmed[0] ?? '') === '[';
        }
        
        if ($is_json) {
            $json_data = json_decode($body, true);
            if ($json_data !== null) {
                $data = $json_data;
                $this->log('Parsed JSON response', 'debug', array('url' => $url, 'keys' => array_keys($data)));
            }
        }

        // Extract data from HTML if selectors provided and no JSON data
        if (empty($data) && !empty($options['selectors'])) {
            $data = $this->extract($body, $options['selectors']);
        }

        $this->log('Scrape completed', 'info', array('url' => $url, 'size' => strlen($body)));

        return array(
            'success' => true,
            'html' => $body,
            'data' => $data,
            'meta' => array(
                'content_type' => $content_type,
                'content_length' => strlen($body),
                'scraped_at' => current_time('mysql'),
                'is_json' => $is_json,
            ),
        );
    }

    /**
     * Scrape multiple URLs
     */
    public function scrape_batch(array $urls, array $options = array()) {
        $results = array();
        $delay = $options['delay'] ?? 1000; // ms between requests

        foreach ($urls as $url) {
            $results[$url] = $this->scrape($url, $options);
            
            // Respect delay between requests
            if ($delay > 0 && $url !== end($urls)) {
                usleep($delay * 1000);
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

        // Suppress libxml errors
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
     * Query using CSS selector (converted to XPath)
     */
    private function query_selector(DOMXPath $xpath, $selector) {
        // Convert simple CSS selectors to XPath
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
     * Convert basic CSS selectors to XPath
     */
    private function css_to_xpath(string $selector) {
        // Handle common CSS selectors
        $selector = trim($selector);
        
        // ID selector: #id
        if (preg_match('/^#([\w-]+)$/', $selector, $matches)) {
            return "//*[@id='{$matches[1]}']";
        }
        
        // Class selector: .class
        if (preg_match('/^\.([\w-]+)$/', $selector, $matches)) {
            return "//*[contains(@class, '{$matches[1]}')]";
        }
        
        // Tag with class: tag.class
        if (preg_match('/^([\w]+)\.([\w-]+)$/', $selector, $matches)) {
            return "//{$matches[1]}[contains(@class, '{$matches[2]}')]";
        }
        
        // Attribute selector: [attr=value]
        if (preg_match('/^\[([\w-]+)=["\']?([^"\']+)["\']?\]$/', $selector, $matches)) {
            return "//*[@{$matches[1]}='{$matches[2]}']";
        }
        
        // Simple tag selector
        if (preg_match('/^[\w]+$/', $selector)) {
            return "//{$selector}";
        }

        // Default: try as XPath directly
        return $selector;
    }

    /**
     * Check and update rate limit
     */
    private function check_rate_limit() {
        $now = time();
        
        // Reset window if expired (1 minute)
        if ($now - $this->rate_limit['window_start'] > 60) {
            $this->rate_limit['requests'] = 0;
            $this->rate_limit['window_start'] = $now;
        }

        if ($this->rate_limit['requests'] >= $this->rate_limit['limit']) {
            $this->log('Rate limit exceeded', 'warning');
            return false;
        }

        $this->rate_limit['requests']++;
        return true;
    }

    /**
     * Get rate limit status
     */
    public function get_rate_limit_status() {
        return array(
            'remaining' => max(0, $this->rate_limit['limit'] - $this->rate_limit['requests']),
            'limit' => $this->rate_limit['limit'],
            'reset_at' => $this->rate_limit['window_start'] + 60,
        );
    }
}
