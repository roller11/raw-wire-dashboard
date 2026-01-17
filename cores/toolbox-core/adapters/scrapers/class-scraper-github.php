<?php
/**
 * GitHub API Scraper Adapter (Free Tier)
 * Uses GitHub REST API v3 for data collection.
 * Free tier: 60 req/hour (unauthenticated), 5000 req/hour (authenticated)
 * 
 * @package RawWire_Dashboard
 * @subpackage Toolbox_Core
 */
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../class-adapter-base.php';
require_once dirname(dirname(__DIR__)) . '/interfaces/interface-scraper.php';

class RawWire_Adapter_Scraper_GitHub extends RawWire_Adapter_Base implements RawWire_Scraper_Interface {
    
    protected $name = 'GitHub API Scraper';
    protected $version = '1.0.0';
    protected $tier = 'free';
    protected $capabilities = array('api_access', 'rate_limited', 'authenticated');
    protected $required_fields = array(); // token is optional (better with it)

    /**
     * GitHub API base URL
     * @var string
     */
    private $api_base = 'https://api.github.com';

    /**
     * Rate limit cache
     * @var array|null
     */
    private $rate_limit_cache = null;

    /**
     * Test connection to GitHub API
     */
    public function test_connection() {
        try {
            $response = $this->make_github_request('/rate_limit');
            
            if (!$response['success']) {
                return array(
                    'success' => false,
                    'message' => 'GitHub API connection failed: ' . ($response['error'] ?? 'Unknown error'),
                );
            }

            $rate = $response['data']['rate'] ?? array();
            
            return array(
                'success' => true,
                'message' => 'GitHub API connection successful',
                'details' => array(
                    'authenticated' => !empty($this->config['token']),
                    'rate_limit' => $rate['limit'] ?? 60,
                    'remaining' => $rate['remaining'] ?? 0,
                    'reset_at' => isset($rate['reset']) ? date('Y-m-d H:i:s', $rate['reset']) : 'N/A',
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
     * Scrape content from GitHub (repos, issues, commits, etc.)
     * 
     * @param string $url Full GitHub URL or API endpoint
     * @param array $options {
     *     @type string $type 'repo'|'issues'|'commits'|'contents'|'api' (default: detect from URL)
     *     @type array  $params Query parameters
     *     @type bool   $raw_html Return raw HTML for README files
     * }
     */
    public function scrape(string $url, array $options = array()) {
        $this->log('Scraping GitHub resource', 'info', array('url' => $url, 'options' => $options));

        // Parse URL to determine what we're scraping
        $parsed = $this->parse_github_url($url);
        if (!$parsed['success']) {
            return array('success' => false, 'error' => $parsed['error']);
        }

        $type = $options['type'] ?? $parsed['type'];
        $endpoint = $this->build_endpoint($parsed, $type);

        // Add query parameters - use & if endpoint already has params, else ?
        $params = $options['params'] ?? array();
        if (!empty($params)) {
            $separator = (strpos($endpoint, '?') !== false) ? '&' : '?';
            $endpoint .= $separator . http_build_query($params);
        }

        $response = $this->make_github_request($endpoint);

        if (!$response['success']) {
            return $response;
        }

        // Transform data based on type
        return array(
            'success' => true,
            'data' => $response['data'],
            'meta' => array(
                'type' => $type,
                'endpoint' => $endpoint,
                'rate_limit' => $this->get_rate_limit_status(),
            ),
        );
    }

    /**
     * Scrape multiple GitHub resources in batch
     */
    public function scrape_batch(array $urls, array $options = array()) {
        $results = array();
        $delay = $options['delay'] ?? 1; // seconds between requests

        foreach ($urls as $url) {
            $results[$url] = $this->scrape($url, $options);
            
            // Rate limit protection
            if (count($urls) > 1) {
                sleep($delay);
            }
        }

        return $results;
    }

    /**
     * Extract structured data from HTML (not applicable for JSON API)
     * For GitHub, this would be used if scraping HTML pages
     */
    public function extract(string $html, array $selectors) {
        $this->log('Extract called on GitHub adapter (expects JSON API responses)', 'warning');
        
        // Basic implementation for HTML parsing if needed
        $data = array();
        
        if (empty($html)) {
            return $data;
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        foreach ($selectors as $key => $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes && $nodes->length > 0) {
                $data[$key] = $nodes->item(0)->nodeValue;
            }
        }

        return $data;
    }

    /**
     * Get current rate limit status from GitHub
     */
    public function get_rate_limit_status() {
        if ($this->rate_limit_cache !== null) {
            return $this->rate_limit_cache;
        }

        $response = $this->make_github_request('/rate_limit');
        
        if (!$response['success']) {
            return array(
                'remaining' => 0,
                'limit' => 60,
                'reset_at' => time() + 3600,
            );
        }

        $rate = $response['data']['rate'] ?? array();
        
        $this->rate_limit_cache = array(
            'remaining' => $rate['remaining'] ?? 0,
            'limit' => $rate['limit'] ?? 60,
            'reset_at' => $rate['reset'] ?? time() + 3600,
        );

        return $this->rate_limit_cache;
    }

    /**
     * Parse GitHub URL to extract owner, repo, and resource type
     * 
     * @param string $url GitHub URL or API endpoint
     * @return array{success: bool, owner?: string, repo?: string, type?: string, path?: string, error?: string}
     */
    private function parse_github_url(string $url) {
        // Already an API endpoint
        if (strpos($url, 'api.github.com') !== false) {
            return array(
                'success' => true,
                'type' => 'api',
                'endpoint' => str_replace('https://api.github.com', '', $url),
            );
        }

        // Parse github.com URL
        $pattern = '#github\.com/([^/]+)/([^/]+)(?:/([^/?]+))?(?:/(.*))?#i';
        if (!preg_match($pattern, $url, $matches)) {
            return array(
                'success' => false,
                'error' => 'Invalid GitHub URL format',
            );
        }

        $result = array(
            'success' => true,
            'owner' => $matches[1],
            'repo' => rtrim($matches[2], '.git'),
        );

        // Determine resource type
        if (isset($matches[3])) {
            $resource = $matches[3];
            $result['path'] = $matches[4] ?? '';
            
            switch ($resource) {
                case 'issues':
                    $result['type'] = 'issues';
                    break;
                case 'pull':
                    $result['type'] = 'pulls';
                    break;
                case 'commit':
                case 'commits':
                    $result['type'] = 'commits';
                    break;
                case 'tree':
                case 'blob':
                    $result['type'] = 'contents';
                    break;
                default:
                    $result['type'] = 'repo';
            }
        } else {
            $result['type'] = 'repo';
        }

        return $result;
    }

    /**
     * Build API endpoint from parsed URL data
     */
    private function build_endpoint(array $parsed, string $type) {
        if ($type === 'api' && isset($parsed['endpoint'])) {
            return $parsed['endpoint'];
        }

        $owner = $parsed['owner'];
        $repo = $parsed['repo'];
        $path = $parsed['path'] ?? '';

        switch ($type) {
            case 'repo':
                return "/repos/{$owner}/{$repo}";
            
            case 'issues':
                if ($path) {
                    return "/repos/{$owner}/{$repo}/issues/{$path}";
                }
                return "/repos/{$owner}/{$repo}/issues";
            
            case 'pulls':
                if ($path) {
                    return "/repos/{$owner}/{$repo}/pulls/{$path}";
                }
                return "/repos/{$owner}/{$repo}/pulls";
            
            case 'commits':
                if ($path) {
                    return "/repos/{$owner}/{$repo}/commits/{$path}";
                }
                return "/repos/{$owner}/{$repo}/commits";
            
            case 'contents':
                return "/repos/{$owner}/{$repo}/contents/{$path}";
            
            default:
                return "/repos/{$owner}/{$repo}";
        }
    }

    /**
     * Make authenticated request to GitHub API
     * 
     * @param string $endpoint API endpoint (without base URL)
     * @param array $args Additional wp_remote_request arguments
     * @return array{success: bool, data?: array, error?: string}
     */
    private function make_github_request(string $endpoint, array $args = array()) {
        $url = $this->api_base . $endpoint;
        
        $headers = array(
            'Accept' => 'application/vnd.github.v3+json',
            'User-Agent' => 'RawWire-Dashboard/1.0',
        );

        // Add authentication if token is configured
        if (!empty($this->config['token'])) {
            $headers['Authorization'] = 'token ' . $this->config['token'];
        }

        $default_args = array(
            'headers' => $headers,
            'timeout' => 30,
        );

        $args = array_merge($default_args, $args);

        $this->log('Making GitHub API request', 'info', array(
            'endpoint' => $endpoint,
            'authenticated' => !empty($this->config['token']),
        ));

        // Use wp_remote_get instead of http_request for better control
        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            $error_msg = $response->get_error_message();
            $this->log('GitHub API request failed', 'error', array('error' => $error_msg));
            return array(
                'success' => false,
                'error' => $error_msg,
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            $this->log('GitHub API returned non-200 status', 'error', array(
                'code' => $code,
                'body' => substr($body, 0, 500),
            ));
            return array(
                'success' => false,
                'error' => "GitHub API returned status {$code}",
                'body' => $body,
                'code' => $code,
            );
        }

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return array(
                'success' => false,
                'error' => 'Failed to parse JSON response: ' . json_last_error_msg(),
            );
        }

        return array(
            'success' => true,
            'data' => $data,
        );
    }

    /**
     * Convenience method: Get repository information
     */
    public function get_repository(string $owner, string $repo) {
        return $this->scrape("https://github.com/{$owner}/{$repo}");
    }

    /**
     * Convenience method: Get repository issues
     */
    public function get_issues(string $owner, string $repo, array $params = array()) {
        $url = "https://github.com/{$owner}/{$repo}/issues";
        return $this->scrape($url, array('params' => $params));
    }

    /**
     * Convenience method: Get repository commits
     */
    public function get_commits(string $owner, string $repo, array $params = array()) {
        $url = "https://github.com/{$owner}/{$repo}/commits";
        return $this->scrape($url, array('params' => $params));
    }

    /**
     * Convenience method: Get file contents
     */
    public function get_contents(string $owner, string $repo, string $path) {
        $endpoint = "/repos/{$owner}/{$repo}/contents/{$path}";
        return $this->make_github_request($endpoint);
    }
}
