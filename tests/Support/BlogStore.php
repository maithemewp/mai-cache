<?php
namespace Mai\Cache\Tests\Support;

use Mai\Cache\Store;

/**
 * Store double for multisite: one ArrayStore per site, chosen by the current site ID, as
 * transients (one options table per site) and a per-site object cache are.
 */
final class BlogStore implements Store {
	public int $blog_id = 1;

	/** @var array<int, ArrayStore> */
	private array $stores = [];

	private function store(): ArrayStore {
		return $this->stores[ $this->blog_id ] ??= new ArrayStore();
	}

	public function read( string $key ): mixed {
		return $this->store()->read( $key );
	}

	public function write( string $key, mixed $value, int $expire ): bool {
		return $this->store()->write( $key, $value, $expire );
	}

	public function remove( string $key ): bool {
		return $this->store()->remove( $key );
	}

	public function available(): bool {
		return true;
	}
}
