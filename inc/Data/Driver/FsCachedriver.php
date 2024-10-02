<?php
namespace Vichan\Data\Driver;

defined('TINYBOARD') or exit;


class FsCacheDriver implements CacheDriver {
	private string $prefix;
	private string $base_path;
	private mixed $lock_fd;
	private int|false $collect_chance_den;


	private function prepareKey(string $key): string {
		$key = \str_replace('/', '::', $key);
		$key = \str_replace("\0", '', $key);
		return $this->prefix . $key;
	}

	private function sharedLockCache(): void {
		\flock($this->lock_fd, LOCK_SH);
	}

	private function exclusiveLockCache(): void {
		\flock($this->lock_fd, LOCK_EX);
	}

	private function unlockCache(): void {
		\flock($this->lock_fd, LOCK_UN);
	}

	private function collectImpl(): int {
		// A read lock is ok, since it's alright if we delete expired items from under the feet of other processes.
		$files = \glob($this->base_path . $this->prefix . '*', GLOB_NOSORT);
		$count = 0;
		foreach ($files as $file) {
			$data = \file_get_contents($file);
			$wrapped = \json_decode($data, true, 512, JSON_THROW_ON_ERROR);
			if ($wrapped['expires'] !== false && $wrapped['expires'] <= time()) {
				if (@\unlink($file)) {
					$count++;
				}
			}
		}
		return $count;
	}

	private function maybeCollect() {
		if ($this->collect_chance_den !== false && \rand(0, $this->collect_chance_den - 1) === 0) {
			$this->collect_chance_den = false; // Collect only once per instance (aka process).
			$this->collectImpl();
		}
	}

	public function __construct(string $prefix, string $base_path, string $lock_file, int|false $collect_chance_den) {
		if ($base_path[\strlen($base_path) - 1] !== '/') {
			$base_path = "$base_path/";
		}

		if (!\is_dir($base_path)) {
			throw new \RuntimeException("$base_path is not a directory!");
		}

		if (!\is_writable($base_path)) {
			throw new \RuntimeException("$base_path is not writable!");
		}

		$this->lock_fd = \fopen($base_path . $lock_file, 'w');
		if ($this->lock_fd === false) {
			throw new \RuntimeException('Unable to open the lock file!');
		}
		register_shutdown_function([$this, 'close']);

		$this->prefix = $prefix;
		$this->base_path = $base_path;
		$this->collect_chance_den = $collect_chance_den;
	}

	public function get(string $key): mixed {
		$key = $this->prepareKey($key);

		$this->sharedLockCache();

		$fd = \fopen($this->base_path . $key, 'r');
		if ($fd === false) {
			$this->unlockCache();
			return null;
		}

		$data = \stream_get_contents($fd);
		\fclose($fd);
		$this->maybeCollect();
		$this->unlockCache();
		$wrapped = \json_decode($data, true, 512, JSON_THROW_ON_ERROR);

		if ($wrapped['expires'] !== false && $wrapped['expires'] <= \time()) {
			// Already, expired, pretend it doesn't exist.
			return null;
		} else {
			return $wrapped['inner'];
		}
	}

	public function set(string $key, mixed $value, mixed $expires = false): void {
		$key = $this->prepareKey($key);

		$wrapped = [
			'expires' => $expires ? \time() + $expires : false,
			'inner' => $value
		];

		$data = \json_encode($wrapped);
		$this->exclusiveLockCache();
		\file_put_contents($this->base_path . $key, $data);
		$this->maybeCollect();
		$this->unlockCache();
	}

	public function delete(string $key): void {
		$key = $this->prepareKey($key);

		$this->exclusiveLockCache();
		@\unlink($this->base_path . $key);
		$this->maybeCollect();
		$this->unlockCache();
	}

	public function collect() {
		$this->sharedLockCache();
		$count = $this->collectImpl();
		$this->unlockCache();
		return $count;
	}

	public function flush(): void {
		$this->exclusiveLockCache();
		$files = \glob($this->base_path . $this->prefix . '*', GLOB_NOSORT);
		foreach ($files as $file) {
			@\unlink($file);
		}
		$this->unlockCache();
	}

	public function close() {
		\fclose($this->lock_fd);
	}
}
