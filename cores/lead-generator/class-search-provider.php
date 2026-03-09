<?php

/**
 * Search Provider Interface
 * 
 * Defines the contract for all search providers (OpenClaw, DuckDuckGo, Brave).
 * Enables modular, swappable search backends with consistent I/O.
 * 
 * @package RawWire_Dashboard
 * @since   1.0.32
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Search Provider Interface
 * 
 * All search providers must implement these methods for consistent behavior.
 */
interface RawWire_Search_Provider_Interface
{
    /**
     * Search for information about a query
     * 
     * @param string $query Search query text
     * @return array Array of results with 'title', 'url', 'description'
     */
    public function search(string $query): array;

    /**
     * Research a party (company/person) in depth
     * 
     * @param array $party Party data with name, type, company, location
     * @return array Research findings with 'success', 'content', 'source', 'results'
     */
    public function research(array $party): array;

    /**
     * Check if provider is available
     * 
     * @return bool True if provider can handle requests
     */
    public function is_available(): bool;

    /**
     * Get provider name for logging
     * 
     * @return string Provider identifier
     */
    public function get_name(): string;
}

/**
 * Provider Status - Tracks connection state with cooldown
 */
class RawWire_Provider_Status
{
    /** @var string Option key for status storage */
    const OPTION_KEY = 'rawwire_provider_status';

    /** @var int Cooldown period after failure (seconds) */
    const COOLDOWN_SECONDS = 15;

    /** @var int Max failures before extended cooldown */
    const MAX_FAILURES = 5;

    /** @var int Extended cooldown after max failures (seconds) */
    const EXTENDED_COOLDOWN_SECONDS = 60;

    /**
     * Check if provider is in cooldown
     */
    public static function is_in_cooldown(string $provider): bool
    {
        $status = self::get_status($provider);

        if (empty($status['last_failure'])) {
            return false;
        }

        $elapsed = time() - $status['last_failure'];
        $cooldown = ($status['failure_count'] >= self::MAX_FAILURES)
            ? self::EXTENDED_COOLDOWN_SECONDS
            : self::COOLDOWN_SECONDS;

        return $elapsed < $cooldown;
    }

    /**
     * Record a failure
     */
    public static function record_failure(string $provider, string $reason = ''): void
    {
        $status = self::get_status($provider);
        $status['last_failure'] = time();
        $status['failure_count'] = ($status['failure_count'] ?? 0) + 1;
        $status['last_reason'] = $reason;
        $status['is_available'] = false;
        self::save_status($provider, $status);

        rawwire_log('provider_status', sprintf(
            '%s failure #%d: %s (cooldown %ds)',
            $provider,
            $status['failure_count'],
            $reason,
            ($status['failure_count'] >= self::MAX_FAILURES)
                ? self::EXTENDED_COOLDOWN_SECONDS
                : self::COOLDOWN_SECONDS
        ), 'warning');
    }

    /**
     * Record a success (resets failure count)
     */
    public static function record_success(string $provider): void
    {
        $status = self::get_status($provider);
        $previous_failures = $status['failure_count'] ?? 0;

        $status['failure_count'] = 0;
        $status['last_success'] = time();
        $status['is_available'] = true;
        $status['last_failure'] = null;
        self::save_status($provider, $status);

        if ($previous_failures > 0) {
            rawwire_log('provider_status', sprintf(
                '%s recovered after %d failures',
                $provider,
                $previous_failures
            ), 'info');
        }
    }

    /**
     * Get current status for provider
     */
    public static function get_status(string $provider): array
    {
        $all_status = get_option(self::OPTION_KEY, []);
        return $all_status[$provider] ?? [
            'is_available' => true,
            'last_success' => null,
            'last_failure' => null,
            'failure_count' => 0,
            'last_reason' => '',
        ];
    }

    /**
     * Save status for provider
     */
    private static function save_status(string $provider, array $status): void
    {
        $all_status = get_option(self::OPTION_KEY, []);
        $all_status[$provider] = $status;
        update_option(self::OPTION_KEY, $all_status, false); // no autoload
    }

    /**
     * Reset status (for manual recovery)
     */
    public static function reset(string $provider): void
    {
        $all_status = get_option(self::OPTION_KEY, []);
        unset($all_status[$provider]);
        update_option(self::OPTION_KEY, $all_status, false);

        rawwire_log('provider_status', sprintf('%s status reset', $provider), 'info');
    }

    /**
     * Get all provider statuses
     */
    public static function get_all(): array
    {
        return get_option(self::OPTION_KEY, []);
    }
}
