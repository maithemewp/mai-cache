<?php
/**
 * Mai\Cache\Cache — remember()-pattern cache over a pluggable Store.
 *
 * @package maithemewp/mai-cache
 * @license GPL-2.0-or-later
 */

namespace Mai\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Transient- or object-cache-backed cache with a Laravel-style remember()
 * pattern. Auto-bypasses caching when SCRIPT_DEBUG is true, and (in object
 * mode) when there is no persistent object cache.
 *
 * @since 0.1.0
 */
class Cache {

	/**
	 * Memoized instances keyed by "mode:prefix".
	 *
	 * @var array<string,self>
	 */
	private static array $instances = [];

	/**
	 * Memoized version tokens keyed by scope (used from 0.2.0).
	 *
	 * @var array<string,string>
	 */
	private static array $tokens = [];

	private string $prefix;
	private Store $store;

	/**
	 * @param string     $prefix Prefix prepended to all keys. Default 'mai'.
	 * @param Store|null  $store  Storage backend. Defaults to TransientStore.
	 *
	 * @since 0.1.0
	 */
	public function __construct( string $prefix = 'mai', ?Store $store = null ) {
		$this->prefix = trim( $prefix, '_' );
		$this->store  = $store ?? new TransientStore();
	}

	/**
	 * Transient-backed instance (Redis when present, DB fallback).
	 *
	 * @since 0.1.0
	 */
	public static function for( string $prefix = 'mai' ): self {
		return self::instance( 'transient', $prefix );
	}

	/**
	 * Object-cache-only instance (wp_cache_*, no DB fallback). A no-op when
	 * there is no persistent object cache.
	 *
	 * @since 0.2.0
	 */
	public static function object( string $prefix = 'mai' ): self {
		return self::instance( 'object', $prefix );
	}

	private static function instance( string $mode, string $prefix ): self {
		$prefix = trim( $prefix, '_' );
		$id     = $mode . ':' . $prefix;

		if ( ! isset( self::$instances[ $id ] ) ) {
			$store                  = 'object' === $mode ? new ObjectCacheStore() : new TransientStore();
			self::$instances[ $id ] = new self( $prefix, $store );
		}

		return self::$instances[ $id ];
	}

	/**
	 * Get a cached value, or compute + cache it. A WP_Error result is not cached.
	 *
	 * @since 0.1.0
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
	 * Get a cached value, deleting it on hit (read-once / consume).
	 * Renamed from 0.1.0's forget() to match Laravel's pull() semantics.
	 *
	 * @since 0.2.0
	 */
	public function pull( string $key, mixed $default = null ): mixed {
		$cached = $this->get( $key );

		if ( false !== $cached ) {
			$this->delete( $key );
			return $cached;
		}

		return $default;
	}

	/**
	 * Get a cached value. Returns false on miss or when caching is disabled.
	 *
	 * @since 0.1.0
	 */
	public function get( string $key ): mixed {
		if ( ! $this->can_cache() ) {
			return false;
		}

		return $this->store->read( $this->key( $key ) );
	}

	/**
	 * Set a cached value. Returns false when caching is disabled.
	 *
	 * @since 0.1.0
	 */
	public function set( string $key, mixed $value, int $expire ): bool {
		if ( ! $this->can_cache() ) {
			return false;
		}

		return $this->store->write( $this->key( $key ), $value, max( 0, $expire ) );
	}

	/**
	 * Delete a cached value.
	 *
	 * @since 0.1.0
	 */
	public function delete( string $key ): bool {
		return $this->store->remove( $this->key( $key ) );
	}

	/**
	 * Build the fully-prefixed key.
	 *
	 * @since 0.1.0
	 */
	public function key( string $key ): string {
		return $this->prefix . '_' . ltrim( $key, '_' );
	}

	/**
	 * Whether caching is currently allowed.
	 *
	 * Disabled when SCRIPT_DEBUG is true, when the store cannot persist
	 * (object mode without a persistent object cache), or when the
	 * "{prefix}_can_cache" filter returns false.
	 *
	 * @since 0.1.0
	 */
	public function can_cache(): bool {
		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
			return false;
		}

		if ( ! $this->store->available() ) {
			return false;
		}

		return (bool) apply_filters( $this->prefix . '_can_cache', true, $this->prefix );
	}

	/**
	 * Get the prefix used by this instance.
	 *
	 * @since 0.1.0
	 */
	public function prefix(): string {
		return $this->prefix;
	}

	/**
	 * Whether a persistent object cache (e.g. Redis) is in use.
	 *
	 * @since 0.2.0
	 */
	public static function has_persistent_object_cache(): bool {
		return (bool) wp_using_ext_object_cache();
	}

	/**
	 * Reset memoized instances and version tokens. For tests and long-running
	 * processes (e.g. WP-CLI) that must not hold stale state across boundaries.
	 *
	 * @since 0.2.0
	 */
	public static function reset_runtime(): void {
		self::$instances = [];
		self::$tokens    = [];
	}
}
