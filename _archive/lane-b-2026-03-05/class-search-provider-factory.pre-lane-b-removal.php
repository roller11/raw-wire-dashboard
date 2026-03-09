<?php

/**
 * Search Provider Factory
 * 
 * Creates and manages search providers for Lane A only (OpenClaw agent path).
 * 
 * @package RawWire_Dashboard
 * @since   1.0.32
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-search-provider.php';
require_once __DIR__ . '/class-duckduckgo-provider.php';
require_once __DIR__ . '/class-openclaw-adapter.php';

class RawWire_Search_Provider_Factory
{
    /** @var string[] Provider fallback order */
    const FALLBACK_CHAIN = ['openclaw'];

    /** @var RawWire_Search_Provider_Interface[] Cached provider instances */
    private static array $providers = [];

    /**
     * Get the configured primary provider
     */
    public static function get_primary(): RawWire_Search_Provider_Interface
    {
        return self::get_provider('openclaw');
    }

    /**
     * Get a specific provider by name
     */
    public static function get_provider(string $name): RawWire_Search_Provider_Interface
    {
        if (!isset(self::$providers[$name])) {
            self::$providers[$name] = self::create_provider($name);
        }

        return self::$providers[$name];
    }

    /**
     * Create a provider instance
     */
    private static function create_provider(string $name): RawWire_Search_Provider_Interface
    {
        switch ($name) {
            case 'openclaw':
                return new RawWire_OpenClaw_Adapter();

            case 'brave':
                // Brave provider (requires API key)
                return new RawWire_Brave_Provider();

            case 'duckduckgo':
            default:
                return new RawWire_DuckDuckGo_Provider();
        }
    }

    /**
     * Get the first available provider from fallback chain
     */
    public static function get_available(): RawWire_Search_Provider_Interface
    {
        try {
            $provider = self::get_provider('openclaw');
            if ($provider->is_available()) {
                return $provider;
            }
        } catch (\Exception $e) {
            rawwire_log('search_provider', 'OpenClaw unavailable: ' . $e->getMessage(), 'warning');
        }

        // Return OpenClaw instance even when unavailable so callers fail explicitly.
        return self::get_provider('openclaw');
    }

    /**
     * Search with automatic fallback
     * 
     * Tries the primary provider, then falls back through the chain.
     */
    public static function search(string $query): array
    {
        try {
            $provider = self::get_provider('openclaw');
            if (!$provider->is_available()) {
                return [];
            }

            $results = $provider->search($query);
            return is_array($results) ? $results : [];
        } catch (\Exception $e) {
            rawwire_log('search_provider', 'Search failed (openclaw): ' . $e->getMessage(), 'warning');
        }

        return [];
    }

    /**
     * Research with automatic fallback
     * 
     * Tries the primary provider, then falls back through the chain.
     */
    public static function research(array $party): array
    {
        try {
            $provider = self::get_provider('openclaw');

            if (!$provider->is_available()) {
                return [
                    'success' => false,
                    'content' => '',
                    'source' => 'openclaw',
                    'results' => [],
                    'error' => 'OpenClaw unavailable',
                ];
            }

            $result = $provider->research($party);
            if (!empty($result['success'])) {
                return $result;
            }
        } catch (\Exception $e) {
            rawwire_log('search_provider', 'Research failed (openclaw): ' . $e->getMessage(), 'warning');
        }

        return [
            'success' => false,
            'content' => '',
            'source' => 'none',
            'results' => [],
            'error' => 'All search providers failed',
        ];
    }

    /**
     * Get provider status for all providers
     */
    public static function get_status(): array
    {
        $status = [];

        foreach (self::FALLBACK_CHAIN as $name) {
            try {
                $provider = self::get_provider($name);
                $provider_status = RawWire_Provider_Status::get_status($name);

                $status[$name] = [
                    'name' => $name,
                    'available' => $provider->is_available(),
                    'in_cooldown' => RawWire_Provider_Status::is_in_cooldown($name),
                    'failure_count' => $provider_status['failure_count'] ?? 0,
                    'last_success' => $provider_status['last_success'] ?? null,
                    'last_failure' => $provider_status['last_failure'] ?? null,
                ];
            } catch (\Exception $e) {
                $status[$name] = [
                    'name' => $name,
                    'available' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $status;
    }

    /**
     * Reset all provider statuses
     */
    public static function reset_all(): void
    {
        foreach (self::FALLBACK_CHAIN as $name) {
            RawWire_Provider_Status::reset($name);
        }

        // Clear cached instances
        self::$providers = [];

        rawwire_log('search_provider', 'All provider statuses reset', 'info');
    }
}

/**
 * Brave Search Provider (stub)
 * 
 * Requires API key. Falls back if not configured.
 */
class RawWire_Brave_Provider implements RawWire_Search_Provider_Interface
{
    const PROVIDER_NAME = 'brave';
    const API_URL = 'https://api.search.brave.com/res/v1/web/search';

    private string $api_key;

    public function __construct()
    {
        $settings = get_option('rawwire_party_investigator_settings', []);
        $this->api_key = $settings['brave_api_key'] ?? '';
    }

    public function get_name(): string
    {
        return self::PROVIDER_NAME;
    }

    public function is_available(): bool
    {
        return !empty($this->api_key) && !RawWire_Provider_Status::is_in_cooldown(self::PROVIDER_NAME);
    }

    public function search(string $query): array
    {
        if (!$this->is_available()) {
            return [];
        }

        $response = wp_remote_get(
            add_query_arg(['q' => $query, 'count' => 10], self::API_URL),
            [
                'headers' => [
                    'Accept' => 'application/json',
                    'X-Subscription-Token' => $this->api_key,
                ],
                'timeout' => 15,
            ]
        );

        if (is_wp_error($response)) {
            RawWire_Provider_Status::record_failure(self::PROVIDER_NAME, $response->get_error_message());
            return [];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            RawWire_Provider_Status::record_failure(self::PROVIDER_NAME, "HTTP {$code}");
            return [];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $results = [];

        if (!empty($body['web']['results'])) {
            foreach ($body['web']['results'] as $item) {
                $results[] = [
                    'title' => $item['title'] ?? '',
                    'url' => $item['url'] ?? '',
                    'description' => $item['description'] ?? '',
                ];
            }
        }

        RawWire_Provider_Status::record_success(self::PROVIDER_NAME);
        return $results;
    }

    public function research(array $party): array
    {
        // Use the DuckDuckGo provider's query building logic
        $ddg = new RawWire_DuckDuckGo_Provider();

        if (!$this->is_available()) {
            return $ddg->research($party);
        }

        // Build queries and search
        $name = $party['name'] ?? '';
        $company = $party['company'] ?? '';
        $location = $party['city'] ?? 'Los Angeles';

        $queries = [
            "\"{$name}\" {$location} contact",
            "site:linkedin.com \"{$name}\"",
            "\"{$company}\" {$location} construction",
        ];

        $all_results = [];
        foreach ($queries as $query) {
            $results = $this->search($query);
            if (!empty($results)) {
                $all_results[] = [
                    'query' => $query,
                    'results' => $results,
                    'provider' => self::PROVIDER_NAME,
                ];
            }
            usleep(200000); // Rate limit
        }

        return [
            'success' => !empty($all_results),
            'content' => $this->format_results($all_results),
            'source' => self::PROVIDER_NAME,
            'results' => $all_results,
        ];
    }

    private function format_results(array $all_results): string
    {
        $text = '';
        foreach ($all_results as $qr) {
            $text .= "### {$qr['query']}\n";
            foreach ($qr['results'] as $r) {
                $text .= "- {$r['title']}\n  {$r['url']}\n";
            }
        }
        return $text;
    }
}
