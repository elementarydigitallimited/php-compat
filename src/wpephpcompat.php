<?php
/**
 * WPEPHPCompat class
 *
 * @package WPEngine\PHPCompat
 * @since 1.0.0
 */

use PHP_CodeSniffer\Config;
use PHP_CodeSniffer\Exceptions\RuntimeException;
use PHP_CodeSniffer\Files\FileList;
use PHP_CodeSniffer\Reporter;
use PHP_CodeSniffer\Runner;
use PHP_CodeSniffer\Util\Cache;
use PHP_CodeSniffer\Util\Common;
use PHPCompatibility\PHPCSHelper;

// Exit if this file is directly accessed.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Summary.
 *
 * Description.
 *
 * @since 1.0.0
 */
class WPEPHPCompat {
	/**
	 * The PHP_CodeSniffer_CLI object.
	 *
	 * @since 1.0.0
	 * @var object
	 */
	public $cli = null;

	/**
	 * Default values for PHP_CodeSniffer scan.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	public $values = array();

	/**
	 * Version of PHP to test.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public $test_version = null;

	/**
	 * Scan only active plugins or all?
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public $only_active = null;

	/**
	 * The base directory for the plugin.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public $base = null;

	/**
	 * Constructor.
	 *
	 * @param string $dir Base plugin directory.
	 *
	 * @since 1.0.0
	 *
	 */
	public function __construct( $dir ) {
		$this->base = $dir;
	}

	/**
	 * Starts the testing process.
	 *
	 * @since 1.0.0
	 */
	public function start_test() {
		$this->debug_log( 'startScan: ' . isset( $_POST['startScan'] ) );

		/**
		 * Filters the scan timeout.
		 *
		 * Lets you change the timeout of the scan. The value is how long the scan
		 * runs before dying and picking back up on a cron. You can set $timeout to
		 * 0 to disable the timeout and the cron.
		 *
		 * @param int $timeout The timeout in seconds.
		 *
		 * @since 1.0.4
		 *
		 */
		$timeout = apply_filters( 'wpephpcompat_scan_timeout', MINUTE_IN_SECONDS );
		$this->debug_log( 'timeout: ' . $timeout );

		// No reason to lock if there's no timeout.
		if ( 0 !== $timeout ) {
			// Try to lock.
			$lock_result = add_option( 'wpephpcompat.lock', time(), '', 'no' );

			$this->debug_log( 'lock: ' . $lock_result );

			if ( ! $lock_result ) {
				$lock_result = get_option( 'wpephpcompat.lock' );

				// Bail if we were unable to create a lock, or if the existing lock is still valid.
				if ( ! $lock_result || ( $lock_result > ( time() - $timeout ) ) ) {
					$this->debug_log( 'Process already running (locked), returning.' );

					$timestamp = wp_next_scheduled( 'wpephpcompat_start_test_cron' );

					if ( false === (bool) $timestamp ) {
						wp_schedule_single_event( time() + $timeout, 'wpephpcompat_start_test_cron' );
					}

					return;
				}
			}
			update_option( 'wpephpcompat.lock', time(), false );
		}

		// Check to see if scan has already started.
		$scan_status = get_option( 'wpephpcompat.status' );
		$this->debug_log( 'scan status: ' . $scan_status );
		if ( ! $scan_status ) {

			// Clear the previous results.
			delete_option( 'wpephpcompat.scan_results' );

			update_option( 'wpephpcompat.status', '1', false );
			update_option( 'wpephpcompat.test_version', $this->test_version, false );
			update_option( 'wpephpcompat.only_active', $this->only_active, false );

			$this->debug_log( 'Generating directory list.' );

			// Add plugins and themes.
			$this->generate_directory_list();

			$count_jobs = wp_count_posts( 'wpephpcompat_jobs' );
			update_option( 'wpephpcompat.numdirs', $count_jobs->publish, false );
		} else {
			// Get scan settings from database.
			$this->test_version = get_option( 'wpephpcompat.test_version' );
			$this->only_active  = get_option( 'wpephpcompat.only_active' );
		}

		$args = array(
			'posts_per_page' => - 1,
			'post_type'      => 'wpephpcompat_jobs',
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		$directories = get_posts( $args );
		$this->debug_log( count( $directories ) . ' plugins left to process.' );

		// If there are no directories to scan, we're finished!
		if ( ! $directories ) {
			$this->debug_log( 'No more plugins to process.' );
			update_option( 'wpephpcompat.status', '0', false );

			return;
		}
		if ( 0 !== $timeout ) {
			wp_schedule_single_event( time() + $timeout, 'wpephpcompat_start_test_cron' );
		}

		if ( ! $this->is_command_line() ) {
			/**
			 * Kill cron after a configurable timeout.
			 * Subtract 5 from the timeout if we can to avoid race conditions.
			 */
			set_time_limit( ( $timeout > 5 ? $timeout - 5 : $timeout ) );
		}

		$scan_results = get_option( 'wpephpcompat.scan_results' );

		foreach ( $directories as $directory ) {
			$this->debug_log( 'Processing: ' . $directory->post_title );

			// Add the plugin/theme name to the results.
			$scan_results .= __( 'Name', 'php-compatibility-checker' ) . ': ' . $directory->post_title . "\n\n";

			// Keep track of the number of times we've attempted to scan the plugin.
			$count = (int) get_post_meta( $directory->ID, 'count', true );
			if ( ! $count ) {
				$count = 1;
			}

			$this->debug_log( 'Attempted scan count: ' . $count );

			if ( $count > 2 ) { // If we've already tried twice, skip it.
				$scan_results .= __( 'The plugin/theme was skipped as it was too large to scan before the server killed the process.', 'php-compatibility-checker' ) . "\n\n";
				update_option( 'wpephpcompat.scan_results', $scan_results, false );
				wp_delete_post( $directory->ID );
				$count = 0;
				$this->debug_log( 'Skipped: ' . $directory->post_title );
				continue;
			}

			// Increment and save the count.
			$count ++;
			update_post_meta( $directory->ID, 'count', $count );

			// Start the scan.
			$report = $this->process_file( $directory->post_content );

			if ( ! $report ) {
				$report = 'PHP ' . $this->test_version . __( ' compatible.', 'php-compatibility-checker' );
			}

			$scan_results .= $report . "\n";

			$update = get_post_meta( $directory->ID, 'update', true );

			if ( ! empty( $update ) ) {
				$version = get_post_meta( $directory->ID, 'version', true );

				$scan_results .= 'Update Available: ' . $update . '; Current Version: ' . $version . ";\n";
			}

			$scan_results .= "\n";

			update_option( 'wpephpcompat.scan_results', $scan_results, false );

			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				WP_CLI::log( $scan_results );
			}

			wp_delete_post( $directory->ID );
		}

		update_option( 'wpephpcompat.status', '0', false );

		$this->debug_log( 'Scan finished.' );

		return $scan_results;
	}

	/**
	 * Runs the actual PHPCompatibility test.
	 *
	 * @param mixed $dir Directory to scan.
	 *
	 * @return string Scan results.
	 * @since 1.0.0
	 *
	 */
	public function process_file( $dir ) {
		if ( strpos( $this->test_version, '-' ) !== strlen( $this->test_version ) ) {
			$test_version = $this->test_version . '-';
		} else {
			$test_version = $this->test_version;
		}

		// Ignoring warnings when generating the exit code.
		PHPCSHelper::setConfigData( 'ignore_warnings_on_exit', true, true );
		PHPCSHelper::setConfigData( 'testVersion', $test_version, true );

		$runner                     = new Runner();
		$runner->config             = new Config();
		$runner->config->extensions = array(
			'php' => 'PHP',
			'inc' => 'PHP',
		);

		$runner->config->reportWidth = 110;
		$runner->config->ignored     = $this->generate_ignored_list();
		$runner->config->files       = is_array( $dir ) ?: [ $dir ];
		$runner->config->standards   = [ 'PHPCompatibility' ];
		$runner->init();

		$runner->reporter = new Reporter( $runner->config );

		$lastDir = '';
		$todo = new FileList( $runner->config, $runner->ruleset );
		$numFiles = count( $todo );
		// Batching and forking.
		$numPerBatch = ceil($numFiles / 8 );

		for ($batch = 0; $batch < 8; $batch++) {
			$startAt = ($batch * $numPerBatch);
			if ($startAt >= $numFiles) {
				break;
			}

			$endAt = ($startAt + $numPerBatch);
			if ($endAt > $numFiles) {
				$endAt = $numFiles;
			}

			// Move forward to the start of the batch.
			$todo->rewind();
			for ($i = 0; $i < $startAt; $i++) {
				$todo->next();
			}

			// Reset the reporter to make sure only figures from this
			// file batch are recorded.
			$runner->reporter->totalFiles    = 0;
			$runner->reporter->totalErrors   = 0;
			$runner->reporter->totalWarnings = 0;
			$runner->reporter->totalFixable  = 0;
			$runner->reporter->totalFixed    = 0;

			// Process the files.
			$pathsProcessed = [];
			for ($i = $startAt; $i < $endAt; $i++) {
				$path = $todo->key();
				$file = $todo->current();

				if ($file->ignored === true) {
					$todo->next();
					continue;
				}

				$currDir = dirname($path);
				if ($lastDir !== $currDir) {
					if (PHP_CODESNIFFER_VERBOSITY > 0) {
						echo 'Changing into directory '.Common::stripBasepath($currDir, $runner->config->basepath).PHP_EOL;
					}

					$lastDir = $currDir;
				}

				$runner->processFile($file);

				$pathsProcessed[] = $path;
				$todo->next();
			}//end for
		}//end for

		ob_start();
		$runner->reporter->printReports();
		$report = ob_get_clean();

		return $this->clean_report( $report );
	}

	/**
	 * Generates a list of ignored files and directories.
	 *
	 * @return array An array containing files and directories that should be ignored.
	 * @since 1.0.3
	 *
	 */
	public function generate_ignored_list() {
		// Default ignored list.
		$ignored = array(
			'*/tests/*', // No reason to scan tests.
			'*/test/*', // Another common test directory.
			'*/node_modules/*', // Commonly used for development but not in production.
			'*/tmp/*', // Temporary files.
		);

		return apply_filters( 'phpcompat_whitelist', $ignored );
	}

	/**
	 * Generates a list of directories to scan and populate the queue.
	 *
	 * @since  1.0.0
	 */
	public function generate_directory_list() {
		if ( ! function_exists( 'get_plugins' ) ) {

			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_base = dirname( $this->base ) . DIRECTORY_SEPARATOR;

		$all_plugins = get_plugins();

		$update_plugins = get_site_transient( 'update_plugins' );

		foreach ( $all_plugins as $k => $v ) {
			// Exclude our plugin.
			if ( 'PHP Compatibility Checker' === $v['Name'] ) {
				continue;
			}

			// Exclude active plugins if only_active = "yes".
			if ( 'yes' === $this->only_active ) {
				// Get array of active plugins.
				$active_plugins = get_option( 'active_plugins' );

				if ( ! in_array( $k, $active_plugins, true ) ) {
					continue;
				}
			}

			$plugin_file = plugin_dir_path( $k );

			// Plugin in root directory (like Hello Dolly).
			if ( './' === $plugin_file ) {
				$plugin_path = $plugin_base . $k;
			} else {
				$plugin_path = $plugin_base . $plugin_file;
			}

			$id = $this->add_directory( $v['Name'], $plugin_path );

			if ( is_object( $update_plugins ) && is_array( $update_plugins->response ) ) {
				// Check for plugin updates.
				foreach ( $update_plugins->response as $uk => $uv ) {
					// If we have a match.
					if ( $uk === $k ) {
						$this->debug_log( 'An update exists for: ' . $v['Name'] );
						// Save the update version.
						update_post_meta( $id, 'update', $uv->new_version );
						// Save the current version.
						update_post_meta( $id, 'version', $v['Version'] );
					}
				}
			}
		}

		// Add themes.
		$all_themes = wp_get_themes();

		foreach ( $all_themes as $k => $v ) {
			if ( 'yes' === $this->only_active ) {
				$current_theme = wp_get_theme();
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				if ( $all_themes[ $k ]->Name !== $current_theme->Name ) {
					continue;
				}
			}

			$theme_path = $all_themes[ $k ]->theme_root . DIRECTORY_SEPARATOR . $k . DIRECTORY_SEPARATOR;

			$this->add_directory( $all_themes[ $k ]->Name, $theme_path );
		}

		// Add parent theme if the current theme is a child theme.
		if ( 'yes' === $this->only_active && is_child_theme() ) {
			$parent_theme_path = get_template_directory();
			$theme_data        = wp_get_theme();
			$parent_theme_name = $theme_data->parent()->Name;

			$this->add_directory( $parent_theme_name, $parent_theme_path );
		}
	}

	/**
	 * Cleans and formats the final report.
	 *
	 * @param string $report The full report.
	 *
	 * @return string         The cleaned report.
	 */
	public function clean_report( $report ) {
		// Remove unnecessary overview.
		$report = preg_replace( '/Time:.+\n/si', '', $report );

		// Remove whitespace.
		$report = trim( $report );

		return $report;
	}

	/**
	 * Removes all database entries created by the scan.
	 *
	 * @since 1.0.0
	 */
	public function clean_after_scan() {
		// Delete options created during the scan.
		delete_option( 'wpephpcompat.lock' );
		delete_option( 'wpephpcompat.status' );
		delete_option( 'wpephpcompat.numdirs' );

		// Clear scheduled cron.
		wp_clear_scheduled_hook( 'wpephpcompat_start_test_cron' );

		// Make sure all directories are removed from the queue.
		$args = array(
			'posts_per_page' => - 1,
			'post_type'      => 'wpephpcompat_jobs',
		);

		$directories = get_posts( $args );

		foreach ( $directories as $directory ) {
			wp_delete_post( $directory->ID );
		}
	}

	/**
	 * Adds a path to the wpephpcompat_jobs custom post type.
	 *
	 * @param string $name Plugin or theme name.
	 * @param string $path Full path to the plugin or theme directory.
	 *
	 * @return null
	 */
	private function add_directory( $name, $path ) {
		$dir = array(
			'post_title'   => $name,
			'post_content' => $path,
			'post_status'  => 'publish',
			'post_author'  => 1,
			'post_type'    => 'wpephpcompat_jobs',
		);

		return wp_insert_post( $dir );
	}

	/**
	 * Logs to the error log if WP_DEBUG is enabled.
	 *
	 * @param string $message Message to log.
	 *
	 * @since 1.0.0
	 *
	 */
	private function debug_log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true && ! $this->is_command_line() ) {
			if ( is_array( $message ) || is_object( $message ) ) {
				error_log( print_r( $message, true ) );
			} else {
				error_log( 'WPE PHP Compatibility: ' . $message );
			}
		}
	}

	/**
	 * Are we running on the command line?
	 *
	 * @return boolean Returns true if the request came from the command line.
	 * @since  1.0.0
	 */
	private function is_command_line() {
		return defined( 'WP_CLI' ) || defined( 'PHPUNIT_TEST' ) || php_sapi_name() === 'cli';
	}
}
