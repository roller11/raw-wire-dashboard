<?php
/**
 * Public-facing functionality for Raw Wire Dashboard
 *
 * @package Raw_Wire_Dashboard
 * @subpackage Raw_Wire_Dashboard/includes
 * @since 1.0.0
 */

/**
 * The public-facing functionality of the plugin.
 *
 * Defines the plugin name, version, and two hooks to use when enqueueing
 * the public-facing stylesheet and JavaScript.
 *
 * @package Raw_Wire_Dashboard
 * @subpackage Raw_Wire_Dashboard/includes
 * @author Raw Wire DAO LLC
 * @since 1.0.0
 */
class Raw_Wire_Dashboard_Public {

	/**
	 * The ID of this plugin.
	 *
	 * @since 1.0.0
	 * @access private
	 * @var string $plugin_name The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since 1.0.0
	 * @access private
	 * @var string $version The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since 1.0.0
	 * @param string $plugin_name The name of the plugin.
	 * @param string $version The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	/**
	 * Register the stylesheets for the public-facing side of the site.
	 *
	 * @since 1.0.0
	 */
	public function enqueue_styles() {
		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Raw_Wire_Dashboard_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Raw_Wire_Dashboard_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_style(
			$this->plugin_name,
			plugin_dir_url( __FILE__ ) . '../css/raw-wire-dashboard-public.css',
			array(),
			$this->version,
			'all'
		);
	}

	/**
	 * Register the JavaScript for the public-facing side of the site.
	 *
	 * @since 1.0.0
	 */
	public function enqueue_scripts() {
		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Raw_Wire_Dashboard_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Raw_Wire_Dashboard_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_script(
			$this->plugin_name,
			plugin_dir_url( __FILE__ ) . '../js/raw-wire-dashboard-public.js',
			array( 'jquery' ),
			$this->version,
			false
		);
	}

	/**
	 * Render the dashboard widget on the public-facing side.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function render_dashboard() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		?>
		<div class="raw-wire-dashboard-container">
			<div class="raw-wire-dashboard-header">
				<h2><?php echo esc_html__( 'Dashboard', 'raw-wire-dashboard' ); ?></h2>
			</div>
			<div class="raw-wire-dashboard-content">
				<?php $this->render_dashboard_content(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the main dashboard content.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function render_dashboard_content() {
		/**
		 * Allow developers to customize dashboard content
		 *
		 * @since 1.0.0
		 */
		do_action( 'raw_wire_dashboard_render_content' );
	}

	/**
	 * Get dashboard statistics.
	 *
	 * @since 1.0.0
	 * @return array Array of dashboard statistics.
	 */
	public function get_dashboard_stats() {
		$stats = array(
			'user_id'      => get_current_user_id(),
			'user_name'    => wp_get_current_user()->user_login,
			'display_name' => wp_get_current_user()->display_name,
			'timestamp'    => current_time( 'mysql' ),
		);

		/**
		 * Filter dashboard statistics
		 *
		 * @since 1.0.0
		 * @param array $stats Array of dashboard statistics.
		 */
		return apply_filters( 'raw_wire_dashboard_stats', $stats );
	}

	/**
	 * Output dashboard statistics as JSON.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function output_dashboard_stats_json() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'User not authenticated' ), 401 );
		}

		$stats = $this->get_dashboard_stats();
		wp_send_json_success( $stats );
	}

	/**
	 * Initialize shortcode for dashboard display.
	 *
	 * @since 1.0.0
	 * @param array $atts Shortcode attributes.
	 * @return string Shortcode output.
	 */
	public function shortcode_dashboard( $atts ) {
		ob_start();
		$this->render_dashboard();
		return ob_get_clean();
	}
}
