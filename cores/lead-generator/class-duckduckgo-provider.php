<?php

/**
 * DuckDuckGo Search Provider
 * 
 * Free search provider - no API key required.
 * Uses HTML scraping of DuckDuckGo search results.
 * 
 * @package RawWire_Dashboard
 * @since   1.0.32
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-search-provider.php';

class RawWire_DuckDuckGo_Provider implements RawWire_Search_Provider_Interface
{
    /** @var string Provider identifier */
    const PROVIDER_NAME = 'duckduckgo';

    /** @var int Cache TTL in seconds */
    const CACHE_TTL = 86400; // 24 hours

    /** @var int Request timeout */
    private int $timeout;

    /** @var int Delay between requests (microseconds) */
    private int $rate_limit_delay;

    /**
     * Constructor
     */
    public function __construct(int $timeout = 15, int $rate_limit_ms = 500)
    {
        $this->timeout = $timeout;
        $this->rate_limit_delay = $rate_limit_ms * 1000; // Convert to microseconds
    }

    /**
     * {@inheritdoc}
     */
    public function get_name(): string
    {
        return self::PROVIDER_NAME;
    }

    /**
     * {@inheritdoc}
     */
    public function is_available(): bool
    {
        // DuckDuckGo is always available (no API key)
        // But check if in cooldown due to recent failures
        return !RawWire_Provider_Status::is_in_cooldown(self::PROVIDER_NAME);
    }

    /**
     * {@inheritdoc}
     */
    public function search(string $query): array
    {
        // Check cache first
        $cache_key = 'rw_ddg_' . md5($query);
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        // Perform search
        $url = 'https://html.duckduckgo.com/html/?q=' . urlencode($query);

        $response = wp_remote_get($url, [
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept'     => 'text/html,application/xhtml+xml',
            ],
            'timeout' => $this->timeout,
        ]);

        if (is_wp_error($response)) {
            RawWire_Provider_Status::record_failure(self::PROVIDER_NAME, $response->get_error_message());
            return [];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            RawWire_Provider_Status::record_failure(self::PROVIDER_NAME, "HTTP {$code}");
            return [];
        }

        $html = wp_remote_retrieve_body($response);
        $results = $this->parse_html($html);

        // Cache results
        set_transient($cache_key, $results, self::CACHE_TTL);

        RawWire_Provider_Status::record_success(self::PROVIDER_NAME);
        return $results;
    }

    /**
     * {@inheritdoc}
     */
    public function research(array $party): array
    {
        $name = $party['name'] ?? '';
        $type = $party['type'] ?? 'company';
        $company = $party['company'] ?? '';
        $location = $party['location'] ?? $party['city'] ?? 'California';

        // Build search queries based on party type
        $queries = $this->build_research_queries($party);

        $all_results = [];
        $search_count = 0;

        foreach ($queries as $query) {
            $results = $this->search($query);

            if (!empty($results)) {
                $all_results[] = [
                    'query' => $query,
                    'results' => $results,
                    'cached' => false, // Cache check is internal
                    'provider' => self::PROVIDER_NAME,
                ];
                $search_count++;
            }

            // Rate limit between requests
            usleep($this->rate_limit_delay);
        }

        $formatted_content = $this->format_results_for_analysis($all_results);

        return [
            'success' => true,
            'content' => $formatted_content,
            'source' => self::PROVIDER_NAME,
            'results' => $all_results,
            'search_count' => $search_count,
        ];
    }

    /**
     * Build research queries for a party
     */
    private function build_research_queries(array $party): array
    {
        $name = $party['name'] ?? '';
        $company = $party['company'] ?? '';
        $type = $party['type'] ?? 'company';
        $location = $party['city'] ?? $party['location'] ?? 'Los Angeles';
        $state = $party['state'] ?? 'CA';
        $license = $party['license'] ?? '';

        $queries = [];

        // 1. Identity verification
        if ($type === 'contractor' && !empty($license)) {
            $queries[] = "\"{$name}\" contractor license {$license} California";
        } elseif ($company) {
            $queries[] = "\"{$company}\" {$location} {$state}";
        } else {
            $queries[] = "\"{$name}\" {$location} {$state} construction OR development";
        }

        // 2. LinkedIn
        $queries[] = "site:linkedin.com \"{$name}\" {$location}";

        // 3. Contact information
        $search_name = $company ?: $name;
        $queries[] = "\"{$search_name}\" contact email phone";

        // 4. Industry associations
        $queries[] = "\"{$search_name}\" AGC OR NAIOP OR BOMA OR ULI member";

        // 5. Recent news/projects
        $queries[] = "\"{$search_name}\" 2025 2026 project OR award OR announces";

        return array_slice($queries, 0, 5); // Limit to 5 queries
    }

    /**
     * Parse DuckDuckGo HTML results
     */
    private function parse_html(string $html): array
    {
        $results = [];

        // Pattern 1: Main result links
        if (preg_match_all('/<a[^>]+class="result__a"[^>]*href="([^"]+)"[^>]*>([^<]+)<\/a>/i', $html, $matches, PREG_SET_ORDER)) {
            foreach (array_slice($matches, 0, 10) as $match) {
                $url = $this->decode_redirect_url($match[1]);
                $title = html_entity_decode(strip_tags($match[2]), ENT_QUOTES, 'UTF-8');

                if ($url && $title && strlen($title) > 3) {
                    $results[] = [
                        'title' => $title,
                        'url' => $url,
                        'description' => '',
                    ];
                }
            }
        }

        // Pattern 2: Result snippets
        if (preg_match_all('/<a[^>]+class="result__snippet"[^>]*>(.+?)<\/a>/is', $html, $snippets)) {
            foreach ($snippets[1] as $i => $snippet) {
                if (isset($results[$i])) {
                    $results[$i]['description'] = html_entity_decode(strip_tags($snippet), ENT_QUOTES, 'UTF-8');
                }
            }
        }

        // Pattern 3: Alternative format (fallback)
        if (empty($results)) {
            if (preg_match_all('/<h2[^>]*>.*?<a[^>]+href="([^"]+)"[^>]*>(.+?)<\/a>/is', $html, $matches, PREG_SET_ORDER)) {
                foreach (array_slice($matches, 0, 10) as $match) {
                    $url = $this->decode_redirect_url($match[1]);
                    $title = html_entity_decode(strip_tags($match[2]), ENT_QUOTES, 'UTF-8');

                    if ($url && $title) {
                        $results[] = [
                            'title' => $title,
                            'url' => $url,
                            'description' => '',
                        ];
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Decode DuckDuckGo redirect URLs
     */
    private function decode_redirect_url(string $url): string
    {
        // DuckDuckGo wraps URLs in redirects
        if (strpos($url, '//duckduckgo.com/l/?') !== false || strpos($url, 'uddg=') !== false) {
            parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $params);
            if (!empty($params['uddg'])) {
                return urldecode($params['uddg']);
            }
        }

        // Direct URL
        if (strpos($url, 'http') === 0) {
            return $url;
        }

        return '';
    }

    /**
     * Format results for AI analysis
     */
    private function format_results_for_analysis(array $all_results): string
    {
        $text = '';

        foreach ($all_results as $query_result) {
            $text .= "### Search: {$query_result['query']}\n";

            foreach ($query_result['results'] as $r) {
                $text .= "- **{$r['title']}**\n";
                $text .= "  {$r['url']}\n";
                if (!empty($r['description'])) {
                    $text .= "  {$r['description']}\n";
                }
            }
            $text .= "\n";
        }

        return $text;
    }
}
