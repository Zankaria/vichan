<?php // Logging

defined('TINYBOARD') or exit;


class LogDrivers {
	private static function syslog($name, $level, $print_stderr) {
		$flags = LOG_ODELAY;
		if ($print_stderr) {
			$flags |= LOG_PERROR;
		}

		if (!openlog($name, $flags, LOG_USER)) {
			// This is very bad.
			return false;
		}

		return new class($level) implements Log {
			private $level;

			function __construct($level) {
				$this->level = $level;
			}

			public function log($level, $message) {
				if ($level <= $this->level) {
					_syslog($level, $message);
				}
			}
		};
	}

	private static function ini_error_log($name, $level, $print_stderr) {
		return new class($name, $level, $print_stderr) implements Log {
			private $name;
			private $level;
			private $print_stderr;

			function __construct($name, $level, $print_stderr) {
				$this->name = $name;
				$this->level = $level;
				$this->print_stderr = $print_stderr;
			}

			public function log($level, $message) {
				if ($level <= $this->level) {
					$line = "{$this->name}: {$message}";
					if ($this->print_stderr) {
						error_log("{$line}\n", 3, '/dev/stderr', null);
					}
					error_log($line, 0, null, null);
				}
			}
		};
	}

	private static function file($name, $level, $print_stderr, $file_path) {
		return new class($name, $level, $print_stderr, $file_path) implements Log {
			private $name;
			private $level;
			private $print_stderr;
			private $file_path;

			function __construct($name, $level, $print_stderr, $file_path) {
				$this->name = $name;
				$this->level = $level;
				$this->print_stderr = $print_stderr;
				$this->file_path = $file_path;
			}

			public function log($level, $message) {
				if ($level <= $this->level) {
					$line = "{$this->name}: {$message}\n";
					if ($this->print_stderr) {
						error_log($line, 3, '/dev/stderr', null);
					}
					error_log($line, 3, $this->file_path, null);
				}
			}
		};
	}

	/**
	 * No-op logging system. Useful for testing.
	 */
	public static function none() {
		return new class() implements Log {
			public function log($level, $message) {
				// No-op.
			}
		};
	}

	/**
	 * Obtain a logging system.
	 *
	 * @param array $config Configuration array.
	 * @return Log|false Returns a logger or false on error.
	 */
	public static function get_logger(&$config) {
		$name = $config['log_system_name'];

		if ($config['debug']) {
			$level = Log::DEBUG;
		} else {
			$level = Log::NOTICE;
		}

		$print_stderr = php_sapi_name() == 'cli';

		// Check 'syslog' for backwards compatibility.
		if ($config['syslog'] || (isset($config['log_system']) && $config['log_system'] === 'syslog')) {
			return self::syslog($name, $level, $print_stderr);
		} else if (isset($config['log_system']) && $config['log_system'] === 'ini_error_log') {
			return self::ini_error_log($name, $level, $print_stderr);
		} else if (isset($config['log_system']) && $config['log_system'] === 'file') {
			if (!isset($config['log_system_file_path'])) {
				return false;
			}
			return self::file($name, $level, $print_stderr, $config['log_system_file_path']);
		} else {
			return self::none();
		}
	}
}

interface Log {
	public const EMERG = LOG_EMERG;
	public const ERROR = LOG_ERR;
	public const WARNING = LOG_WARNING;
	public const NOTICE = LOG_NOTICE;
	public const INFO = LOG_INFO;
	public const DEBUG = LOG_DEBUG;

	/**
	 * Log a message if the level of irrelevancy is bellow the maximum.
	 *
	 * @param int $level Message level. Use Log interface constants.
	 * @param string $message The message to log.
	 */
	public function log($level, $message);
}
