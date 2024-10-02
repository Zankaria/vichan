<?php
namespace Vichan\Driver;

defined('TINYBOARD') or exit;


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
