<?php // Geoip wrapper

require_once('vendor/autoload.php');


class GeoIPQueries {
	private $inner;

	private static function ipv4to6($ip) {
		if (strpos($ip, ':') !== false) {
			if (strpos($ip, '.') <= 0) {
				// Native ipv6.
				return $ip;
			}
			$ip = substr($ip, strrpos($ip, ':') + 1);
		}
		$iparr = array_pad(explode('.', $ip), 4, 0);
		$part7 = base_convert(($iparr[0] * 256) + $iparr[1], 10, 16);
		$part8 = base_convert(($iparr[2] * 256) + $iparr[3], 10, 16);
		return '::ffff:'.$part7.':'.$part8;
	}

	function __construct() {
		$this->inner = geoip_open('inc/lib/geoip/GeoIPv6.dat', GEOIP_STANDARD);
	}

	function __destruct() {
		geoip_close($this->inner);
	}

	/**
	 * @param string $ip IP address to resolve. Can be either IPv4 or IPv6.
	 * @return string|false Returns the ip's country code on success, null on failure.
	 */
	public function resolve_flag_any_ip($ip) {
		$country_code = geoip_country_code_by_addr_v6($this->inner, self::ipv4to6($ip));
		if ($country_code !== false) {
			$country_code = strtolower($country_code);
			if (!in_array($country_code, array('eu', 'ap', 'o1', 'a1', 'a2'))) {
				return $country_code;
			}
		}
		return false;
	}
}