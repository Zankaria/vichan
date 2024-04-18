<?php

defined('TINYBOARD') or exit;

/**
 * PHP has no nested or private classes support.
 */
class CacheDrivers {
	public static function mockery(): CacheDriver {
		/**
		 * A proxy to the older cache class implementation.
		 */
		return new class implements CacheDriver {
			public function get(string $key): mixed {
				$ret = \Cache::get($key);
				if ($ret === false) {
					return null;
				}
				return $ret;
			}

			public function set(string $key, mixed $value, mixed $expires = false): void {
				\Cache::set($key, $value, $expires);
			}

			public function delete(string $key): void {
				\Cache::delete($key);
			}

			public function flush(): void {
				\Cache::flush();
			}
		};
	}
}

interface CacheDriver {
	/**
	 * Get the value of associated with the key.
	 *
	 * @param string $key The key of the value.
	 * @return mixed|null The value associated with the key, or null if there is none.
	 */
	public function get(string $key): mixed;

	/**
	 * Set a key-value pair.
	 *
	 * @param string $key The key.
	 * @param mixed $value The value.
	 * @param int|bool $expires After how many seconds the pair will expire. Use false or ignore this parameter to use the
	 *                      default global config behavior. Some drivers will always ignore this parameter and store the
	 *                      pair until it's removed.
	 */
	public function set(string $key, mixed $value, int|false $expires = false): void;

	/**
	 * Delete a key-value pair.
	 *
	 * @param string $key The key.
	 */
	public function delete(string $key): void;

	/**
	 * Delete all the key-value pairs.
	 */
	public function flush(): void;
}
