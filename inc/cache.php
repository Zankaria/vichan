<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

defined('TINYBOARD') or exit;

require_once('inc/data/cache-provider.php');
require_once('inc/data/twig-cache.php');

class Cache {
	private static $cache;

	public static function init() {
		global $config;
		if (!self::$cache) {
			self::$cache = CacheProvider::get_cache($config);
		}
	}

	public static function get($key) {
		global $config, $debug;
		self::init();
		$data = self::$cache->get($key);

		if ($config['debug']) {
			$debug['cached'][] = $key . ($data === false ? ' (miss)' : ' (hit)');
		}

		return $data;
	}

	public static function set($key, $value, $expires = false) {
		global $config, $debug;
		self::init();
		self::$cache->set($key, $value, $expires);

		if ($config['debug']) {
			$debug['cached'][] = $key . ' (set)';
		}
	}

	public static function delete($key) {
		global $config, $debug;
		self::init();
		self::$cache->delete($key);

		if ($config['debug']) {
			$debug['cached'][] = $key . ' (deleted)';
		}
	}
	public static function flush() {
		global $config;
		self::init();
		self::$cache->flush();

		return false;
	}
}
