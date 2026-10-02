<?php
declare(strict_types=1);

namespace Mai\Cache\Tests\Support;

use Mai\Cache\Store;

/**
 * Store double that wraps an ArrayStore and counts the writes to each key.
 */
final class CountingStore implements Store {
	public ArrayStore $inner;

	/**
	 * Number of write() calls per key.
	 *
	 * @var array<string,int>
	 */
	public array $writes = [];

	public function __construct( ?ArrayStore $inner = null ) {
		$this->inner = $inner ?? new ArrayStore();
	}

	public function read( string $key ): mixed {
		return $this->inner->read( $key );
	}

	public function write( string $key, mixed $value, int $expire ): bool {
		$this->writes[ $key ] = ( $this->writes[ $key ] ?? 0 ) + 1;

		return $this->inner->write( $key, $value, $expire );
	}

	public function remove( string $key ): bool {
		return $this->inner->remove( $key );
	}

	public function available(): bool {
		return $this->inner->available();
	}
}
