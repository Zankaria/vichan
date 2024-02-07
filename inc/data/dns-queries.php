<?php

defined('TINYBOARD') or exit;

require_once('inc/driver/dns-driver.php');
require_once('inc/driver/cache-driver.php');


class DnsQueries {
	const DNS_CACHE_TIMEOUT = 60 * 15; // 15 minutes.

	// Can't store booleans in the cache, since false is used to report a cache-miss.
	const CACHE_FALSE = 0x00;
	const CACHE_TRUE = 0x01;

	private $resolver;
	private $cache;
	private $blacklist_providers;
	private $exceptions;


	private static function reverse_ip_octets($ip) {
		return implode('.', array_reverse(explode('.', $ip)));
	}

	private static function is_ipv6($ip) {
		return strstr($ip, ':') !== false;
	}

	/**
	 * Builds the name/host to resolve to discover if an ip is the host.
	 */
	private static function build_endpoint($host, $ip) {
		$lookup = str_replace('%', $ip, $host);
		if ($lookup === $host) {
			$lookup = $ip . '.' . $host;
		}
		return $lookup;
	}

	private function check_name_blacklisted($name) {
		$value = $this->cache->get("dns_spam_name_$name");
		if ($value === false) {
			$value = (bool)$this->resolver->name_to_ips($name);
			$serialized_value = $value ? self::CACHE_TRUE : self::CACHE_FALSE;
			$this->cache->set("dns_spam_name_$name", $serialized_value, self::DNS_CACHE_TIMEOUT);
		}
		return $value === self::CACHE_TRUE;
	}


	/**
	 * Build a DNS accessor.
	 *
	 * @param DnsDriver $resolver DNS driver.
	 * @param CacheDriver $cache Cache driver.
	 * @param array $blacklists Array of blacklist providers.
	 * @param array $exceptions Exceptions to the blacklists.
	 */
	function __construct($resolver, $cache, $blacklist_providers, $exceptions) {
		$this->resolver = $resolver;
		$this->cache = $cache;
		$this->blacklist_providers = $blacklist_providers;
		$this->exceptions = $exceptions;
	}

	/**
	 * Is the given IP known to a blacklist?
	 * Documentation: https://github.com/vichan-devel/vichan/wiki/dnsbl
	 *
	 * @param string $ip The ip to lookup.
	 * @return bool Returns true if the IP is a in known blacklist.
	 */
	public function is_spam_ip($ip) {
		if (self::is_ipv6($ip)) {
			return false;
		}

		if (in_array($ip, $this->exceptions)) {
			return false;
		}

		if (preg_match("/^(::(ffff:)?)?(127\.|192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[0-1])\.|0\.|255\.)/", $ip)) {
			// It's pointless to check for local IP addresses in dnsbls, isn't it?
			return false;
		}

		$ip = self::reverse_ip_octets($ip);

		foreach ($this->blacklist_providers as $blacklist) {
			$blacklist_host = $blacklist;
			if (is_array($blacklist)) {
				$blacklist_host = $blacklist[0];
			}

			// The name that will be looked up.
			$name = self::build_endpoint($blacklist_host, $ip);

			// Do the actual check.
			$is_blacklisted = $this->check_name_blacklisted($name);

			if ($is_blacklisted) {
				// Pick the strategy to deal with this blacklisted host.

				if (!isset($blacklist[1])) {
					// Just block them.
					return true;
				} elseif (is_array($blacklist[1])) {
					// Check if the blacklist applies only to some IPs.
					foreach ($blacklist[1] as $octet) {
						if ($ip == $octet || $ip == '127.0.0.' . $octet) {
							return true;
						}
					}
				} elseif (is_callable($blacklist[1])) {
					// Custom user provided function.
					if ($blacklist[1]($ip)) {
						return true;
					}
				} else {
					// Check if the blacklist only applies to a specific IP.
					if ($ip == $blacklist[1] || $ip == '127.0.0.' . $blacklist[1]) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Performs the Reverse DNS lookup (rDNS) of the given IP.
	 * This function can be slow since it always validates the response.
	 *
	 * @param string $ip The ip to lookup.
	 * @return string|false The hostname of the given ip, false if none.
	 */
	public function ip_to_name($ip) {
		$name = $this->cache->get("rdns_$ip");
		if ($name === false) {
			$name = $this->resolver->ip_to_name($ip);
			if ($name === false) {
				return false;
			}

			// Validate the response.
			$resolved_ips = $this->resolver->name_to_ips($name);
			if (!is_array($resolved_ips)) {
				// Could not resolve.
				$this->cache->set("rdns_$ip", self::CACHE_FALSE, self::DNS_CACHE_TIMEOUT);
				return false;
			} else {
				// The name resolves to something.
				foreach ($resolved_ips as $resolved_ip) {
					$this->cache->set("rdns_$resolved_ip", $name, self::DNS_CACHE_TIMEOUT);
				}

				// But does it resolve to the given ip?
				if (!in_array($ip, $resolved_ips)) {
					$this->cache->set("rdns_$ip", self::CACHE_FALSE, self::DNS_CACHE_TIMEOUT);
					return false;
				}
				return $name;
			}
		} elseif ($name === self::CACHE_FALSE) {
			return false;
		} else {
			return $name;
		}
	}
}
