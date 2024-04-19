<?php
namespace Vichan\Service;

use InvalidArgumentException;
use Vichan\Driver\{DnsDriver, CacheDriver};
use Lifo\IP\IP;

defined('TINYBOARD') or exit;


class DnsQueries {
	private const DNS_CACHE_TIMEOUT = 60 * 15; // 15 minutes.

	// Can't store booleans in the cache, since false is used to report a cache-miss.
	private const CACHE_FALSE = 0x00;
	private const CACHE_TRUE = 0x01;

	private DnsDriver $resolver;
	private CacheDriver $cache;
	private array $blacklist_providers;
	private array $exceptions;
	private bool $rdns_validate;


	private static function reverseIPv4Octets(string $ip): string|false {
		$ret = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
		if ($ret === false) {
			return false;
		}
		return implode('.', array_reverse(explode('.', $ip)));
	}

	private static function reverseIPv6Octets(string $ip): string|false {
		$ret = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
		if ($ret === false) {
			return false;
		}
		return strrev(implode(".", str_split(str_replace(':', '', IP::inet_expand($ip)))));
	}

	/**
	 * Builds the name/host to resolve to discover if an ip is the host.
	 */
	private static function buildEndpoint(string $host, string $ip) {
		$replaced = 0;
		$lookup = str_replace('%', $ip, $host, $replaced);
		if ($replaced === 0) {
			$lookup = "$ip.$host";
		}
		return $lookup;
	}

	private static function filterIp(string $str): string|false {
		return filter_var($str, FILTER_VALIDATE_IP);
	}

	private function isIPWhitelisted(string $ip): bool {
		if (in_array($ip, $this->exceptions)) {
			return true;
		}

		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
			return true;
		}

		return false;
	}

	private function isIPBlacklisted(string $ip, string $rip): bool {
		foreach ($this->blacklist_providers as $blacklist) {
			$blacklist_host = $blacklist;
			if (is_array($blacklist)) {
				$blacklist_host = $blacklist[0];
			}

			// The name that will be looked up.
			$name = self::buildEndpoint($blacklist_host, $rip);

			// Do the actual check.
			$is_blacklisted = $this->checkNameResolves($name);

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

	private function checkNameResolves($name): bool {
		$value = $this->cache->get("dns_queries_dns_$name");
		if ($value === null) {
			$value = (bool)$this->resolver->nameToIPs($name);
			$serialized_value = $value ? self::CACHE_TRUE : self::CACHE_FALSE;
			$this->cache->set("dns_queries_dns_$name", $serialized_value, self::DNS_CACHE_TIMEOUT);
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
	 * @param bool $rdns_validate Validate Reverse DNS queries results.
	 */
	public function __construct(DnsDriver $resolver, CacheDriver $cache, array $blacklist_providers, array $exceptions, bool $rdns_validate) {
		$this->resolver = $resolver;
		$this->cache = $cache;
		$this->blacklist_providers = $blacklist_providers;
		$this->exceptions = $exceptions;
		$this->rdns_validate = $rdns_validate;
	}

	/**
	 * Is the given IP known to a blacklist?
	 * Documentation: https://github.com/vichan-devel/vichan/wiki/dnsbl
	 *
	 * @param string $ip The ip to lookup.
	 * @return bool Returns true if the IP is a in known blacklist.
	 * @throws InvalidArgumentException Throws if $ip is not a valid IPv4 or IPv6 address.
	 */
	public function isSpamIP($ip): bool {
		$rip = false;
		$ret = self::reverseIPv4Octets($ip);
		if ($ret !== false) {
			$rip = $ret;
		}
		$ret = self::reverseIPv6Octets($ip);
		if ($ret !== false) {
			$rip = $ret;
		}

		if ($rip === false) {
			throw new InvalidArgumentException("$ip is not a valid ip address.");
		}

		if ($this->isIPWhitelisted($ip)) {
			return false;
		}

		return $this->isIPBlacklisted($ip, $rip);
	}

	/**
	 * Performs the Reverse DNS lookup (rDNS) of the given IP.
	 * This function can be slow since may validate the response.
	 *
	 * @param string $ip The ip to lookup.
	 * @return array|false The hostnames of the given ip.
	 * @throws InvalidArgumentException Throws if $ip is not a valid IPv4 or IPv6 address.
	 */
	public function ipToNames(string $ip): array {
		$ret = self::filterIp($ip);
		if ($ret === false) {
			throw new InvalidArgumentException("$ip is not a valid ip address.");
		}

		$names = $this->cache->get("dns_queries_rdns_$ret");
		if ($names !== false) {
			return $names;
		}

		$names = $this->resolver->IPToNames($ret);
		if ($names === false) {
			$this->cache->set("dns_queries_rdns_$ret", [], self::DNS_CACHE_TIMEOUT);
			return [];
		}

		// Do we bother with validating the result?
		if (!$this->rdns_validate) {
			$this->cache->set("dns_queries_rdns_$ret", $names, self::DNS_CACHE_TIMEOUT);
			return $names;
		}

		// Filter out the names that do not resolve to the given ip.
		$acc = [];
		foreach ($names as $name) {
			// Validate the response.
			$resolved_ips = $this->resolver->nameToIPs($name);
			if ($resolved_ips !== false && is_array($ret, $resolved_ips)) {
				$acc[] = $name;
			}
		}

		$this->cache->set("dns_queries_rdns_$ret", $acc, self::DNS_CACHE_TIMEOUT);
		return $acc;
	}
}
