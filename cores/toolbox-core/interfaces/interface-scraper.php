<?php
/**
 * Scraper Adapter Interface
 * Interface for all data collection/scraping adapters.
 * 
 * @package RawWire_Dashboard
 * @subpackage Toolbox_Core
 */
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/interface-adapter.php';

interface RawWire_Scraper_Interface extends RawWire_Adapter_Interface {
    /**
     * Scrape content from a URL
     * 
     * @param string $url The URL to scrape
     * @param array $options Optional scraping options (selectors, wait time, etc.)
     * @return array{success: bool, html?: string, data?: array, error?: string}
     */
    public function scrape(string $url, array $options = array());

    /**
     * Scrape multiple URLs in batch
     * 
     * @param array $urls Array of URLs to scrape
     * @param array $options Scraping options
     * @return array Array of results keyed by URL
     */
    public function scrape_batch(array $urls, array $options = array());

    /**
     * Extract structured data from HTML using selectors
     * 
     * @param string $html The HTML content
     * @param array $selectors CSS selectors mapped to field names
     * @return array Extracted data
     */
    public function extract(string $html, array $selectors);

    /**
     * Get rate limit status
     * 
     * @return array{remaining: int, limit: int, reset_at: int}
     */
    public function get_rate_limit_status();
}
