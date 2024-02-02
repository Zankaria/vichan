<?php

require_once('inc/data/data-accessor.php');

class ThemePages {
	private $config;
	private $da;

	/**
	 * Loads the relative theme information protecting global variables.
	 */
	private static function load_theme_info($path) {
		global $config, $theme;

		if (!file_exists($path)) {
			return false;
		}

		// Protect globals.
		$_config = $config;
		$_theme = $theme;

		// Load theme information into $theme
		include $path;

		$theme_info = $theme;

		// Restore globals.
		$config = $_config;
		$theme = $_theme;

		return $theme_info;
	}

	private static function load_theme_builder($path) {
		if (!file_exists($path)) {
			return false;
		}
		require_once $path;
	}

	/**
	 * @param array $config Configuration array.
	 * @param DataAccessor $da Data accessor.
	 */
	function __construct($config, $da) {
		$this->config = $config;
		$this->da = $da;
	}

	/**
	 * @param bool $board_name Rebuild also the board name.
	 */
	function rebuild_themes($action, $board, $board_name = false) {
		global $board, $current_locale;

		$themes = $this->da->get_themes_empty();
		if ($themes === false) {
			return false;
		}

		// Save the global variables
		$_board = $board;

		foreach ($themes as $theme) {
			// Restore them
			$board = $_board;

			// Reload the locale if the theme code changed it.
			if ($this->config['locale'] != $current_locale) {
				$current_locale = $this->config['locale'];
				init_locale($this->config['locale']);
			}

			if (PHP_SAPI === 'cli') {
				echo "Rebuilding theme " . $theme['theme']."... ";
			}

			$this->rebuild_theme($theme['theme'], $action, $board_name);

			if (PHP_SAPI === 'cli') {
				echo "done\n";
			}
		}

		// Restore them again
		$board = $_board;

		// Reload the locale
		if ($this->config['locale'] != $current_locale) {
			$current_locale = $this->config['locale'];
			init_locale($this->config['locale']);
		}
	}

	/**
	 * @return bool Returns if the theme's code has been ran or not.
	 */
	function rebuild_theme($theme_name, $action, $board = false) {
		$settings = $this->da->get_theme_settings($theme_name);
		if (!$settings) {
			return false;
		}

		$theme_info_path = $this->config['dir']['themes'] . '/' . $theme_name . '/info.php';
		$theme_builder_path = $this->config['dir']['themes'] . '/' . $theme_name . '/theme.php';

		$theme_info = ThemePages::load_theme_info($theme_info_path);
		if ($theme_info === false) {
			return false;
		}

		$success = ThemePages::load_theme_builder($theme_builder_path);
		if ($success === false) {
			return false;
		}

		/*
		 * "all functions and classes defined in the included file have the global scope."
		 * https://www.php.net/manual/en/function.include.php
		 */
		$theme_info['build_function']($action, $settings, $board);
		return true;
	}
}