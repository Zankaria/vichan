<?php

defined('TINYBOARD') or exit;

require_once('inc/functions/interop.php');


class DnsDrivers {
	const DNS_TIMEOUT = 1; // 1 second.


	private static function dns_system($timeout) {
		// Try to impose a very frail timeout https://www.php.net/manual/en/function.gethostbyname.php#118841
		putenv("RES_OPTIONS=retrans:1 retry:1 timeout:{$timeout} attempts:1");

		return new class() implements DnsDriver {
			/**
			 * For the love of god never use this.
			 * https://www.php.net/manual/en/function.gethostbynamel.php#119535
			 */
			public function name_to_ips($name) {
				// Add a trailing dot to not return the loopback address on failure
				// https://www.php.net/manual/en/function.gethostbynamel.php#119535
				$ret = gethostbynamel("{$name}.");
				if (is_array($ret)) {
					return $ret;
				}
				return false;
			}

			/**
			 * For the love of god never use this.
			 * https://www.php.net/manual/en/function.gethostbyaddr.php#57553
			 */
			public function ip_to_name($ip) {
				$ret = gethostbyaddr($ip);
				if ($ret === $ip) {
					return false;
				}
				// Case extravaganza: https://www.php.net/manual/en/function.gethostbyaddr.php#123563
				return strtolower($ret);
			}
		};
	}

	private static function host($timeout) {
		return new class($timeout) implements DnsDriver {
			private $timeout;

			private static function match_or($pattern, $subject, $default) {
				$ret = preg_match_all($pattern, $subject, $out);
				if ($ret === false || $ret === 0) {
					return $default;
				}
				return $out[1];
			}

			function __construct($timeout) {
				$this->timeout = $timeout;
			}

			public function name_to_ips($name) {
				$ret = shell_exec_error("host -W {$this->timeout} {$name}");
				if ($ret === false) {
					return false;
				}

				$ipv4 = self::match_or('/has address ([^\s]+)$/', $ret, array());
				$ipv6 = self::match_or('/has IPv6 address ([^\s]+)$/', $ret, array());
				$all_ip = array_merge($ipv4, $ipv6);
				
				if (empty($all_ip)) {
					return false;
				}
				return $all_ip;
			}

			public function ip_to_name($ip) {
				$ret = shell_exec_error("host -W {$this->timeout} {$ip}");
				if ($ret === false) {
					return false;
				}

				$name = self::match_or('/domain name pointer ([^\s]+)$/', $ret, false);
				if ($name === false) {
					return false;
				}

				return rtrim($name, '.');
			}
		};
	}

	/**
	 * Get the configured DNS driver.
	 *
	 * @param array $config Configuration array.
	 * @return DnsDriver Returns the configured driver.
	 */
	public static function get_dns_driver(&$config) {
		if ($config['dns_system']) {
			return self::dns_system(self::DNS_TIMEOUT);
		} else {
			return self::host(self::DNS_TIMEOUT);
		}
	}
}

interface DnsDriver {
	/**
	 * Resolve a domain name to 1 or more ips.
	 *
	 * @param string $name Domain name.
	 * @return array|false Returns an array of IPv4 and IPv6 addresses or false on error.
	 */
	public function name_to_ips($name);

	/**
	 * Resolve an ip address to a domain name.
	 *
	 * @param string $ip Ip address.
	 * @return string|false Returns the domain name or false on error.
	 */
	public function ip_to_name($ip);
}
