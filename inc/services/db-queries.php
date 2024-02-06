<?php // Wraps data access with cache

defined('TINYBOARD') or exit;

require_once('inc/data/driver/cache-driver.php');


class DbQueries {
	/**
	 * Implementor notes: this class should only throw exceptions if there's an error with doing the query.
	 * Empty results should be represented with false or an empty array.
	 */
	const BAN_INFO_TIMEOUT = 60 * 5; // 5 minutes.
	const BOARD_INFO_TIMEOUT = 60 * 10; // 10 minutes.
	const THREAD_INFO_TIMEOUT = 60 * 2; // 2 minutes.

	/**
	 * MySQL error code for "table not found"
	 * https://dev.mysql.com/doc/mysql-errors/8.0/en/server-error-reference.html#error_er_bad_table_error
	 */
	const MYSQL_ERR_BAD_TABLE = '42S02';
	// Cannot store the false boolean in the cache.
	const CACHE_FALSE = 0x00;

	private $pdo;
	private $cache;


	/**
	 * Since the PDO may be shared with legacy code, it's not configured to throw PDOExceptions.
	 * This function fixes that.
	 */
	private static function execute_or_throw($query) {
		if (!$query->execute()) {
			throw new RuntimeException($query->errorInfo()[1]);
		}
	}

	/**
	 * Since the PDO may be shared with legacy code, it's not configured to throw PDOExceptions.
	 * This function fixes that, and also deals with vichan boards not being column values but different tables.
	 * If the execution fails because the table does not exist, this function returns false.
	 */
	private static function execute_or_fail_or_throw($query) {
		if (!$query->execute()) {
			$info = $query->errorInfo();
			$sqlErrorCode = $info[0];

			if ($sqlErrorCode == self::MYSQL_ERR_BAD_TABLE) {
				return false;
			}
			throw new RuntimeException($info[1]);
		}
		return true;
	}

	/**
	 * Before PHP 8, PDOStatement::fetchAll may return false on "failure" (no, nowhere I found what this failure
	 * actually means or entails).
	 * This function wraps the call and throws and exception on failure.
	 * @param PDOStatement $query PDO query that has already been executed.
	 * @param int $mode The fetch mode. Defaults to FETCH_BOTH since it was the default from php 7 through 8.0.6, and
	 * 					is still the implicit default to this day
	 * 					 - https://web.archive.org/web/20160114105940/https://www.php.net/manual/en/pdostatement.fetch.php
	 * 					 - https://web.archive.org/web/20210305021241/https://www.php.net/manual/en/pdostatement.fetch.php
	 */
	private static function fetch_all_or_throw($query, $mode = PDO::FETCH_BOTH) {
		$ret = $query->fetchAll($mode);
		// May return false on failure on PHP < 8.0.0.
		if ($ret === false) {
			throw new RuntimeException("fetchAll failed");
		}
		return $ret;
	}

	private function cached_or($key, $expire, $get) {
		$value = $this->cache->get($key);
		if ($value !== false) {
			return $value;
		}

		$value = $get();
		if ($value === false) {
			return false;
		}

		$this->cache->set($key, $value, $expire);
		return $value;
	}

	/**
	 * Loads all the board data, ordered by uri.
	 * Usually the number of boards is relatively low, so the query shouldn't be too costly in any case, while the cost
	 * will be evened out by caching the data that is required by various functions.
	 */
	private function get_boards_ordered() {
		return $this->cached_or('boards_all_ordered', self::BOARD_INFO_TIMEOUT, function() {
			$query = $this->pdo->prepare("SELECT * FROM ``boards`` ORDER BY `uri`");
			self::execute_or_throw($query);
			return self::fetch_all_or_throw($query);
		});
	}

	/**
	 * Construct a new data access instance.
	 *
	 * @param PDO $pdo A MySQL PDO.
	 * @param CacheDriver $cache A cache driver.
	 */
	function __construct($pdo, $cache) {
		$this->pdo = $pdo;
		$this->cache = $cache;
	}

	/**
	 * Get information about a board.
	 *
	 * @param string $uri The interested board uri.
	 * @return array|false An array with the board's data or false if the board does not exist.
	 * @throws RuntimeException Throws on error.
	 */
	public function get_board_info($uri) {
		$boards = $this->get_boards_ordered();
		if ($boards) {
			foreach ($boards as $board) {
				if ($board['uri'] === $uri) {
					return $board;
				}
			}
		}
		return false;
	}

	/**
	 * Get the title of a board.
	 *
	 * @param string $uri The interested board uri.
	 * @return string|false An the board's title or false if the board does not exist.
	 * @throws RuntimeException Throws on error.
	 */
	public function get_board_title($uri) {
		$boards = $this->get_boards_ordered();
		if ($boards) {
			foreach ($boards as $board) {
				if ($board['uri'] === $uri) {
					return $board['title'];
				}
			}
		}
		return false;
	}

	/**
	 * @return array A list of the boards.
	 * @throws RuntimeException Throws on error.
	 */
	public function get_boards() {
		return $this->get_boards_ordered();
	}

	/**
	 * @return array list of the boards with their uris.
	 * @throws RuntimeException Throws on error.
	 */
	public function get_boards_uris() {
		return array_map(function($arr) { return $arr['uri']; }, $this->get_boards_ordered());
	}

	/**
	 * @return array An array of arrays with themes without names or values.
	 * @throws RuntimeException Throws on error.
	 */
	public function get_themes_empty() {
		return $this->cached_or('themes_empty', false, function() {
			$query = $this->pdo->prepare("SELECT `theme` FROM ``theme_settings`` WHERE `name` IS NULL AND `value` IS NULL");
			self::execute_or_throw($query->execute());
			return self::fetch_all_or_throw($query, PDO::FETCH_ASSOC);
		});
	}

	/**
	 * @return array The settings of the given theme.
	 * @throws RuntimeException Throws on error.
	 */
	public function get_theme_settings($theme) {
		return $this->cached_or("theme_settings_of_$theme", false, function() use ($theme) {
			$query = $this->pdo->prepare("SELECT `name`, `value` FROM ``theme_settings`` WHERE `theme` = :theme AND `name` IS NOT NULL");
			$query->bindValue(':theme', $theme);
			self::execute_or_throw($query);

			$res = array();
			while ($s = $query->fetch(PDO::FETCH_ASSOC)) {
				$res[$s['name']] = $s['value'];
			}
			return $res;
		});
	}

	/**
	 * @param int $id The post id.
	 * @param string $board_uri The board uri. Fails if it doesn't exist.
	 * @return array The files associated with the ban in the board.
	 * @throws RuntimeException Throws on error.
	 */
	public function get_ban_files_of($id, $board_uri) {
		return $this->cached_or("ban_files_in_{$board_uri}_of_{$id}", self::BAN_INFO_TIMEOUT, function() use ($id, $board_uri) {
			$query = $this->pdo->prepare(sprintf("SELECT `files` FROM ``posts_%s`` WHERE `id` = :id", $board_uri));
			$query->bindValue(':id', $id, PDO::PARAM_INT);
			if (!self::execute_or_fail_or_throw($query)) {
				return array();
			}
			return self::fetch_all_or_throw($query, PDO::FETCH_ASSOC);
		});
	}

	/**
	 * @return array
	 * @throws RuntimeException Throws on error.
	 */
	public function get_ban_appeals($id) {
		return $this->cached_or("ban_appeals_of_$id", self::BAN_INFO_TIMEOUT, function() use($id) {
			$query = $this->pdo->prepare("SELECT `time`, `denied` FROM ``ban_appeals`` WHERE `ban_id` = :id");
			$query->bindValue(':id', $id, PDO::PARAM_INT);
			self::execute_or_throw($query);
			return self::fetch_all_or_throw($query, PDO::FETCH_ASSOC);
		});
	}

	/**
	 * @param int $id The post id.
	 * @param string $board_uri The board uri. Fails if it doesn't exist.
	 * @return array|false Returns an array with the thread's data, or false if it does not exist.
	 * @throws RuntimeException Throws on error.
	 */
	public function get_thread($id, $board_uri) {
		$ret = $this->cached_or("thread_in_{$board_uri}_{$id}", self::THREAD_INFO_TIMEOUT, function() use ($id, $board_uri) {
			$query = $this->pdo->prepare(sprintf("SELECT `locked`, `sage` FROM ``posts_%s`` WHERE `id` = :id AND `thread` IS NULL LIMIT 1", $board_uri));
			$query->bindValue(':id', $id, PDO::PARAM_INT);
			if (!self::execute_or_fail_or_throw($query)) {
				return self::CACHE_FALSE;
			}
			$thread = $query->fetchColumn();
			if ($thread === false) {
				return self::CACHE_FALSE;
			}
			return $thread;
		});

		if ($ret === self::CACHE_FALSE) {
			return false;
		}
		return $ret;
	}

	/**
	 * @param int $id The post id.
	 * @param string $board_uri The board uri. Fails if it doesn't exist.
	 */
	public function get_thread_locked($id, $board_uri) {
		$thread = $this->get_thread($id, $board_uri);
		if ($thread === false) {
			return false;
		}
		return array_filter($thread, function($key) { return $key === 'locked'; }, ARRAY_FILTER_USE_KEY);
	}

	/**
	 * @param int $id The post id.
	 * @param string $board_uri The board uri. Fails if it doesn't exist.
	 */
	public function get_thread_sage($id, $board_uri) {
		$thread = $this->get_thread($id, $board_uri);
		if ($thread === false) {
			return false;
		}
		return array_filter($thread, function($key) { return $key === 'sage'; }, ARRAY_FILTER_USE_KEY);
	}
}
