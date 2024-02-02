<?php // Wraps data access with cache

defined('TINYBOARD') or exit;

class DataAccessor {
	private $db;
	private $cache;

	const BAN_INFO_TIMEOUT = 60 * 5; // 5 minutes.
	const BOARD_INFO_TIMEOUT = 60 * 10; // 10 minutes.
	const THREAD_INFO_TIMEOUT = 60 * 2; // 2 minutes.


	private function cached_or($key, $expire, $get) {
		if ($this->cache && ($value = $this->cache->get($key))) {
			return $value;
		}

		$value = $get();
		if ($value !== false) {
			if ($this->cache) {
				$this->cache->set($key, $value, $expire);
			}
			return $value;
		}
		return false;
	}

	/**
	 * Loads all the board data, ordered by uri.
	 * Usually the number of boards is relatively low, so the query shouldn't be too costly in any case, while the cost
	 * will be evened out by caching the data that is required by various functions.
	 */
	private function get_boards_ordered() {
		return $this->cached_or('boards_all_ordered', DataAccessor::BOARD_INFO_TIMEOUT, function() {
			$query = $this->db->prepare("SELECT * FROM ``boards`` ORDER BY `uri`");
			$query->execute() or error($this->db->error_of($query));
			return $query->fetchAll();
		});
	}

	function __construct($db, $cache) {
		$this->db = $db;
		$this->cache = $cache;
	}

	/**
	 * Get information about a board.
	 *
	 * @param string $uri The interested board uri.
	 * @return array|false An array with the board's data or false on error.
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
	 * @return string|false An the board's title or false on error.
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
	 */
	public function get_boards() {
		return $this->get_boards_ordered();
	}

	/**
	 * @return array|false A list of the board uris, or false on error..
	 */
	public function get_boards_uris() {
		$boards = $this->get_boards_ordered();
		if ($boards) {
			return array_map(function($arr) { return $arr['uri']; }, $boards);
		}
		return false;
	}

	/**
	 * @return array|false An array of arrays with themes without names or values, or false on error.
	 */
	public function get_themes_empty() {
		return $this->cached_or('themes_empty', false, function() {
			$query = $this->db->prepare("SELECT `theme` FROM ``theme_settings`` WHERE `name` IS NULL AND `value` IS NULL");
			$query->execute() or error($query->errorInfo()[1]);
			return $query->fetchAll(PDO::FETCH_ASSOC);
		});
	}

	/**
	 * @return array|false The settings of the given theme, or false on error.
	 */
	public function get_theme_settings($theme) {
		return $this->cached_or("theme_settings_of_$theme", false, function() use ($theme) {
			$query = $this->db->prepare("SELECT `name`, `value` FROM ``theme_settings`` WHERE `theme` = :theme AND `name` IS NOT NULL");
			$query->bindValue(':theme', $theme);
			$query->execute() or error($query->errorInfo()[1]);

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
	 * @return array|false The files associated with the ban in the board, or false on error.
	 */
	public function get_ban_files_of($id, $board_uri) {
		return $this->cached_or("ban_files_in_{$board_uri}_of_{$id}", DataAccessor::BAN_INFO_TIMEOUT, function() use ($id, $board_uri) {
			$query = $this->db->prepare(sprintf("SELECT `files` FROM ``posts_%s`` WHERE `id` = :id"), $board_uri);
			$query->bindValue(':id', $id, PDO::PARAM_INT);
			$query->execute() or error($query->errorInfo()[1]);
			return $query->fetchAll(PDO::FETCH_ASSOC);
		});
	}

	public function get_ban_appeals($id) {
		return $this->cached_or("ban_appeals_of_$id", DataAccessor::BAN_INFO_TIMEOUT, function() use($id) {
			$query = $this->db->prepare("SELECT `time`, `denied` FROM ``ban_appeals`` WHERE `ban_id` = :id");
			$query->bindValue(':id', $id, PDO::PARAM_INT);
			$query->execute() or error($query->errorInfo()[1]);
			return $query->fetchAll(PDO::FETCH_ASSOC);
		});
	}

	/**
	 * @param int $id The post id.
	 * @param string $board_uri The board uri. Fails if it doesn't exist.
	 */
	public function get_thread($id, $board_uri) {
		return $this->cached_or("thread_in_{$board_uri}_{$id}", DataAccessor::THREAD_INFO_TIMEOUT, function() use ($id, $board_uri) {
			$query = $this->db->prepare(sprintf("SELECT `locked`, `sage` FROM ``posts_%s`` WHERE `id` = :id AND `thread` IS NULL LIMIT 1"), $board_uri);
			$query->bindValue(':id', $id, PDO::PARAM_INT);
			$query->execute() or error($query->errorInfo()[1]);
			return $query->fetchColumn();
		});
	}

	/**
	 * @param int $id The post id.
	 * @param string $board_uri The board uri. Fails if it doesn't exist.
	 */
	public function get_thread_locked($id, $board_uri) {
		$thread = $this->get_thread($id, $board_uri);
		if ($thread) {
			return array_filter($thread, function($key) { return $key === 'locked'; }, ARRAY_FILTER_USE_KEY);
		}
		return false;
	}

	/**
	 * @param int $id The post id.
	 * @param string $board_uri The board uri. Fails if it doesn't exist.
	 */
	public function get_thread_sage($id, $board_uri) {
		$thread = $this->get_thread($id, $board_uri);
		if ($thread) {
			return array_filter($thread, function($key) { return $key === 'sage'; }, ARRAY_FILTER_USE_KEY);
		}
		return false;
	}
}