<?php
/**
 * Scraper Service
 * 
 * Handles all web scraping operations with clear logging and error handling.
 * This is the central service for fetching content from government sources.
 * 
 * @package RawWire_Dashboard
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class RawWire_Scraper_Service {
    
    /**
     * Available data sources
     */
    private $sources = array();
    
    /**
     * Logger instance
     */
    private $logger;
    
    /**
     * Results from last scrape
     */
    private $last_results = array();
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->init_sources();
        $this->logger = class_exists('RawWire_Logger') ? 'RawWire_Logger' : null;
    }
    
    /**
     * Items per source limit
     */
    private $items_per_source = 10;

    /**
     * Initialize available data sources
     * Each source includes copyright info for proper attribution
     */
    private function init_sources() {
        $this->sources = array(
            'federal_register_rules' => array(
                'name' => 'Federal Register - Rules',
                'url' => 'https://www.federalregister.gov/documents/search?conditions%5Btype%5D%5B%5D=RULE',
                'api_url' => 'https://www.federalregister.gov/api/v1/documents.json?conditions[type][]=RULE&per_page=20',
                'enabled' => true,
                'copyright_status' => 'public_domain',
                'attribution' => 'U.S. Government Publishing Office / Federal Register',
            ),
            'federal_register_notices' => array(
                'name' => 'Federal Register - Notices', 
                'url' => 'https://www.federalregister.gov/documents/search?conditions%5Btype%5D%5B%5D=NOTICE',
                'api_url' => 'https://www.federalregister.gov/api/v1/documents.json?conditions[type][]=NOTICE&per_page=20',
                'enabled' => true,
                'copyright_status' => 'public_domain',
                'attribution' => 'U.S. Government Publishing Office / Federal Register',
            ),
            'whitehouse_briefings' => array(
                'name' => 'White House Press Briefings',
                'url' => 'https://www.whitehouse.gov/briefing-room/press-briefings/',
                'rss_url' => 'https://www.whitehouse.gov/briefing-room/press-briefings/feed/',
                'enabled' => true,
                'copyright_status' => 'public_domain',
                'attribution' => 'The White House',
            ),
            'whitehouse_statements' => array(
                'name' => 'White House Statements',
                'url' => 'https://www.whitehouse.gov/briefing-room/statements-releases/',
                'rss_url' => 'https://www.whitehouse.gov/briefing-room/statements-releases/feed/',
                'enabled' => true,
                'copyright_status' => 'public_domain',
                'attribution' => 'The White House',
            ),
            'fda_news' => array(
                'name' => 'FDA News & Events',
                'url' => 'https://www.fda.gov/news-events/newsroom/press-announcements',
                'enabled' => true,
                'copyright_status' => 'public_domain',
                'attribution' => 'U.S. Food and Drug Administration',
            ),
            'epa_releases' => array(
                'name' => 'EPA News Releases',
                'url' => 'https://www.epa.gov/newsreleases',
                'enabled' => true,
                'copyright_status' => 'public_domain',
                'attribution' => 'U.S. Environmental Protection Agency',
            ),
            'doj_releases' => array(
                'name' => 'DOJ Press Releases',
                'url' => 'https://www.justice.gov/news',
                'enabled' => true,
                'copyright_status' => 'public_domain',
                'attribution' => 'U.S. Department of Justice',
            ),
            'sec_releases' => array(
                'name' => 'SEC Press Releases',
                'url' => 'https://www.sec.gov/news/pressreleases',
                'enabled' => true,
                'copyright_status' => 'public_domain',
                'attribution' => 'U.S. Securities and Exchange Commission',
            ),
        );
    }
    
    /**
     * Log message
     */
    private function log($message, $level = 'info', $context = array()) {
        if ($this->logger && method_exists($this->logger, $level)) {
            call_user_func(array($this->logger, $level), "[ScraperService] " . $message, $context);
        }
        error_log("[RawWire ScraperService] [{$level}] {$message}");
    }
    
    /**
     * Get available sources
     */
    public function get_sources() {
        return $this->sources;
    }
    
    /**
     * Scrape all enabled sources
     * 
     * @param array $config Configuration options
     * @return array Results with success status and items
     */
    public function scrape_all($config = array()) {
        $this->log('Starting scrape_all', 'info', array('config' => $config));
        
        // Set workflow status
        set_transient('rawwire_workflow_status', array(
            'active' => true,
            'stage' => 'scraping',
            'message' => 'Scraping government sources...',
            'progress' => 15,
            'startTime' => current_time('mysql')
        ), 300); // 5 minutes
        
        $results = array(
            'success' => false,
            'total_scraped' => 0,
            'items_by_source' => array(),
            'errors' => array(),
            'started_at' => current_time('mysql'),
        );
        
        // Determine which sources to scrape
        $sources_to_scrape = $this->get_active_sources($config);
        
        if (empty($sources_to_scrape)) {
            $results['errors'][] = 'No sources enabled for scraping';
            $this->log('No sources enabled', 'warning');
            return $results;
        }
        
        $this->log('Scraping ' . count($sources_to_scrape) . ' sources', 'info');
        
        foreach ($sources_to_scrape as $key => $source) {
            $source_result = $this->scrape_source($key, $source);
            
            if ($source_result['success']) {
                $results['items_by_source'][$source['name']] = $source_result['items'];
                $results['total_scraped'] += count($source_result['items']);
                $this->log("Scraped {$source['name']}: " . count($source_result['items']) . " items", 'info');
                
                // Store items to candidates table
                $stored_count = $this->store_to_candidates($source_result['items'], $source['name']);
                $this->log("Stored {$stored_count} items to candidates table from {$source['name']}", 'info');
            } else {
                $results['errors'][] = $source_result['error'];
                $this->log("Failed {$source['name']}: " . $source_result['error'], 'error');
            }
        }
        
        $results['success'] = ($results['total_scraped'] > 0);
        $results['completed_at'] = current_time('mysql');
        
        $this->last_results = $results;
        $this->log("Scrape complete. Total: {$results['total_scraped']} items", 'info');
        
        // Fire hook for AI scoring
        if ($results['success']) {
            do_action('rawwire_scrape_complete', $results);
            $this->log("Fired rawwire_scrape_complete hook", 'info');
        }
        
        return $results;
    }
    
    /**
     * Store scraped items to candidates table
     * 
     * @param array $items Array of items to store
     * @param string $source_name Source identifier
     * @return int Number of items stored
     */
    private function store_to_candidates($items, $source_name) {
        global $wpdb;
        
        $candidates_table = $wpdb->prefix . 'rawwire_candidates';
        $archives_table = $wpdb->prefix . 'rawwire_archives';
        $stored = 0;
        
        foreach ($items as $item) {
            // Check for duplicates in candidates table
            $exists_in_candidates = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$candidates_table} WHERE title = %s AND source = %s",
                $item['title'],
                $source_name
            ));
            
            // Check for duplicates in archives table (don't re-scrape already processed items)
            $exists_in_archives = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$archives_table} WHERE title = %s AND source = %s",
                $item['title'],
                $source_name
            ));
            
            if ($exists_in_candidates || $exists_in_archives) {
                continue; // Skip duplicates
            }
            
            // Insert into candidates table
            $result = $wpdb->insert(
                $candidates_table,
                array(
                    'title' => sanitize_text_field($item['title']),
                    'content' => wp_kses_post($item['content'] ?? ''),
                    'link' => esc_url_raw($item['link'] ?? ''),
                    'source' => $source_name,
                    'copyright_status' => $item['copyright_status'] ?? 'unknown',
                    'copyright_info' => $item['copyright_info'] ?? '',
                    'attribution' => $item['attribution'] ?? '',
                    'publication_date' => $item['publication_date'] ?? '',
                    'document_number' => $item['document_number'] ?? '',
                    'created_at' => current_time('mysql')
                ),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
            );
            
            if ($result) {
                $stored++;
            }
        }
        
        return $stored;
    }
    
    /**
     * Get active sources based on config
     */
    private function get_active_sources($config) {
        $active = array();
        
        // If config specifies sources, use those
        if (!empty($config['sources'])) {
            foreach ($config['sources'] as $key => $enabled) {
                if ($enabled && isset($this->sources[$key])) {
                    $active[$key] = $this->sources[$key];
                }
            }
        }
        
        // If no config or no sources specified, use all enabled
        if (empty($active)) {
            foreach ($this->sources as $key => $source) {
                if (!empty($source['enabled'])) {
                    $active[$key] = $source;
                }
            }
        }
        
        return $active;
    }
    
    /**
     * Scrape a single source
     * Returns exactly $items_per_source items with full metadata
     */
    public function scrape_source($key, $source) {
        $result = array(
            'success' => false,
            'items' => array(),
            'error' => null,
        );
        
        $this->log("Scraping source: {$source['name']}", 'info', array('url' => $source['url']));
        
        try {
            // Try RSS feed first if available (more reliable)
            if (!empty($source['rss_url'])) {
                $rss_resp = wp_remote_get($source['rss_url'], array('timeout' => 20));
                if (!is_wp_error($rss_resp) && wp_remote_retrieve_response_code($rss_resp) === 200) {
                    $items = $this->parse_rss_feed(wp_remote_retrieve_body($rss_resp), $source);
                    if (!empty($items)) {
                        $result['success'] = true;
                        $result['items'] = array_slice($items, 0, $this->items_per_source);
                        return $result;
                    }
                }
            }

            // Try API if available (Federal Register)
            if (!empty($source['api_url'])) {
                $api_resp = wp_remote_get($source['api_url'], array('timeout' => 20));
                if (!is_wp_error($api_resp) && wp_remote_retrieve_response_code($api_resp) === 200) {
                    $items = $this->parse_federal_register(wp_remote_retrieve_body($api_resp), $source);
                    if (!empty($items)) {
                        $result['success'] = true;
                        $result['items'] = array_slice($items, 0, $this->items_per_source);
                        return $result;
                    }
                }
            }

            // Fall back to HTML scraping
            $response = wp_remote_get($source['url'], array(
                'timeout' => 30,
                'headers' => array(
                    'User-Agent' => 'RawWire-Bot/1.0 (WordPress; Government Data Aggregator)',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.5',
                ),
            ));
            
            if (is_wp_error($response)) {
                $result['error'] = "HTTP Error for {$source['name']}: " . $response->get_error_message();
                $this->log($result['error'], 'error');
                return $result;
            }
            
            $status_code = wp_remote_retrieve_response_code($response);
            $this->log("HTTP {$status_code} from {$source['name']}", 'debug');

            if ($status_code !== 200) {
                $result['error'] = "HTTP {$status_code} from {$source['name']}";
                return $result;
            }

            $body = wp_remote_retrieve_body($response);

            if (empty($body)) {
                $result['error'] = "Empty response from {$source['name']}";
                return $result;
            }

            $this->log("Received " . strlen($body) . " bytes from {$source['name']}", 'debug');

            // Parse the HTML
            $items = $this->parse_html($body, $source['url'], $source);
            
            if (empty($items)) {
                $result['error'] = "No items parsed from {$source['name']}";
                $result['success'] = false;
                return $result;
            }
            
            $result['success'] = true;
            $result['items'] = array_slice($items, 0, $this->items_per_source);
            
        } catch (Exception $e) {
            $result['error'] = "Exception scraping {$source['name']}: " . $e->getMessage();
            $this->log($result['error'], 'error');
        }
        
        return $result;
    }
    
    /**
     * Parse HTML to extract items with full metadata
     * @param string $html Raw HTML content
     * @param string $base_url Base URL for relative links
     * @param array $source Source config with copyright/attribution
     */
    private function parse_html($html, $base_url, $source) {
        $items = array();
        $source_name = is_array($source) ? $source['name'] : $source;
        $copyright_status = is_array($source) ? ($source['copyright_status'] ?? 'unknown') : 'unknown';
        $attribution = is_array($source) ? ($source['attribution'] ?? $source_name) : $source_name;
        
        if (empty($html)) {
            return $items;
        }
        
        // Use DOMDocument if available
        if (class_exists('DOMDocument') && class_exists('DOMXPath')) {
            libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            $xpath = new DOMXPath($dom);
            
            // XPath queries to find article links
            $queries = array(
                '//h2//a[@href]',
                '//h3//a[@href]',
                "//*[contains(@class, 'entry-title')]//a[@href]",
                "//*[contains(@class, 'document-title')]//a[@href]",
                "//*[contains(@class, 'views-field-title')]//a[@href]",
                "//a[contains(@href,'federalregister.gov/documents')]",
                "//article//a[@href]",
            );
            
            $seen = array();
            
            foreach ($queries as $query) {
                $nodes = $xpath->query($query);
                
                if (!$nodes || $nodes->length === 0) {
                    continue;
                }
                
                foreach ($nodes as $node) {
                    $href = trim($node->getAttribute('href'));
                    $title = trim($node->textContent);
                    
                    // Skip short titles (likely navigation)
                    if (strlen($title) < 20) {
                        continue;
                    }
                    
                    // Skip javascript links
                    if (stripos($href, 'javascript:') === 0) {
                        continue;
                    }
                    
                    // Make URL absolute
                    $url = $this->make_absolute_url($href, $base_url);
                    
                    // Dedupe by URL + title
                    $key = md5($url . '|' . strtolower($title));
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    
                    // Find summary/description nearby (target 50-200 words)
                    $summary = $this->find_summary_text($node);
                    $summary = $this->normalize_summary($summary);
                    
                    // Skip items without sufficient summary (< 50 words)
                    if (empty($summary)) {
                        continue;
                    }
                    
                    $items[] = array(
                        'title' => $this->clean_text($title),
                        'content' => $summary,
                        'link' => esc_url_raw($url),
                        'source' => $source_name,
                        'copyright_status' => $copyright_status,
                        'is_subject_to_copyright' => ($copyright_status !== 'public_domain'),
                        'attribution' => $attribution,
                        'license' => ($copyright_status === 'public_domain' ? 'U.S. Government Work - Public Domain' : ''),
                    );
                    
                    // Limit items per source
                    if (count($items) >= $this->items_per_source) {
                        break 2;
                    }
                }
            }
            
            libxml_clear_errors();
            
        } else {
            // Fallback: regex parsing
            $this->log('DOMDocument not available, using regex fallback', 'warning');
            $items = $this->parse_html_regex($html, $base_url, $source);
        }
        
        return $items;
    }
    
    /**
     * Regex fallback for HTML parsing
     */
    private function parse_html_regex($html, $base_url, $source) {
        $items = array();
        $source_name = is_array($source) ? $source['name'] : $source;
        $copyright_status = is_array($source) ? ($source['copyright_status'] ?? 'unknown') : 'unknown';
        $attribution = is_array($source) ? ($source['attribution'] ?? $source_name) : $source_name;
        
        if (preg_match_all('/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER)) {
            $seen = array();
            
            foreach ($matches as $match) {
                $href = html_entity_decode(trim($match[1]));
                $title = trim(strip_tags($match[2]));
                
                if (strlen($title) < 20) {
                    continue;
                }
                
                if (stripos($href, 'javascript:') === 0) {
                    continue;
                }
                
                $url = $this->make_absolute_url($href, $base_url);
                $key = md5($url . '|' . strtolower($title));
                
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                
                $items[] = array(
                    'title' => $this->clean_text($title),
                    'content' => '',
                    'link' => esc_url_raw($url),
                    'source' => $source_name,
                    'copyright_status' => $copyright_status,
                    'is_subject_to_copyright' => ($copyright_status !== 'public_domain'),
                    'attribution' => $attribution,
                    'license' => ($copyright_status === 'public_domain' ? 'U.S. Government Work - Public Domain' : ''),
                );
                
                if (count($items) >= $this->items_per_source) {
                    break;
                }
            }
        }
        
        return $items;
    }

    /**
     * Parse Federal Register API JSON with full metadata
     */
    private function parse_federal_register($body, $source) {
        $items = array();
        $source_name = is_array($source) ? $source['name'] : $source;
        $copyright_status = is_array($source) ? ($source['copyright_status'] ?? 'public_domain') : 'public_domain';
        $attribution = is_array($source) ? ($source['attribution'] ?? 'Federal Register') : 'Federal Register';
        
        $data = json_decode($body, true);
        if (!$data || empty($data['results'])) {
            return $items;
        }
        foreach ($data['results'] as $doc) {
            $abstract = $this->clean_text($doc['abstract'] ?? '');
            $abstract = $this->normalize_summary($abstract);
            
            // Skip items without sufficient summary
            if (empty($abstract)) {
                continue;
            }
            
            $items[] = array(
                'title' => $this->clean_text($doc['title'] ?? ''),
                'content' => $abstract,
                'link' => esc_url_raw($doc['html_url'] ?? ''),
                'source' => $source_name,
                'copyright_status' => $copyright_status,
                'is_subject_to_copyright' => false,
                'attribution' => $attribution,
                'license' => 'U.S. Government Work - Public Domain',
                'publication_date' => $doc['publication_date'] ?? '',
                'document_number' => $doc['document_number'] ?? '',
            );
            if (count($items) >= $this->items_per_source) break;
        }
        return $items;
    }

    /**
     * Parse simple RSS/Atom feed with full metadata
     */
    private function parse_rss_feed($body, $source) {
        $items = array();
        $source_name = is_array($source) ? $source['name'] : $source;
        $copyright_status = is_array($source) ? ($source['copyright_status'] ?? 'public_domain') : 'public_domain';
        $attribution = is_array($source) ? ($source['attribution'] ?? $source_name) : $source_name;
        
        $xml = @simplexml_load_string($body);
        if (!$xml) return $items;
        $nodes = $xml->channel->item ?? $xml->entry ?? array();
        foreach ($nodes as $node) {
            $title = isset($node->title) ? (string)$node->title : '';
            $link = isset($node->link) ? (string)($node->link['href'] ?? $node->link) : '';
            $desc = isset($node->description) ? (string)$node->description : (isset($node->summary) ? (string)$node->summary : '');
            $pubDate = isset($node->pubDate) ? (string)$node->pubDate : '';
            
            if (strlen($title) < 10) continue;
            
            $desc = $this->clean_text(strip_tags($desc));
            $desc = $this->normalize_summary($desc);
            
            // Skip items without sufficient summary
            if (empty($desc)) {
                continue;
            }
            
            $items[] = array(
                'title' => $this->clean_text($title),
                'content' => $desc,
                'link' => esc_url_raw($link),
                'source' => $source_name,
                'copyright_status' => $copyright_status,
                'is_subject_to_copyright' => ($copyright_status !== 'public_domain'),
                'attribution' => $attribution,
                'license' => ($copyright_status === 'public_domain' ? 'U.S. Government Work - Public Domain' : ''),
                'publication_date' => $pubDate,
            );
            if (count($items) >= $this->items_per_source) break;
        }
        return $items;
    }

    /**
     * Normalize summary to 50-200 words
     * Enforces minimum 50 words, truncates if over 200
     * Returns empty string if cannot reach minimum word count
     */
    private function normalize_summary($text) {
        if (empty($text)) {
            return '';
        }
        
        // Clean and normalize whitespace
        $text = preg_replace('/\s+/', ' ', trim($text));
        
        // Count words
        $words = preg_split('/\s+/', $text);
        $word_count = count($words);
        
        // Must have at least 50 words for quality summary
        if ($word_count < 50) {
            // Return empty to indicate insufficient content
            return '';
        }
        
        // If over 200 words, truncate to ~200
        if ($word_count > 200) {
            $words = array_slice($words, 0, 200);
            $text = implode(' ', $words) . '...';
        }
        
        return $text;
    }
    
    /**
     * Find summary text near an anchor element
     */
    private function find_summary_text($anchor_node) {
        $summary = '';
        
        // Check parent and siblings for paragraph text
        $parent = $anchor_node->parentNode;
        
        if (!$parent) {
            return $summary;
        }
        
        // Look for sibling paragraphs
        $sibling = $parent->nextSibling;
        for ($i = 0; $i < 5 && $sibling; $i++) {
            if ($sibling->nodeName === 'p' || $sibling->nodeName === 'div') {
                $text = trim($sibling->textContent);
                if (strlen($text) > 50) {
                    $summary = substr($text, 0, 500);
                    break;
                }
            }
            $sibling = $sibling->nextSibling;
        }
        
        // Also check parent's parent
        if (empty($summary) && $parent->parentNode) {
            $grandparent = $parent->parentNode;
            foreach ($grandparent->childNodes as $child) {
                if ($child->nodeName === 'p') {
                    $text = trim($child->textContent);
                    if (strlen($text) > 50) {
                        $summary = substr($text, 0, 500);
                        break;
                    }
                }
            }
        }
        
        return $summary;
    }
    
    /**
     * Make a URL absolute
     */
    private function make_absolute_url($href, $base_url) {
        // Already absolute
        if (parse_url($href, PHP_URL_SCHEME)) {
            return $href;
        }
        
        // Protocol-relative
        if (strpos($href, '//') === 0) {
            $scheme = parse_url($base_url, PHP_URL_SCHEME) ?: 'https';
            return $scheme . ':' . $href;
        }
        
        $parts = parse_url($base_url);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        
        // Root-relative
        if (strpos($href, '/') === 0) {
            return $scheme . '://' . $host . $href;
        }
        
        // Path-relative
        $path = isset($parts['path']) ? rtrim(dirname($parts['path']), '/') : '';
        return $scheme . '://' . $host . $path . '/' . $href;
    }
    
    /**
     * Clean text content
     */
    private function clean_text($text) {
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }
    
    /**
     * Test scraping a single source (for debugging)
     */
    public function test_source($source_key) {
        if (!isset($this->sources[$source_key])) {
            return array(
                'success' => false,
                'error' => "Unknown source: {$source_key}",
            );
        }
        
        return $this->scrape_source($source_key, $this->sources[$source_key]);
    }
    
    /**
     * Get last results
     */
    public function get_last_results() {
        return $this->last_results;
    }
}
