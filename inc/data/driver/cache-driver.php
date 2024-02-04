<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

defined('TINYBOARD') or exit;

/**
 * PHP has no nested or private classes support.
 */
class CacheDrivers {
	private static function memcached($prefix, $default_timeout, $memcached_server) {
		$memcached = new Memcached();
		$memcached->addServers($memcached_server);

		return new class($prefix, $default_timeout, $memcached) implements CacheDriver {
			private $prefix;
			private $default_timeout;
			private $inner;

			function __construct($prefix, $default_timeout, $inner) {
				$this->prefix = $prefix;
				$this->default_timeout = $default_timeout;
				$this->inner = $inner;
			}

			public function get($key) {
				return $this->inner->get($this->prefix . $key);
			}

			public function set($key, $value, $expires = false) {
				if (!$expires) {
					$expires = $this->default_timeout;
				}
				$this->inner->set($this->prefix . $key, $value, $expires);
			}

			public function delete($key) {
				$this->inner->delete($this->prefix . $key);
			}

			public function flush() {
				$this->inner->flush();
			}
		};
	}

	private static function redis($prefix, $default_timeout, $host, $port, $password, $database) {
		$redis = new Redis();
		$redis->connect($host, $port);
		if ($password) {
			$redis->auth($password);
		}
		if (!$redis->select($database)) {
			return false;
		}

		return new class($prefix, $default_timeout, $redis) implements CacheDriver {
			private $prefix;
			private $default_timeout;
			private $inner;

			function __construct($prefix, $default_timeout, $inner) {
				$$this->prefix = $prefix;
				$this->default_timeout = $default_timeout;
				$this->inner = $inner;
			}

			public function get($key) {
				return json_decode($this->inner->get($this->prefix . $key), true);
			}

			public function set($key, $value, $expires = false) {
				if (!$expires) {
					$expires = $this->default_timeout;
				}
				$this->inner->setex($this->prefix . $key, $expires, json_encode($value));
			}

			public function delete($key) {
				$this->inner->del($this->prefix . $key);
			}

			public function flush() {
				$this->inner->flushDB();
			}
		};
	}

	private static function apcu($default_timeout) {
		return new class($default_timeout) implements CacheDriver {
			private $default_timeout;

			function __construct($default_timeout) {
				$this->default_timeout = $default_timeout;
			}

			public function get($key) {
				return apcu_fetch($key);
			}

			public function set($key, $value, $expires = false) {
				if (!$expires) {
					$expires = $this->default_timeout;
				}
				apcu_store($key, $value, $expires);
			}

			public function delete($key) {
				apcu_delete($key);
			}

			public function flush() {
				apcu_clear_cache('user');
			}
		};
	}

	private static function filesystem($prefix, $base_path) {
		$base_path = rtrim($base_path, '/');

		if (!is_dir($base_path) || is_writable($base_path)) {
			return false;
		}

		return new class($prefix, $base_path) implements CacheDriver {
			private $prefix;
			private $base_path;

			function __construct($prefix, $base_path) {
				$this->prefix = $prefix;
				$this->base_path = $base_path;
			}

			public function get($key) {
				$key = str_replace('/', '::', $key);
				$key = str_replace("\0", '', $key);
				$key = $this->prefix . $key;

				if (!file_exists("{$this->base_path}/{$key}")) {
					return false;
				}

				$data = file_get_contents('tmp/cache/'.$key);
				return json_decode($data, true);
			}

			public function set($key, $value, $expires = false) {
				$key = str_replace('/', '::', $key);
				$key = str_replace("\0", '', $key);
				$key = $this->prefix . $key;

				$data = json_encode($value);
				file_put_contents("{$this->base_path}/{$key}", $data);
			}

			public function delete($key) {
				$key = str_replace('/', '::', $key);
				$key = str_replace("\0", '', $key);
				$key = $this->prefix . $key;

				@unlink("{$this->base_path}/{$key}");
			}

			public function flush() {
				$files = glob("{$this->base_path}/*");
				foreach ($files as $file) {
					@unlink($file);
				}
			}
		};
	}

	private static function php_array() {
		return new class() implements CacheDriver {
			private static $inner = array();

			public function get($key) {
				return isset(self::$inner[$key]) ? self::$inner[$key] : false;
			}

			public function set($key, $value, $expires = false) {
				self::$inner[$key] = $value;
			}

			public function delete($key) {
				unset(self::$inner[$key]);
			}

			public function flush() {
				self::$inner = array();
			}
		};
	}

	/**
	 * No-op cache. Useful for testing.
	 */
	public static function none() {
		return new class() implements CacheDriver {
			public function get($key) {
				return false;
			}

			public function set($key, $value, $expires = false) {
				// No-op.
			}

			public function delete($key) {
				// No-op.
			}

			public function flush() {
				// No-op.
			}
		};
	}

	/**
	 * Get the configured cache driver.
	 *
	 * @param array $config The configuration array.
	 * @return CacheDriver|false Returns the configured driver or false on error.
	 */
	public static function get_cache_driver(&$config) {
		switch ($config['cache']['enabled']) {
			case 'memcached':
				return self::memcached(
					$config['cache']['prefix'],
					$config['cache']['timeout'],
					$config['cache']['memcached']
				);
			case 'redis':
				return self::redis(
					$config['cache']['prefix'],
					$config['cache']['timeout'],
					$config['cache']['redis'][0],
					$config['cache']['redis'][1],
					$config['cache']['redis'][2],
					$config['cache']['redis'][3]
				);
			case 'apcu':
				return self::apcu($config['cache']['timeout']);
			case 'fs':
				return self::filesystem(
					$config['cache']['prefix'],
					"/tmp/cache/{$config['cache']['prefix']}"
				);
			case 'php':
				return self::php_array();
			default:
				return self::none();
		}
	}
}

interface CacheDriver {
	/**
	 * Get the value of associated with the key.
	 *
	 * @param string $key The key of the value.
	 * @return mixed|false The value associated with the key, or false if there is none.
	 */
	public function get($key);

	/**
	 * Set a key-value pair.
	 *
	 * @param string $key The key.
	 * @param mixed $value The value.
	 * @param int|bool $expires After how many seconds the pair will expire. Use false or ignore this parameter to use the
	 *                      default global config behavior. Some drivers will always ignore this parameter and store the
	 *                      pair until it's removed.
	 */
	public function set($key, $value, $expires = false);

	/**
	 * Delete a key-value pair.
	 *
	 * @param string $key The key.
	 */
	public function delete($key);

	/**
	 * Delete all the key-value pairs.
	 */
	public function flush();
}
