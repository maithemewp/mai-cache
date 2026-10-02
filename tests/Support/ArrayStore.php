<?php
namespace Mai\Cache\Tests\Support;

use Mai\Cache\Cache;
use Mai\Cache\Store;

/**
 * In-memory Store double. read() returns false on miss, mirroring transients.
 * Honours $expire against Cache::now(), so tests can move the clock.
 */
final class ArrayStore implements Store {
	public array $data      = [];
	public bool  $available = true;

	/**
	 * Expiry timestamp per key. A key with no entry never expires.
	 *
	 * @var array<string,int>
	 */
	public array $expires = [];

	public function read( string $key ): mixed {
		if ( isset( $this->expires[ $key ] ) && Cache::now() >= $this->expires[ $key ] ) {
			unset( $this->data[ $key ], $this->expires[ $key ] );
		}

		return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : false;
	}

	public function write( string $key, mixed $value, int $expire ): bool {
		$this->data[ $key ] = $value;

		if ( $expire > 0 ) {
			$this->expires[ $key ] = Cache::now() + $expire;
		} else {
			unset( $this->expires[ $key ] );
		}

		return true;
	}

	public function remove( string $key ): bool {
		unset( $this->data[ $key ], $this->expires[ $key ] );
		return true;
	}

	public function available(): bool {
		return $this->available;
	}
}
