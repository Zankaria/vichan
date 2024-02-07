<?php
namespace Vichan\Driver;

defined('TINYBOARD') or exit;


class DnsDrivers {
	public static function osResolver(int $timeout): DnsDriver {
		// Try to impose a very frail timeout https://www.php.net/manual/en/function.gethostbyname.php#118841
		putenv("RES_OPTIONS=retrans:1 retry:1 timeout:{$timeout} attempts:1");

		return new class implements DnsDriver {
			/**
			 * For the love of god never use this.
			 * https://www.php.net/manual/en/function.gethostbynamel.php#119535
			 */
			public function nameToIPs(string $name): array|false {
				// Add a trailing dot to not return the loopback address on failure
				// https://www.php.net/manual/en/function.gethostbynamel.php#119535
				$ret = gethostbynamel("{$name}.");
				if ($ret === false) {
					return false;
				}
				return $ret;
			}

			/**
			 * For the love of god never use this.
			 * https://www.php.net/manual/en/function.gethostbyaddr.php#57553
			 */
			public function IPToNames(string $ip): array|false {
				$ret = gethostbyaddr($ip);
				if ($ret === $ip) {
					return false;
				}
				// Case extravaganza: https://www.php.net/manual/en/function.gethostbyaddr.php#123563
				return [ strtolower($ret) ];
			}
		};
	}

	public static function host(int $timeout) {
		return new class($timeout) implements DnsDriver {
			private int $timeout;

			private static function matchOr(string $pattern, string $subject, mixed $default): array {
				$ret = preg_match_all($pattern, $subject, $out);
				if ($ret === false || $ret === 0) {
					return $default;
				}
				return $out[0];
			}

			public function __construct(int $timeout) {
				$this->timeout = $timeout;
			}

			public function nameToIPs(string $name): array|false {
				$ret = shell_exec_error("host -W {$this->timeout} {$name}");
				if ($ret === false) {
					return false;
				}

				$ipv4 = self::matchOr('/has address ([^\s]+)$/', $ret, []);
				$ipv6 = self::matchOr('/has IPv6 address ([^\s]+)$/', $ret, []);
				$all_ip = array_merge($ipv4, $ipv6);

				if (empty($all_ip)) {
					return false;
				}
				return $all_ip;
			}

			public function IPToNames(string $ip): array|false {
				$ret = shell_exec_error("host -W {$this->timeout} {$ip}");
				if ($ret === false) {
					return false;
				}

				$names = self::matchOr('/domain name pointer ([^\s]+)$/', $ret, false);
				if ($names === false) {
					return false;
				}

				return array_map(fn($n) => rtrim($n, '.'), $names);
			}
		};
	}
}

interface DnsDriver {
	/**
	 * Resolve a domain name to 1 or more ips.
	 *
	 * @param string $name Domain name.
	 * @return array|false Returns an array of IPv4 and IPv6 addresses or false on error.
	 */
	public function nameToIPs(string $name): array|false;

	/**
	 * Resolve an ip address to a domain name.
	 *
	 * @param string $ip Ip address.
	 * @return array|false Returns the domain names or false on error.
	 */
	public function IPToNames(string $ip): array|false;
}
