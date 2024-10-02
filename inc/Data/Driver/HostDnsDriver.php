<?php
namespace Vichan\Data\Driver;

defined('TINYBOARD') or exit;


/**
 * Relies on the `host` command line executable.
 */
class HostDnsDriver implements DnsDriver {
	private int $timeout;

	private static function matchOr(string $pattern, string $subject, mixed $default): array {
		$ret = \preg_match_all($pattern, $subject, $out);
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
		$all_ip = \array_merge($ipv4, $ipv6);

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

		return \array_map(fn($n) => \rtrim($n, '.'), $names);
	}
}
