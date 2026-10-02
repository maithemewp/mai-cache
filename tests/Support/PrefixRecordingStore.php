<?php
declare(strict_types=1);

namespace Mai\Cache\Tests\Support;

use Mai\Cache\PrefixDelete;
use Mai\Cache\Store;

/**
 * Store double that also implements PrefixDelete. It wraps an ArrayStore and
 * records each delete_prefix() call, along with the token rows as they stood at
 * that moment, so a test can tell whether the token had rotated by then.
 */
final class PrefixRecordingStore implements Store, PrefixDelete {
	public ArrayStore $inner;

	/**
	 * Prefixes passed to delete_prefix(), in call order.
	 *
	 * @var string[]
	 */
	public array $deleted = [];

	/**
	 * A copy of the inner store's data at each delete_prefix() call.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $data_at_delete = [];

	public function __construct( ?ArrayStore $inner = null ) {
		$this->inner = $inner ?? new ArrayStore();
	}

	public function read( string $key ): mixed {
		return $this->inner->read( $key );
	}

	public function write( string $key, mixed $value, int $expire ): bool {
		return $this->inner->write( $key, $value, $expire );
	}

	public function remove( string $key ): bool {
		return $this->inner->remove( $key );
	}

	public function available(): bool {
		return $this->inner->available();
	}

	public function delete_prefix( string $prefix ): int {
		$this->deleted[]        = $prefix;
		$this->data_at_delete[] = $this->inner->data;

		return 0;
	}
}
