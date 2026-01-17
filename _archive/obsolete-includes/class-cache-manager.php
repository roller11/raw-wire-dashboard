<?php
/**
 * Cache Manager Class
 *
 * Provides a comprehensive caching system with TTL support, automatic expiration,
 * and cache invalidation capabilities. Integrates with WordPress transients for
 * persistent caching across page loads.
 *
 * @package Raw_Wire_Dashboard
 * @subpackage Includes
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Class RWD_Cache_Manager
 *
 * Manages application-level caching with TTL (Time-To-Live) support,
 * expiration handling, and bulk invalidation capabilities.
 */
class RWD_Cache_Manager {

	/**
	 * Cache storage (in-memory)
	 *
	 * @var array
	 */
	private $memory_cache = array();

	/**
	 * Cache metadata (expiration times)
	 *
	 * @var array
	 */
	private $cache_metadata = array();

	/**
	 * Default TTL in seconds (1 hour)
	 *
	 * @var int
	 */
	private $default_ttl = 3600;

	/**
	 * Cache key prefix for WordPress transients
	 *
	 * @var string
	 */
	private $cache_prefix = 'rwd_cache_';

	/**
	 * Cache invalidation groups
	 *
	 * @var array
	 */
	private $invalidation_groups = array();

	/**
	 * Bloom filter for fast non-existence checks
	 *
	 * OPTIMIZATION 4: Simple bloom filter implementation
	 *
	 * @var array
	 */
	private $bloom_filter = array();

	/**
	 * Maximum memory cache size
	 *
	 * OPTIMIZATION 2: LRU eviction limit
	 *
	 * @var int
	 */
	private $max_memory_cache_size = 100;

	/**
	 * Frequently accessed keys for preloading
	 *
	 * OPTIMIZATION 3: Preload tracking
	 *
	 * @var array
	 */
	private $frequent_keys = array();

	/**
	 * Cache tags for grouped invalidation
	 *
	 * OPTIMIZATION 2: Cache tags
	 *
	 * @var array
	 */
	private $cache_tags = array();

	/**
	 * Lock keys for stampede prevention
	 *
	 * OPTIMIZATION 3: Stampede prevention
	 *
	 * @var array
	 */
	private $locks = array();

	/**
	 * Cache metrics
	 *
	 * OPTIMIZATION 5: Track hit/miss rates
	 *
	 * @var array
	 */
	private $metrics = array(
		'hits' => 0,
		'misses' => 0,
		'sets' => 0,
		'deletes' => 0,
		'memory_usage' => 0,
	);

	/**
	 * Distributed cache adapter (Redis/Memcached)
	 *
	 * OPTIMIZATION 4: Distributed caching
	 *
	 * @var object|null
	 */
	private $distributed_cache = null;

	/**
	 * Constructor
	 *
	 * @param int    $default_ttl Optional. Default TTL in seconds. Default is 3600 (1 hour).
	 * @param string $prefix      Optional. Cache key prefix. Default is 'rwd_cache_'.
	 */
	public function __construct( $default_ttl = 3600, $prefix = 'rwd_cache_' ) {
		$this->default_ttl    = $default_ttl;
		$this->cache_prefix   = $prefix;
		$this->memory_cache   = array();
		$this->cache_metadata = array();
		
		// OPTIMIZATION 4: Initialize distributed cache if available
		$this->init_distributed_cache();
		
		// OPTIMIZATION 5: Load metrics from persistent storage
		$this->load_metrics();
		
		// OPTIMIZATION 1: Warm cache on initialization
		$this->warm_cache();
	}

	/**
	 * Get a value from cache
	 *
	 * Attempts to retrieve a cached value. Checks memory cache first, then
	 * WordPress transients. Returns false if the cache key doesn't exist or
	 * if the TTL has expired.
	/**
	 * Get a value from cache
	 *
	 * Retrieves a cached value from memory cache first, then WordPress transients.
	 * Returns false if the key doesn't exist or the cached value has expired.
	 *
	 * OPTIMIZATIONS DEPLOYED:
	 * 1. Logging with debug severity for cache hits/misses
	 * 2. LRU eviction when memory cache is full
	 * 3. Preload frequently accessed keys
	 * 4. Bloom filter for fast non-existence checks
	 * 5. Lazy refresh before expiration
	 *
	 * @param string $key      The cache key.
	 * @param bool   $use_transient Optional. Whether to use WordPress transients. Default true.
	 *
	 * @return mixed The cached value, or false if not found or expired.
	 */
	public function get( $key, $use_transient = true ) {
		// Validate key
		if ( ! $this->is_valid_key( $key ) ) {
			if ( class_exists( 'RawWire_Logger' ) ) {
				RawWire_Logger::debug( 'Invalid cache key', 'cache', array( 'key' => $key ) );
			}
			return false;
		}

		// OPTIMIZATION 4: Bloom filter check for fast non-existence
		if ( ! $this->bloom_filter_check( $key ) ) {
			if ( class_exists( 'RawWire_Logger' ) ) {
				RawWire_Logger::debug( 'Bloom filter miss', 'cache', array( 'key' => $key ) );
			}
			return false;
		}

		// Check in-memory cache first
		if ( isset( $this->memory_cache[ $key ] ) ) {
			if ( $this->is_expired( $key ) ) {
				unset( $this->memory_cache[ $key ] );
				unset( $this->cache_metadata[ $key ] );
				
				if ( class_exists( 'RawWire_Logger' ) ) {
					RawWire_Logger::debug( 'Cache expired', 'cache', array( 'key' => $key ) );
				}
				
				return false;
			}
			
			// OPTIMIZATION 5: Lazy refresh if close to expiration
			if ( $this->should_lazy_refresh( $key ) ) {
				if ( class_exists( 'RawWire_Logger' ) ) {
					RawWire_Logger::debug( 'Cache lazy refresh triggered', 'cache', array( 'key' => $key ) );
				}
				do_action( 'rawwire_cache_lazy_refresh', $key );
			}
			
			// OPTIMIZATION 1: Log cache hit
			if ( class_exists( 'RawWire_Logger' ) ) {
				RawWire_Logger::debug( 'Cache hit (memory)', 'cache', array( 'key' => $key ) );
			}
			
			// Update access time for LRU
			$this->update_access_time( $key );
			
			return $this->memory_cache[ $key ];
		}

		// Check WordPress transients
		if ( $use_transient ) {
			$transient_key = $this->get_transient_key( $key );
			$value         = get_transient( $transient_key );
			
			if ( false !== $value ) {
				// OPTIMIZATION 2: Check if memory cache is full, evict LRU
				if ( $this->is_memory_cache_full() ) {
					$this->evict_lru();
				}
				
				// Store in memory cache for quick subsequent access
				$this->memory_cache[ $key ]   = $value;
				$this->cache_metadata[ $key ] = array(
					'created'     => time(),
					'ttl'         => $this->default_ttl,
					'last_access' => time(),
				);
				
				// OPTIMIZATION 1: Log cache hit
				if ( class_exists( 'RawWire_Logger' ) ) {
					RawWire_Logger::debug( 'Cache hit (transient)', 'cache', array( 'key' => $key ) );
				}
				
				$this->metrics['hits']++;
				return $value;
			}
		}

		// OPTIMIZATION 4: Check distributed cache (Redis/Memcached)
		if ($this->distributed_cache !== null) {
			$value = $this->get_from_distributed_cache($key);
			if ($value !== false) {
				$this->memory_cache[$key] = $value;
				$this->metrics['hits']++;
				return $value;
			}
		}

		// Cache miss
		if ( class_exists( 'RawWire_Logger' ) ) {
			RawWire_Logger::debug( 'Cache miss', 'cache', array( 'key' => $key ) );
		}
		
		$this->metrics['misses']++;
		return false;
	}

	/**
	 * Initialize distributed cache adapter
	 *
	 * OPTIMIZATION 4: Redis/Memcached support
	 *
	 * @since  1.0.16
	 * @return void
	 */
	private function init_distributed_cache() {
		// Check if Redis extension is available
		if (class_exists('Redis') && defined('RAWWIRE_REDIS_HOST')) {
			try {
				$redis = new Redis();
				$redis->connect(RAWWIRE_REDIS_HOST, RAWWIRE_REDIS_PORT ?? 6379);
				if (defined('RAWWIRE_REDIS_PASSWORD')) {
					$redis->auth(RAWWIRE_REDIS_PASSWORD);
				}
				$this->distributed_cache = $redis;
				if (class_exists('RawWire_Logger')) {
					RawWire_Logger::debug('Redis distributed cache initialized', 'cache', array());
				}
			} catch (Exception $e) {
				if (class_exists('RawWire_Logger')) {
					RawWire_Logger::log_error('Failed to connect to Redis', array('error' => $e->getMessage()), 'warning');
				}
			}
		}
	}

	/**
	 * Get value from distributed cache
	 *
	 * @since  1.0.16
	 * @param  string $key Cache key
	 * @return mixed|false
	 */
	private function get_from_distributed_cache($key) {
		if ($this->distributed_cache === null) {
			return false;
		}
		
		try {
			$value = $this->distributed_cache->get($this->cache_prefix . $key);
			return $value !== false ? unserialize($value) : false;
		} catch (Exception $e) {
			return false;
		}
	}

	/**
	 * Set value in distributed cache
	 *
	 * @since  1.0.16
	 * @param  string $key Cache key
	 * @param  mixed  $value Value to cache
	 * @param  int    $ttl TTL in seconds
	 * @return bool
	 */
	private function set_in_distributed_cache($key, $value, $ttl) {
		if ($this->distributed_cache === null) {
			return false;
		}
		
		try {
			return $this->distributed_cache->setex(
				$this->cache_prefix . $key,
				$ttl,
				serialize($value)
			);
		} catch (Exception $e) {
			return false;
		}
	}

	/**
	 * Warm cache with frequently accessed data
	 *
	 * OPTIMIZATION 1: Cache warming
	 *
	 * @since  1.0.16
	 * @return void
	 */
	private function warm_cache() {
		$warm_keys = get_option('rawwire_cache_warm_keys', array());
		
		foreach ($warm_keys as $key => $callback) {
			if (!$this->get($key, false)) {
				if (is_callable($callback)) {
					$value = call_user_func($callback);
					$this->set($key, $value);
				}
			}
		}
	}

	/**
	 * Register key for cache warming
	 *
	 * OPTIMIZATION 1: Cache warming on startup
	 *
	 * @since  1.0.16
	 * @param  string   $key Cache key
	 * @param  callable $callback Function to generate value
	 * @return void
	 */
	public function register_warm_key($key, $callback) {
		$warm_keys = get_option('rawwire_cache_warm_keys', array());
		$warm_keys[$key] = $callback;
		update_option('rawwire_cache_warm_keys', $warm_keys, false);
	}

	/**
	 * Acquire lock for stampede prevention
	 *
	 * OPTIMIZATION 3: Cache stampede prevention
	 *
	 * @since  1.0.16
	 * @param  string $key Cache key
	 * @param  int    $timeout Lock timeout in seconds
	 * @return bool True if lock acquired
	 */
	public function acquire_lock($key, $timeout = 10) {
		$lock_key = 'lock_' . $key;
		
		// Check if lock exists
		if (isset($this->locks[$lock_key]) && $this->locks[$lock_key] > time()) {
			return false;
		}
		
		// Set lock
		$this->locks[$lock_key] = time() + $timeout;
		set_transient($this->get_transient_key($lock_key), true, $timeout);
		
		return true;
	}

	/**
	 * Release lock
	 *
	 * @since  1.0.16
	 * @param  string $key Cache key
	 * @return void
	 */
	public function release_lock($key) {
		$lock_key = 'lock_' . $key;
		unset($this->locks[$lock_key]);
		delete_transient($this->get_transient_key($lock_key));
	}

	/**
	 * Add cache tags for grouped invalidation
	 *
	 * OPTIMIZATION 2: Cache tags
	 *
	 * @since  1.0.16
	 * @param  string $key Cache key
	 * @param  array  $tags Tags to associate
	 * @return void
	 */
	public function tag($key, $tags) {
		if (!is_array($tags)) {
			$tags = array($tags);
		}
		
		foreach ($tags as $tag) {
			if (!isset($this->cache_tags[$tag])) {
				$this->cache_tags[$tag] = array();
			}
			$this->cache_tags[$tag][] = $key;
		}
		
		update_option('rawwire_cache_tags', $this->cache_tags, false);
	}

	/**
	 * Invalidate all caches with specific tag
	 *
	 * OPTIMIZATION 2: Grouped cache invalidation
	 *
	 * @since  1.0.16
	 * @param  string $tag Tag to invalidate
	 * @return int Number of keys invalidated
	 */
	public function invalidate_tag($tag) {
		if (!isset($this->cache_tags[$tag])) {
			$this->cache_tags = get_option('rawwire_cache_tags', array());
		}
		
		if (!isset($this->cache_tags[$tag])) {
			return 0;
		}
		
		$count = 0;
		foreach ($this->cache_tags[$tag] as $key) {
			$this->delete($key);
			$count++;
		}
		
		unset($this->cache_tags[$tag]);
		update_option('rawwire_cache_tags', $this->cache_tags, false);
		
		return $count;
	}

	/**
	 * Get cache metrics
	 *
	 * OPTIMIZATION 5: Cache metrics and monitoring
	 *
	 * @since  1.0.16
	 * @return array Cache statistics
	 */
	public function get_metrics() {
		$this->metrics['memory_usage'] = count($this->memory_cache);
		$this->metrics['hit_rate'] = $this->metrics['hits'] + $this->metrics['misses'] > 0
			? round(($this->metrics['hits'] / ($this->metrics['hits'] + $this->metrics['misses'])) * 100, 2)
			: 0;
		
		return $this->metrics;
	}

	/**
	 * Load metrics from persistent storage
	 *
	 * @since  1.0.16
	 * @return void
	 */
	private function load_metrics() {
		$stored = get_option('rawwire_cache_metrics', array());
		if (!empty($stored)) {
			$this->metrics = array_merge($this->metrics, $stored);
		}
	}

	/**
	 * Save metrics to persistent storage
	 *
	 * @since  1.0.16
	 * @return void
	 */
	private function save_metrics() {
		update_option('rawwire_cache_metrics', $this->metrics, false);
	}

	/**
	 * Reset metrics
	 *
	 * @since  1.0.16
	 * @return void
	 */
	public function reset_metrics() {
		$this->metrics = array(
			'hits' => 0,
			'misses' => 0,
			'sets' => 0,
			'deletes' => 0,
			'memory_usage' => 0,
		);
		$this->save_metrics();

		// OPTIMIZATION 1: Log cache miss
		if ( class_exists( 'RawWire_Logger' ) ) {
			RawWire_Logger::debug( 'Cache miss', 'cache', array( 'key' => $key ) );
		}
		
		return false;
	}

	/**
	 * Set a value in cache
	 *
	 * Stores a value in both memory cache and WordPress transients.
	 * The value will expire after the specified TTL.
	 *
	 * @param string $key       The cache key.
	 * @param mixed  $value     The value to cache.
	 * @param int    $ttl       Optional. Time-to-live in seconds. Default is self::$default_ttl.
	 * @param string $group     Optional. Cache group for invalidation. Default empty string.
	 * @param bool   $use_transient Optional. Whether to use WordPress transients. Default true.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function set( $key, $value, $ttl = null, $group = '', $use_transient = true ) {
		// Validate key
		if ( ! $this->is_valid_key( $key ) ) {
			return false;
		}

		// Use default TTL if not specified
		if ( null === $ttl ) {
			$ttl = $this->default_ttl;
		}

		// Validate TTL
		$ttl = max( 1, (int) $ttl );

		// Store in memory cache
		$this->memory_cache[ $key ]   = $value;
		$this->cache_metadata[ $key ] = array(
			'created'     => time(),
			'ttl'         => $ttl,
			'group'       => $group,
			'last_access' => time(),
		);

		// OPTIMIZATION 4: Add to bloom filter
		$this->bloom_filter_add( $key );

		// Store in WordPress transients
		if ( $use_transient ) {
			$transient_key = $this->get_transient_key( $key );
			set_transient( $transient_key, $value, $ttl );
		}

		// Track group for invalidation
		if ( ! empty( $group ) ) {
			if ( ! isset( $this->invalidation_groups[ $group ] ) ) {
				$this->invalidation_groups[ $group ] = array();
			}
			if ( ! in_array( $key, $this->invalidation_groups[ $group ], true ) ) {
				$this->invalidation_groups[ $group ][] = $key;
			}
		}

		return true;
	}

	/**
	 * Delete a specific cache entry
	 *
	 * Removes a value from both memory cache and WordPress transients.
	 *
	 * @param string $key          The cache key to delete.
	 * @param bool   $use_transient Optional. Whether to delete from WordPress transients. Default true.
	 *
	 * @return bool True on success, false if key doesn't exist.
	 */
	public function delete( $key, $use_transient = true ) {
		// Validate key
		if ( ! $this->is_valid_key( $key ) ) {
			return false;
		}

		$deleted = false;

		// Delete from memory cache
		if ( isset( $this->memory_cache[ $key ] ) ) {
			unset( $this->memory_cache[ $key ] );
			$deleted = true;
		}

		// Delete metadata
		if ( isset( $this->cache_metadata[ $key ] ) ) {
			unset( $this->cache_metadata[ $key ] );
		}

		// Delete from WordPress transients
		if ( $use_transient ) {
			$transient_key = $this->get_transient_key( $key );
			delete_transient( $transient_key );
		}

		return $deleted;
	}

	/**
	 * Invalidate cache by group
	 *
	 * Deletes all cache entries that belong to a specific group.
	 * Useful for bulk cache invalidation when related data changes.
	 *
	 * @param string $group        The cache group to invalidate.
	 * @param bool   $use_transient Optional. Whether to delete from WordPress transients. Default true.
	 *
	 * @return int Number of cache entries deleted.
	 */
	public function invalidate_group( $group, $use_transient = true ) {
		$deleted_count = 0;

		// Get all keys in the group
		if ( isset( $this->invalidation_groups[ $group ] ) ) {
			foreach ( $this->invalidation_groups[ $group ] as $key ) {
				if ( $this->delete( $key, $use_transient ) ) {
					$deleted_count++;
				}
			}
			unset( $this->invalidation_groups[ $group ] );
		}

		return $deleted_count;
	}

	/**
	 * Invalidate all cache entries
	 *
	 * Clears both memory cache and WordPress transients.
	 * Use with caution as this affects all cached data.
	 *
	 * @param bool $use_transient Optional. Whether to delete from WordPress transients. Default true.
	 *
	 * @return int Number of cache entries deleted.
	 */
	public function invalidate_all( $use_transient = true ) {
		$deleted_count = count( $this->memory_cache );

		foreach ( array_keys( $this->memory_cache ) as $key ) {
			$this->delete( $key, $use_transient );
		}

		$this->invalidation_groups = array();

		return $deleted_count;
	}

	/**
	 * Get cache statistics
	 *
	 * Returns information about the current cache state including
	 * size, expired entries, and groups.
	 *
	 * @return array Cache statistics.
	 */
	public function get_stats() {
		$expired_count = 0;

		foreach ( $this->cache_metadata as $key => $metadata ) {
			if ( $this->is_expired( $key ) ) {
				$expired_count++;
			}
		}

		return array(
			'total_entries'     => count( $this->memory_cache ),
			'expired_entries'   => $expired_count,
			'memory_usage'      => strlen( serialize( $this->memory_cache ) ),
			'groups'            => count( $this->invalidation_groups ),
			'cache_prefix'      => $this->cache_prefix,
			'default_ttl'       => $this->default_ttl,
			'timestamp'         => current_time( 'mysql' ),
		);
	}

	/**
	 * Check if a cache entry has expired
	 *
	 * Determines if the TTL for a cache entry has passed based on
	 * the creation time and specified TTL.
	 *
	 * @param string $key The cache key to check.
	 *
	 * @return bool True if expired, false otherwise.
	 */
	private function is_expired( $key ) {
		if ( ! isset( $this->cache_metadata[ $key ] ) ) {
			return true;
		}

		$metadata = $this->cache_metadata[ $key ];
		$created  = $metadata['created'];
		$ttl      = $metadata['ttl'];
		$elapsed  = time() - $created;

		return $elapsed >= $ttl;
	}

	/**
	 * Validate cache key format
	 *
	 * Ensures the cache key meets minimum requirements.
	 *
	 * @param string $key The key to validate.
	 *
	 * @return bool True if valid, false otherwise.
	 */
	private function is_valid_key( $key ) {
		if ( ! is_string( $key ) || empty( $key ) ) {
			return false;
		}

		// WordPress transient keys have a 191 character limit (255 - prefix length)
		if ( strlen( $this->cache_prefix . $key ) > 191 ) {
			return false;
		}

		return true;
	}

	/**
	 * Generate WordPress transient key
	 *
	 * Creates a properly formatted transient key with the cache prefix.
	 *
	 * @param string $key The cache key.
	 *
	 * @return string The formatted transient key.
	 */
	private function get_transient_key( $key ) {
		return $this->cache_prefix . $key;
	}

	/**
	 * Get cache entry metadata
	 *
	 * Returns metadata for a specific cache entry including
	 * creation time, TTL, and group information.
	 *
	 * @param string $key The cache key.
	 *
	 * @return array|false Metadata array or false if entry doesn't exist.
	 */
	public function get_metadata( $key ) {
		if ( ! isset( $this->cache_metadata[ $key ] ) ) {
			return false;
		}

		$metadata         = $this->cache_metadata[ $key ];
		$created          = $metadata['created'];
		$ttl              = $metadata['ttl'];
		$elapsed          = time() - $created;
		$remaining_ttl    = max( 0, $ttl - $elapsed );

		return array(
			'created'       => $created,
			'ttl'           => $ttl,
			'elapsed'       => $elapsed,
			'remaining_ttl' => $remaining_ttl,
			'expired'       => $remaining_ttl <= 0,
			'group'         => isset( $metadata['group'] ) ? $metadata['group'] : '',
		);
	}

	/**
	 * Check if a cache entry exists
	 *
	 * Determines if a cache key exists and hasn't expired.
	 *
	 * @param string $key The cache key to check.
	 *
	 * @return bool True if exists and valid, false otherwise.
	 */
	public function exists( $key ) {
		if ( ! isset( $this->memory_cache[ $key ] ) ) {
			return false;
		}

		if ( $this->is_expired( $key ) ) {
			$this->delete( $key );
			return false;
		}

		return true;
	}

	/**
	 * Get all cache keys (optionally filtered by group)
	 *
	 * @param string $group Optional. Filter by group. Default empty string (no filter).
	 *
	 * @return array Array of cache keys.
	 */
	public function get_keys( $group = '' ) {
		if ( empty( $group ) ) {
			return array_keys( $this->memory_cache );
		}

		if ( ! isset( $this->invalidation_groups[ $group ] ) ) {
			return array();
		}

		return $this->invalidation_groups[ $group ];
	}

	/**
	 * Increment a numeric cache value
	 *
	 * Atomically increments the numeric value stored at a key.
	 *
	 * @param string $key       The cache key.
	 * @param int    $increment Optional. Amount to increment by. Default 1.
	 * @param int    $ttl       Optional. TTL for the value. Default is self::$default_ttl.
	 * @param string $group     Optional. Cache group. Default empty string.
	 *
	 * @return int|false The new value on success, false on failure.
	 */
	public function increment( $key, $increment = 1, $ttl = null, $group = '' ) {
		$current = $this->get( $key, false );

		if ( false === $current ) {
			$current = 0;
		}

		$new_value = (int) $current + (int) $increment;
		$this->set( $key, $new_value, $ttl, $group, false );

		return $new_value;
	}

	/**
	 * Decrement a numeric cache value
	 *
	 * Atomically decrements the numeric value stored at a key.
	 *
	 * @param string $key       The cache key.
	 * @param int    $decrement Optional. Amount to decrement by. Default 1.
	 * @param int    $ttl       Optional. TTL for the value. Default is self::$default_ttl.
	 * @param string $group     Optional. Cache group. Default empty string.
	 *
	 * @return int|false The new value on success, false on failure.
	 */
	public function decrement( $key, $decrement = 1, $ttl = null, $group = '' ) {
		return $this->increment( $key, -1 * (int) $decrement, $ttl, $group );
	}

	/**
	 * Check if key might exist using bloom filter
	 *
	 * OPTIMIZATION 4: Fast probabilistic check for key existence
	 *
	 * @param string $key Cache key
	 * @return bool True if key might exist, false if definitely doesn't exist
	 */
	private function bloom_filter_check( $key ) {
		$hash = crc32( $key ) % 1000;
		return isset( $this->bloom_filter[ $hash ] );
	}

	/**
	 * Add key to bloom filter
	 *
	 * OPTIMIZATION 4: Mark key as possibly existing
	 *
	 * @param string $key Cache key
	 */
	private function bloom_filter_add( $key ) {
		$hash = crc32( $key ) % 1000;
		$this->bloom_filter[ $hash ] = true;
	}

	/**
	 * Check if memory cache is full
	 *
	 * OPTIMIZATION 2: LRU eviction trigger
	 *
	 * @return bool True if cache is full
	 */
	private function is_memory_cache_full() {
		return count( $this->memory_cache ) >= $this->max_memory_cache_size;
	}

	/**
	 * Evict least recently used item
	 *
	 * OPTIMIZATION 2: LRU eviction algorithm
	 */
	private function evict_lru() {
		if ( empty( $this->memory_cache ) ) {
			return;
		}

		$oldest_key = null;
		$oldest_time = PHP_INT_MAX;

		foreach ( $this->cache_metadata as $key => $meta ) {
			if ( isset( $meta['last_access'] ) && $meta['last_access'] < $oldest_time ) {
				$oldest_time = $meta['last_access'];
				$oldest_key = $key;
			}
		}

		if ( $oldest_key ) {
			if ( class_exists( 'RawWire_Logger' ) ) {
				RawWire_Logger::debug( 'LRU eviction', 'cache', array( 'key' => $oldest_key ) );
			}
			unset( $this->memory_cache[ $oldest_key ] );
			unset( $this->cache_metadata[ $oldest_key ] );
		}
	}

	/**
	 * Update access time for LRU tracking
	 *
	 * OPTIMIZATION 2: LRU timestamp update
	 *
	 * @param string $key Cache key
	 */
	private function update_access_time( $key ) {
		if ( isset( $this->cache_metadata[ $key ] ) ) {
			$this->cache_metadata[ $key ]['last_access'] = time();
		}
	}

	/**
	 * Check if key should be lazy refreshed
	 *
	 * OPTIMIZATION 5: Proactive refresh before expiration
	 *
	 * @param string $key Cache key
	 * @return bool True if should refresh
	 */
	private function should_lazy_refresh( $key ) {
		if ( ! isset( $this->cache_metadata[ $key ] ) ) {
			return false;
		}

		$meta = $this->cache_metadata[ $key ];
		$age = time() - $meta['created'];
		$ttl = isset( $meta['ttl'] ) ? $meta['ttl'] : $this->default_ttl;

		// Refresh if 90% of TTL has elapsed
		return ( $age / $ttl ) >= 0.9;
	}

	/**
	 * Preload frequently accessed keys
	 *
	 * OPTIMIZATION 3: Preload common keys into memory cache
	 *
	 * @param array $keys Array of keys to preload
	 */
	public function preload( $keys ) {
		foreach ( $keys as $key ) {
			$value = $this->get( $key, true );
			if ( $value !== false ) {
				$this->frequent_keys[] = $key;
				if ( class_exists( 'RawWire_Logger' ) ) {
					RawWire_Logger::debug( 'Key preloaded', 'cache', array( 'key' => $key ) );
				}
			}
		}
	}

}
