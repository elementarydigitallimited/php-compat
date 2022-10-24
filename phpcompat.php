<?php
/**
 * WPEngine_PHPCompat class
 *
 * @wordpress-plugin
 * Plugin Name: PHP Compatibility Checker
 * Description: Make sure your plugins and themes are compatible with PHP 8 or above.
 * Author:      Elementary Digital
 * Version:     1.0.0
 * Text Domain: php-compatibility-checker
 */

// Exit if this file is directly accessed.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPEPHPCOMPAT_CAPABILITY', 'manage_options' );
define( 'WPEPHPCOMPAT_ADMIN_PAGE_SLUG', 'php-compatibility-checker' );

require_once dirname( __FILE__ ) . '/load-files.php';

/**
 * This handles hooking into WordPress.
 *
 * @since 1.0.0
 */
class WPEngine_PHPCompat {

	/**
	 * Contains singleton instance.
	 *
	 * @since 1.0.0
	 * @static
	 * @var WPEngine_PHPCompat|null
	 */
	private static $instance = null;

	/**
	 * Settings page hook.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $page;

	/**
	 * Returns an instance of this class.
	 *
	 * @since 1.0.0
	 * @static
	 *
	 * @return WPEngine_PHPCompat An instance of this class.
	 */
	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}

		return self::$instance;
	}

	/**
	 * Initializes hooks and setup environment variables.
	 *
	 * @since 0.1.0
	 * @static
	 */
	public static function init() {
		$instance = self::instance();

		// Load textdomain.
		add_action( 'init', array( $instance, 'load_textdomain' ) );

		// Build our tools page.
		add_action( 'admin_menu', array( $instance, 'create_menu' ) );

		// Load our JavaScript.
		add_action( 'admin_enqueue_scripts', array( $instance, 'admin_enqueue' ) );

		// The action to run the compatibility test.
		add_action( 'wp_ajax_wpephpcompat_start_test', array( $instance, 'start_test' ) );
		add_action( 'wp_ajax_wpephpcompat_check_status', array( $instance, 'check_status' ) );
		add_action( 'wpephpcompat_start_test_cron', array( $instance, 'start_test' ) );
		add_action( 'wp_ajax_wpephpcompat_clean_up', array( $instance, 'clean_up' ) );

		// Create custom post type.
		add_action( 'init', array( $instance, 'create_job_queue' ) );

		// Handle activation notice.
		register_activation_hook( __FILE__, array( $instance, 'set_activation_notice_flag' ) );
		add_action( 'admin_notices', array( $instance, 'maybe_show_activation_notice' ) );

		// Add plugin action link.
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $instance, 'filter_plugin_links' ) );
	}

	/**
	 * Returns an array of available PHP versions to test.
	 *
	 * @since 1.0.0
	 *
	 * @return array Associative array of available PHP versions.
	 */
	function get_phpversions() {

		$versions = array(
			'PHP 8.0' => '8.0',
			'PHP 8.1' => '8.1'
		);

		return apply_filters( 'phpcompat_phpversions', $versions );

	}

	/**
	 * Starts the test.
	 *
	 * @since 1.0.0
	 *
	 * @action wp_ajax_wpephpcompat_start_test
	 * @action wpephpcompat_start_test_cron
	 */
	public function start_test() {
		if ( current_user_can( WPEPHPCOMPAT_CAPABILITY ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			global $wpdb;

			$wpephpc = new WPEPHPCompat( dirname( __FILE__ ) );

			$test_data = array();
			foreach ( array( 'test_version', 'only_active' ) as $key ) {
				if ( isset( $_POST[ $key ] ) ) {
					$test_data[ $key ] = sanitize_text_field( $_POST[ $key ] );
				}
			}

			// New scan!
			if ( isset( $_POST['startScan'] ) ) {
				// Make sure we clean up after the last test.
				$wpephpc->clean_after_scan();

				// Fork so we can close the connection.
				$this->fork_scan( $test_data['test_version'], $test_data['only_active'] );
			} else {
				if ( isset( $test_data['test_version'] ) ) {
					$wpephpc->test_version = $test_data['test_version'];
				}

				if ( isset( $test_data['only_active'] ) ) {
					$wpephpc->only_active = $test_data['only_active'];
				}

				$wpephpc->start_test();
			}

			wp_die();
		}
	}

	/**
	 * Checks the progress or result of the tests.
	 *
	 * @todo Use heartbeat API.
	 * @since  1.0.0
	 *
	 * @action wp_ajax_wpephpcompat_check_status
	 */
	public function check_status() {
		if ( current_user_can( WPEPHPCOMPAT_CAPABILITY ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			$scan_status  = get_option( 'wpephpcompat.status' );
			$count_jobs   = wp_count_posts( 'wpephpcompat_jobs' );
			$total_jobs   = get_option( 'wpephpcompat.numdirs' );
			$test_version = get_option( 'wpephpcompat.test_version' );
			$only_active  = get_option( 'wpephpcompat.only_active' );

			$active_job = false;

			$jobs = get_posts(
				array(
					'posts_per_page' => -1,
					'post_type'      => 'wpephpcompat_jobs',
					'orderby'        => 'title',
					'order'          => 'ASC',
				)
			);

			if ( 0 < count( $jobs ) ) {
				$active_job = $jobs[0]->post_title;
			}

			$to_encode = array(
				'status'     => $scan_status,
				'count'      => $count_jobs->publish,
				'total'      => $total_jobs,
				'activeJob'  => $active_job,
				'version'    => $test_version,
				'onlyActive' => $only_active,
			);

			// If the scan is still running.
			if ( $scan_status ) {
				$to_encode['results']  = '0';
				$to_encode['progress'] = ( ( $total_jobs - $count_jobs->publish ) / $total_jobs ) * 100;
			} else {
				// Else return the results and clean up!
				$scan_results = get_option( 'wpephpcompat.scan_results' );
				// Not using esc_html since the results are shown in a textarea.
				$to_encode['results'] = $scan_results;

				$wpephpc = new WPEPHPCompat( dirname( __FILE__ ) );
				$wpephpc->clean_after_scan();
			}
			wp_send_json( $to_encode );
		}
	}

	/**
	 * Makes an Ajax call to start the scan in the background.
	 *
	 * @since 1.3.2
	 *
	 * @param string $test_version Version of PHP to test.
	 * @param string $only_active  Whether to scan only active plugins or all.
	 */
	public function fork_scan( $test_version, $only_active ) {
		$query = array(
			'action' => 'wpephpcompat_start_test',
		);

		// Keep track of these variables.
		$body = array(
			'test_version' => $test_version,
			'only_active'  => $only_active,
		);

		// Instantly return!
		$args = array(
			'timeout'   => 0.01,
			'blocking'  => false,
			'body'      => $body,
			'cookies'   => $_COOKIE,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
		);

		// Build our URL.
		$url = add_query_arg( $query, admin_url( 'admin-ajax.php' ) );

		/**
		 * Modify the URL used to fork a request.
		 *
		 * When running in a Docker container the url used to access the site internally
		 * can be different from the external url. For example internally the port
		 * is 80, and externally it's 8081.
		 *
		 * @since 1.4.6
		 *
		 * @param string $url The url used to make the fork request.
		 */
		$url = apply_filters( 'phpcompat_fork_url', $url );
		// POST.
		wp_remote_post( esc_url_raw( $url ), $args );
	}

	/**
	 * Removes all database options from the database.
	 *
	 * @since 1.3.2
	 *
	 * @action wp_ajax_wpephpcompat_clean_up
	 */
	public function clean_up() {
		if ( current_user_can( WPEPHPCOMPAT_CAPABILITY ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			$wpephpc = new WPEPHPCompat( dirname( __FILE__ ) );
			$wpephpc->clean_after_scan();
			delete_option( 'wpephpcompat.scan_results' );
			wp_send_json( 'success' );
		}
	}

	/**
	 * Creates custom post type to store the directories we need to process.
	 *
	 * @since 1.0.0
	 */
	public function create_job_queue() {
		register_post_type(
			'wpephpcompat_jobs',
			array(
				'labels'      => array(
					'name'          => __( 'Jobs', 'php-compatibility-checker' ),
					'singular_name' => __( 'Job', 'php-compatibility-checker' ),
				),
				'public'      => false,
				'has_archive' => false,
			)
		);
	}

	/**
	 * Loads textdomain for WP < 4.6 translation support.
	 *
	 * @since 1.4.7
	 *
	 * @action admin_init
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'php-compatibility-checker' );
	}

	/**
	 * Enqueues our JavaScript and CSS.
	 *
	 * @since 1.0.0
	 *
	 * @action admin_enqueue_scripts
	 *
	 * @param string $hook Current page hook name.
	 */
	public function admin_enqueue( $hook ) {

		// Only enqueue these assets on the settings page.
		if ( $this->page !== $hook ) {
			return;
		}

		// Grab the plugin version.
		$plugin_data = get_plugin_data( __FILE__, false, false );
		if ( isset( $plugin_data['Version'] ) ) {
			$version = $plugin_data['Version'];
		}

		// Styles.
		wp_enqueue_style( 'wpephpcompat-style', plugins_url( '/src/css/style.css', __FILE__ ), array(), $version );

		// Scripts.
		wp_enqueue_script( 'wpephpcompat-handlebars', plugins_url( '/src/js/handlebars.js', __FILE__ ), array( 'jquery' ), $version );
		wp_enqueue_script( 'wpephpcompat-download', plugins_url( '/src/js/download.min.js', __FILE__ ), array(), $version );
		wp_enqueue_script( 'wpephpcompat', plugins_url( '/src/js/run.js', __FILE__ ), array( 'jquery', 'wpephpcompat-handlebars', 'wpephpcompat-download' ), $version );

		/**
		 * Strings for i18n.
		 *
		 * These translated strings can be access in jquery with window.wpephpcompat object.
		 */
		$strings = array(
			'name'       => __( 'Name', 'php-compatibility-checker' ),
			'compatible' => __( 'compatible', 'php-compatibility-checker' ),
			'are_not'    => __( 'plugins/themes may not be compatible', 'php-compatibility-checker' ),
			'is_not'     => __( 'Your WordPress site is possibly not PHP', 'php-compatibility-checker' ),
			'out_of'     => __( 'out of', 'php-compatibility-checker' ),
			'run'        => __( 'Scan site', 'php-compatibility-checker' ),
			'rerun'      => __( 'Scan site again', 'php-compatibility-checker' ),
			'your_wp'    => __( 'Your WordPress site is', 'php-compatibility-checker' ),
		);

		wp_localize_script( 'wpephpcompat', 'wpephpcompat', $strings );
	}

	/**
	 * Add the settings page to the wp-admin menu.
	 *
	 * @since 1.0.0
	 *
	 * @action admin_menu
	 */
	public function create_menu() {
		// Create Tools sub-menu.
		$this->page = add_submenu_page( 'tools.php', __( 'PHP Compatibility', 'php-compatibility-checker' ), __( 'PHP Compatibility', 'php-compatibility-checker' ), WPEPHPCOMPAT_CAPABILITY, WPEPHPCOMPAT_ADMIN_PAGE_SLUG, array( self::instance(), 'settings_page' ) );
	}

	/**
	 * Render method for the settings page.
	 *
	 * @since 1.0.0
	 */
	public function settings_page() {
		// Discover last options used.
		$test_version = get_option( 'wpephpcompat.test_version' );
		$only_active  = get_option( 'wpephpcompat.only_active' );

		// Determine if current site is a WP Engine customer.
		$is_wpe_customer = ! empty( $_SERVER['IS_WPE'] ) && $_SERVER['IS_WPE'];

		$phpversions = $this->get_phpversions();

		// Assigns defaults for the scan if none are found in the database.
		$test_version = ( ! empty( $test_version ) ) ? $test_version : '8.0';
		$only_active  = ( ! empty( $only_active ) ) ? $only_active : 'yes';

		// Content variables.
		$url_get_hosting          = esc_url( 'https://wpeng.in/5a0336/' );
		$url_wpe_agency_partners  = esc_url( 'https://wpeng.in/fa14e4/' );
		$url_wpe_customer_upgrade = esc_url( 'https://wpeng.in/407b79/' );
		$url_wpe_logo             = esc_url( 'https://wpeng.in/22f22b/' );
		$url_codeable_submit      = esc_url( 'https://codeable.io/wp-admin/admin-ajax.php?action=wp_engine_phpcompat' );

		$update_url = site_url( 'wp-admin/update-core.php', 'admin' );

		?>
		<div class="wrap wpe-pcc-wrap">
			<h1><?php _e( 'PHP Compatibility Checker', 'php-compatibility-checker' ); ?></h1>
			<div class="wpe-pcc-main">
				<p><?php _e( 'The PHP Compatibility Checker can be used on any WordPress website on any web host.', 'php-compatibility-checker' ); ?></p>
				<p><?php _e( 'This tool will lint your theme and plugin code on this site and provide you a report of compatibility issues. These issues are categorized into errors and warnings and will list the file and line number of the offending code, as well as the info about why that line of code is incompatible with the chosen version of PHP. This tool will also suggest updates to themes and plugins, as a new version may offer compatible code.', 'php-compatibility-checker' ); ?></p>
				<hr>
				<div class="wpe-pcc-scan-options">
					<h2><?php _e( 'Scan Options', 'php-compatibility-checker' ); ?></h2>
					<table class="form-table wpe-pcc-form-table">
						<tbody>
							<tr>
								<th scope="row"><label for="phptest_version"><?php _e( 'PHP Version', 'php-compatibility-checker' ); ?></label></th>
								<td>
									<fieldset>
										<?php
										foreach ( $phpversions as $name => $version ) {
											printf( '<label><input type="radio" name="phptest_version" value="%s" %s /> %s</label><br>', $version, checked( $test_version, $version, false ), $name );
										}
										?>
									</fieldset>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="active_plugins"><?php _e( 'Plugin / Theme Status', 'php-compatibility-checker' ); ?></label></th>
								<td>
									<fieldset>
										<label><input type="radio" name="active_plugins" value="yes" <?php checked( $only_active, 'yes', true ); ?> /> <?php _e( 'Only scan active plugins and themes', 'php-compatibility-checker' ); ?></label><br>
										<label><input type="radio" name="active_plugins" value="no" <?php checked( $only_active, 'no', true ); ?> /> <?php _e( 'Scan all plugins and themes', 'php-compatibility-checker' ); ?></label>
									</fieldset>
								</td>
							</tr>
							<tr>
								<th scope="row"></th>
									<td>
										<div class="wpe-pcc-run-scan">
											<input name="run" id="runButton" type="button" value="<?php _e( 'Scan site', 'php-compatibility-checker' ); ?>" class="button-secondary" />
											<div class="wpe-pcc-scan-information">
												<span style="display:none; visibility:visible;" class="spinner wpe-pcc-spinner"></span> <span id="wpe-progress-active"></span> <span style="display:none;" id="wpe-pcc-progress-count"></span>
											</div> <!-- /wpe-pcc-scan-information -->
										</div> <!-- /wpe-pcc-run-scan -->
									</td>
								</th>
							</tr>
						</tbody>
					</table>
				</div> <!-- /wpe-pcc-scan-options -->

				<div class="wpe-pcc-results" style="display:none;">
					<hr>
					<h2>
						<?php
						printf(
							/* translators: %s: PHP version number */
							__( 'Scan Results for PHP %s Compatibility', 'php-compatibility-checker' ),
							'<span class="wpe-pcc-test-version">' . $test_version . '</span>'
						);
						?>
					</h2>

					<div class="wpe-pcc-download-report" style="display:none;">
						<a id="downloadReport" class="button-primary" href="#"><span class="dashicons dashicons-download"></span> <?php _e( 'Download Report', 'php-compatibility-checker' ); ?></a>
						<a class="wpe-pcc-clear-results" name="run" id="cleanupButton"><?php _e( 'Clear results', 'php-compatibility-checker' ); ?></a>
						<label class="wpe-pcc-developer-mode">
							<input type="checkbox" id="developermode" name="developermode" value="yes" />
							<?php _e( 'View results as raw text', 'php-compatibility-checker' ); ?>
						</label>
						<hr>
					</div> <!-- /wpe-pcc-download-report -->

					<div id="wpe-pcc-standardMode"></div>

					<div style="display:none;" id="developerMode">
						<textarea readonly="readonly" id="testResults"></textarea>
					</div>

					<p class="wpe-pcc-attention">
						<?php
						printf(
							/* translators: %s: hosting URL */
							__( '<strong>Attention:</strong> Not all errors are show-stoppers. <a target="_blank" href="%s">Test this site on PHP 8</a> to see if it just works!', 'php-compatibility-checker' ),
							$url_get_hosting
						);
						?>
					</p>

				</div> <!-- /wpe-pcc-results -->

				<div class="wpe-pcc-footer">
					<hr>
					<strong><?php _e( 'Limitations &amp; Caveats', 'php-compatibility-checker' ); ?></strong>
					<ul class="wpe-pcc-bullets">
						<li><?php _e( 'This tool cannot detect unused code paths that might be used for backwards compatibility, potentially showing false positives. We maintain <a target="_blank" href="https://github.com/wpengine/phpcompat/wiki/Results">a whitelist of plugins</a> that can cause false positives.', 'php-compatibility-checker' ); ?></li>
						<li><?php _e( 'This tool does not execute your theme or plugin code, so it cannot detect runtime compatibility issues.', 'php-compatibility-checker' ); ?></li>
						<li><?php _e( 'PHP Warnings could cause compatibility issues with future PHP versions and/or spam your logs.', 'php-compatibility-checker' ); ?></li>
						<li>
							<?php
							printf(
								/* translators: %s: FAQ URL */
								__( 'The scan will get stuck if WP-Cron is not running correctly. Please <a target="_blank" href="%s">see the FAQ</a> for more information.', 'php-compatibility-checker' ),
								'https://wordpress.org/plugins/php-compatibility-checker/faq/'
							);
							?>
						</li>
					</ul>
					<p>
						<?php
						printf(
							/* translators: %s: GitHub Wiki URL */
							__( 'Report false positives <a target="_blank" href="%s">on our GitHub repo</a>.', 'php-compatibility-checker' ),
							'https://github.com/wpengine/phpcompat/wiki/Results'
						);
						?>
					</p>
				</div> <!-- /wpe-pcc-footer -->
			</div> <!-- /wpe-pcc-main -->

		</div> <!-- /wpe-pcc-wrap -->

		<script id="result-template" type="text/x-handlebars-template">
			<div class="wpe-pcc-alert wpe-pcc-alert-{{#if skipped}}skipped{{else if passed}}passed{{else}}error{{/if}}">
				<p>
					<span class="dashicons-before dashicons-{{#if errors}}no{{else if skipped}}editor-help{{else}}yes{{/if}}"></span>
					<strong>{{plugin_name}} </strong> -
					<span class="wpe-pcc-alert-status">
						{{#if skipped}}
							<span class="wpe-pcc-badge wpe-pcc-badge-skipped"><?php _e( 'Unknown', 'php-compatibility-checker' ); ?></span>
						{{else}}
							{{#if passed}}
								<span class="wpe-pcc-badge wpe-pcc-badge-passed"><?php _e( 'Compatible', 'php-compatibility-checker' ); ?></span>
							{{/if}}
							{{#if warnings}}
								<span class="wpe-pcc-badge wpe-pcc-badge-warnings"><?php _e( 'Warnings:', 'php-compatibility-checker' ); ?> <strong>{{warnings}}</strong></span>
							{{/if}}
							{{#if errors}}
								<span class="wpe-pcc-badge wpe-pcc-badge-errors"><?php _e( 'Errors:', 'php-compatibility-checker' ); ?> <strong>{{errors}}</strong></span>
							{{/if}}
						{{/if}}
						</span>
						{{#if updateAvailable}}
							(<a href="<?php echo esc_url( $update_url ); ?>"><?php _e( 'Update Available', 'php-compatibility-checker' ); ?></a>)
						{{/if}}
					<a class="wpe-pcc-alert-details" href="#"><?php _e( 'toggle details', 'php-compatibility-checker' ); ?></a>
					<textarea class="wpe-pcc-alert-logs hide">{{logs}}</textarea>
				</p>
			</div> <!-- /wpe-pcc-alert -->
		</script>
		<?php
	}

	/**
	 * Sets the activation notice flag so that it is shown in the admin.
	 *
	 * @since 1.4.4
	 */
	public function set_activation_notice_flag() {
		add_option( 'wpephpcompat.show_notice', true );
	}

	/**
	 * Shows the activation notice if the flag for it is set.
	 *
	 * @since 1.4.4
	 */
	public function maybe_show_activation_notice() {
		$option = get_option( 'wpephpcompat.show_notice' );

		if ( ! $option ) {
			return;
		}

		delete_option( 'wpephpcompat.show_notice' );

		if ( ! current_user_can( WPEPHPCOMPAT_CAPABILITY ) ) {
			return;
		}

		$url = add_query_arg( 'page', WPEPHPCOMPAT_ADMIN_PAGE_SLUG, admin_url( 'tools.php' ) );

		?>
		<div class="notice updated is-dismissible">
			<p>
				<?php
				printf(
					/* translators: %s: URL to admin page */
					__( 'You have just activated the <strong>PHP Compatibility Checker</strong>. <a href="%s">Start scanning your plugins and themes for compatibility with the latest PHP versions now!</a>', 'php-compatibility-checker' ),
					esc_url( $url )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Adds a link to the admin page to the plugin action links.
	 *
	 * @since 1.4.4
	 *
	 * @param array $links Plugin action links.
	 * @return array Modified plugin action links.
	 */
	public function filter_plugin_links( $links ) {
		if ( current_user_can( WPEPHPCOMPAT_CAPABILITY ) ) {
			$url = add_query_arg( 'page', WPEPHPCOMPAT_ADMIN_PAGE_SLUG, admin_url( 'tools.php' ) );

			array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Start Scan', 'php-compatibility-checker' ) . '</a>' );
		}

		return $links;
	}
}

// Register the WPEngine_PHPCompat instance.
WPEngine_PHPCompat::init();
