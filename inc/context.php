<?php
namespace Vichan;

use Vichan\Driver\{CacheDriver, CacheDrivers, HttpDriver, HttpDrivers, Log, LogDrivers};

defined('TINYBOARD') or exit;


interface DependencyFactory {
	public function buildLogDriver(): Log;
	public function buildCacheDriver(): CacheDriver;
	public function buildHttpDriver(): HttpDriver;
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

	public function buildHttpDriver(): HttpDriver {
		return HttpDrivers::getHttpDriver(
			$this->config['upload_by_url_timeout'],
			$this->config['max_filesize']
		);
	}
}

class Context {
	private DependencyFactory $factory;
	private ?Log $log;
	private ?HttpDriver $http;


	public function __construct(DependencyFactory $factory) {
		$this->factory = $factory;
	}

	public function getLog(): Log {
		return $this->log ??= $this->factory->buildLogDriver();
	}

	public function getHttpDriver(): HttpDriver {
		return $this->http ??= $this->factory->buildHttpDriver();
	}
}
