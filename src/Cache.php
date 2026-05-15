<?php
/**
 * Mai\Cache\Cache — remember()-pattern wrapper around WordPress transients.
 *
 * @package maithemewp/mai-cache
 * @license GPL-2.0-or-later
 */

namespace Mai\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Transient-backed cache with a Laravel-style remember() pattern.
 *
 * Auto-bypasses caching when SCRIPT_DEBUG is true so you never debug stale
 * cache during development.
 *
 * @since 0.1.0
 */
class Cache {

	/**
	 * Memoized instances keyed by prefix, for the static for() factory.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string,self>
	 */
	private static array $instances = [];

	/**
	 * Prefix applied to all transient keys (and the filter name).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param string $prefix Prefix prepended to all keys. Default 'mai'.
	 *                       Example: prefix 'acme' + key 'popular_posts'
	 *                       → transient key 'acme_popular_posts'.
	 */
	public function __construct( string $prefix = 'mai' ) {
		$this->prefix = trim( $prefix, '_' );
	}

	/**
	 * Get (or create) the memoized instance for a given prefix.
	 *
	 * Useful for one-liners:
	 *
	 *     Cache::for( 'acme' )->remember( 'key', fn() => …, HOUR_IN_SECONDS );
	 *
	 * @since 0.1.0
	 *
	 * @param string $prefix
	 *
	 * @return self
	 */
	public static function for( string $prefix = 'mai' ): self {
		$prefix = trim( $prefix, '_' );

		if ( ! isset( self::$instances[ $prefix ] ) ) {
			self::$instances[ $prefix ] = new self( $prefix );
		}

		return self::$instances[ $prefix ];
	}

	/**
	 * Get the prefix used by this instance.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function prefix(): string {
		return $this->prefix;
	}

	/**
	 * Get a cached value, or compute + cache it via the callback.
	 *
	 * If the callback returns a WP_Error, the result is NOT cached (so a
	 * transient failure doesn't persist).
	 *
	 * @since 0.1.0
	 *
	 * @param string   $key      Cache key (without prefix).
	 * @param callable $callback Generator for the value if cache misses.
	 * @param int      $expire   TTL in seconds.
	 *
	 * @return mixed The cached value or the callback result.
	 */
	public function remember( string $key, callable $callback, int $expire ): mixed {
		$cached = $this->get( $key );

		if ( false !== $cached ) {
			return $cached;
		}

		$value = $callback();

		if ( ! is_wp_error( $value ) ) {
			$this->set( $key, $value, $expire );
		}

		return $value;
	}

	/**
	 * Get a cached value, deleting it from the cache on hit (read-once).
	 *
	 * @since 0.1.0
	 *
	 * @param string $key
	 * @param mixed  $default Returned if the key is missing.
	 *
	 * @return mixed
	 */
	public function forget( string $key, mixed $default = null ): mixed {
		$cached = $this->get( $key );

		if ( false !== $cached ) {
			$this->delete( $key );
			return $cached;
		}

		return $default;
	}

	/**
	 * Get a cached value directly. Returns false on miss or when caching is
	 * disabled (SCRIPT_DEBUG / can_cache filter).
	 *
	 * @since 0.1.0
	 *
	 * @param string $key
	 *
	 * @return mixed
	 */
	public function get( string $key ): mixed {
		if ( ! $this->can_cache() ) {
			return false;
		}

		return get_transient( $this->key( $key ) );
	}

	/**
	 * Set a cached value directly.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key
	 * @param mixed  $value
	 * @param int    $expire TTL in seconds.
	 *
	 * @return bool True if the value was set; false if caching is disabled.
	 */
	public function set( string $key, mixed $value, int $expire ): bool {
		if ( ! $this->can_cache() ) {
			return false;
		}

		return set_transient( $this->key( $key ), $value, absint( $expire ) );
	}

	/**
	 * Delete a cached value.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key
	 *
	 * @return bool
	 */
	public function delete( string $key ): bool {
		return delete_transient( $this->key( $key ) );
	}

	/**
	 * Build the fully-prefixed transient key.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key
	 *
	 * @return string
	 */
	public function key( string $key ): string {
		return $this->prefix . '_' . ltrim( $key, '_' );
	}

	/**
	 * Whether caching is currently allowed.
	 *
	 * Disabled when:
	 * - SCRIPT_DEBUG is true (dev — never want stale)
	 * - The "{prefix}_can_cache" filter returns false
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function can_cache(): bool {
		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
			return false;
		}

		/**
		 * Filter whether caching is enabled for this prefix.
		 *
		 * Example: add_filter( 'acme_can_cache', '__return_false' );
		 *
		 * @since 0.1.0
		 *
		 * @param bool   $enabled Whether caching is enabled.
		 * @param string $prefix  The prefix this instance uses.
		 */
		return (bool) apply_filters( $this->prefix . '_can_cache', true, $this->prefix );
	}
}
