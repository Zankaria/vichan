<?php
namespace Vichan;

use Vichan\Driver\{CacheDriver, CacheDrivers, DnsDriver, DnsDrivers, HttpDriver, HttpDrivers, Log, LogDrivers};
use Vichan\Service\DnsQueries;

defined('TINYBOARD') or exit;


interface DependencyFactory {
	public function buildLogDriver(): Log;
	public function buildCacheDriver(): CacheDriver;
	public function buildDnsDriver(): DnsDriver;
	public function buildHttpDriver(): HttpDriver;
	public function buildDnsQueries(DnsDriver $resolver, CacheDriver $cache): DnsQueries;
}

class WebDependencyFactory implements DependencyFactory {
	private array $config;


	public function __construct(array $config) {
		$this->config = $config;
	}

	public function buildLogDriver(): Log {
		$name = $this->config['log_system']['name'];
		$level = $this->config['debug'] ? Log::DEBUG : Log::NOTICE;
		$backend = $this->config['log_system']['type'];

		// Check 'syslog' for backwards compatibility.
		if ((isset($this->config['syslog']) && $this->config['syslog']) || $backend === 'syslog') {
			return LogDrivers::syslog($name, $level, $this->config['log_system']['syslog_stderr']);
		} elseif ($backend === 'file') {
			return LogDrivers::file($name, $level, $this->config['log_system']['file_path']);
		} elseif ($backend === 'stderr') {
			return LogDrivers::stderr($name, $level);
		} elseif ($backend === 'none') {
			return LogDrivers::none();
		} else {
			return LogDrivers::error_log($name, $level);
		}
	}

	public function buildCacheDriver(): CacheDriver {
		return CacheDrivers::mockery();
	}

	public function buildDnsDriver(): DnsDriver {
		if ($this->config['dns_system']) {
			return DnsDrivers::osResolver(1);
		} else {
			return DnsDrivers::host(1);
		}
	}

	public function buildHttpDriver(): HttpDriver {
		return HttpDrivers::getHttpDriver(
			$this->config['upload_by_url_timeout'],
			$this->config['max_filesize']
		);
	}

	public function buildDnsQueries(DnsDriver $resolver, CacheDriver $cache): DnsQueries {
		return new DnsQueries(
			$resolver,
			$cache,
			$this->config['dnsbl'],
			$this->config['dnsbl_exceptions'],
			$this->config['fcrdns']
		);
	}
}

class Context {
	private DependencyFactory $factory;
	private ?Log $log;
	private ?CacheDriver $cache;
	private ?DnsDriver $resolver;
	private ?HttpDriver $http;
	private ?DnsQueries $dnsQueries;


	public function __construct(DependencyFactory $factory) {
		$this->factory = $factory;
	}

	public function getLog(): Log {
		return $this->log ??= $this->factory->buildLogDriver();
	}

	public function getCacheDriver(): CacheDriver {
		return $this->cache ??= $this->factory->buildCacheDriver();
	}

	public function getDnsDriver(): DnsDriver {
		return $this->resolver ??= $this->factory->buildDnsDriver();
	}

	public function getHttpDriver(): HttpDriver {
		return $this->http ??= $this->factory->buildHttpDriver();
	}

	public function getDnsQueries(): DnsQueries {
		return $this->dnsQueries ??= $this->factory->buildDnsQueries(
			$this->getDnsDriver(),
			$this->getCacheDriver()
		);
	}
}
