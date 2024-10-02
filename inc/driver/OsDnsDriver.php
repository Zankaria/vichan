<?php
namespace Vichan\Driver;

defined('TINYBOARD') or exit;


/**
 * For the love of god never use this implementation if you can.
 */
class OsDnsDriver implements DnsDriver {
	public function __construct(int $timeout) {
		// Try to impose a very frail timeout https://www.php.net/manual/en/function.gethostbyname.php#118841
		\putenv("RES_OPTIONS=retrans:1 retry:1 timeout:{$timeout} attempts:1");
	}

	/**
	 * For the love of god never use this.
	 * https://www.php.net/manual/en/function.gethostbynamel.php#119535
	 */
	public function nameToIPs(string $name): array|false {
		// Add a trailing dot to not return the loopback address on failure
		// https://www.php.net/manual/en/function.gethostbynamel.php#119535
		$ret = \gethostbynamel("{$name}.");
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
		$ret = \gethostbyaddr($ip);
		if ($ret === $ip) {
			return false;
		}
		// Case extravaganza: https://www.php.net/manual/en/function.gethostbyaddr.php#123563
		return [ \strtolower($ret) ];
	}
}
