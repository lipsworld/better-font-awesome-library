<?php
/**
 * Better Font Awesome Library
 *
 * A class to implement Font Awesome via the jsDelivr CDN.
 *
 * @since 0.9.0
 *
 * @package Better Font Awesome Library
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// Includes
require_once plugin_dir_path( __FILE__ ) . 'inc/class-jsdelivr-fetcher.php';

if ( ! class_exists( 'Better_Font_Awesome_Library' ) ) :
	class Better_Font_Awesome_Library {

	/*--------------------------------------------*
	 * Constants
	 *--------------------------------------------*/
	const NAME = 'Better Font Awesome Library';
	const SLUG = 'bfa';
	const VERSION = '0.9.0';


	/*--------------------------------------------*
	 * Properties
	 *--------------------------------------------*/
	public $args, $stylesheet_url, $prefix, $css, $icons, $version;
	protected $jsdelivr_fetcher, $cdn_data, $titan;
	protected $default_args = array(
		'version'                 => 'latest',
		'minified'                => true,
		'remove_existing_fa'      => false,
		'load_styles'             => true,
		'load_admin_styles'       => true,
		'load_shortcode'          => false,
		'load_tinymce_plugin'     => false,
		'fallback_css_url'        => 'lib/fallback-font-awesome/css/font-awesome.min.css',
		'fallback_css_file'       => '',
	);

	/**
	 * Instance of this class.
	 *
	 * @since    1.0.0
	 *
	 * @var      object
	 */
	protected static $instance = null;

	/**
	 * Whether or not the wp_remote_get() call for the Font Awesome stylesheet succeeded.
	 *
	 * @since    1.0.0
	 *
	 * @var      boolean
	 */
	private $css_fetch_succeeded = false;

	/**
	 * Returns the instance of this class, and initializes
	 * the instance if it doesn't already exist
	 *
	 * @return Better_Font_Awesome_Library The BFAL object
	 */
	public static function get_instance( $args = '' ) {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new static( $args );
		}

		return $instance;
	}

	/**
	 * Constructor
	 */
	protected function __construct( $args = '' ) {

		// Initialize jsDelivr Fercher class_alias()
		$this->jsdelivr_fetcher = jsDeliver_Fetcher::get_instance();

		// Do API failure actions
		if ( ! $this->api_fetch_succeeded() ) {
			$this->api_fetch_failure_actions();
		}

		// Set default fallback CSS file path
		$this->default_args['fallback_css_file'] = plugin_dir_path( __FILE__ ) . $this->default_args['fallback_css_url'];

		// Initialize with specific args if passed
		$this->args = wp_parse_args( $args, $this->default_args );

		// Filter args
		$this->args = apply_filters( 'bfa_args', $this->args );

		// Initialize functionality
		add_action( 'init', array( $this, 'init' ) );

		// Do scripts and styles - priority 15 to make sure styles/scripts load after other plugins
		if ( $this->args['load_styles'] || $this->args['remove_existing_fa'] ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'do_scripts_and_styles' ), 15 );
		}

		if ( $this->args['load_admin_styles'] || $this->args['load_tinymce_plugin'] ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'do_scripts_and_styles' ), 15 );
		}

		// Load TinyMCE plugin
		if ( $this->args['load_tinymce_plugin'] ) {
			add_action( 'admin_head', array( $this, 'admin_init' ) );
			add_action( 'admin_head', array( $this, 'admin_head_variables' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'custom_admin_css' ), 15 );
		}
	}

	/**
	 * Private clone method to prevent cloning of the instance of the
	 * *Singleton* instance.
	 *
	 * @return void
	 */
	private function __clone() {
	}

	/**
	 * Private unserialize method to prevent unserializing of the *Singleton*
	 * instance.
	 *
	 * @return void
	 */
	private function __wakeup() {
	}

	public function api_fetch_failure_actions() {
		add_action( 'admin_notices', array( $this, 'api_error_notice' ) );
	}

	/**
	 * Runs when the plugin is initialized
	 */
	function init() {
		// Set Font Awesome variables (stylesheet url, prefix, etc)
		$this->setup_global_variables();

		// Add Font Awesome stylesheet to TinyMCE
		add_editor_style( $this->stylesheet_url );

		// Remove existing [icon] shortcodes added via other plugins/themes
		if ( $this->args['remove_existing_fa'] ) {
			remove_shortcode( 'icon' );
		}

		// Register the shortcode [icon]
		if ( $this->args['load_shortcode'] ) {
			add_shortcode( 'icon', array( $this, 'render_shortcode' ) );
		}
	}

	/*
	 * Set the Font Awesome stylesheet url to use based on the settings
	 */
	function setup_global_variables() {
		// Get latest version if need be
		if ( 'latest' == $this->args['version'] ) {
			$this->args['version'] = $this->get_api_value( 'lastversion' );

			/**
			 * Fallback in case the API fetch failed and 'lastversion' isn't available.
			 * Defaults to the highest available version (key) in $transient_css_array.
			 */
			if ( ! $this->args['version'] ) {
				$transient_css_array = get_transient( self::SLUG . '-css' );
				$this->args['version'] = max( array_keys( $transient_css_array ) );
			}
		}

		// Set stylesheet URL
		$stylesheet = $this->args['minified'] ? '/css/font-awesome.min.css' : '/css/font-awesome.css';
		$this->stylesheet_url = '//cdn.jsdelivr.net/fontawesome/' . $this->args['version'] . $stylesheet;

		// Set proper prefix based on version
		if ( 0 <= version_compare( $this->args['version'], '4' ) )
			$this->prefix = 'fa';
		elseif ( 0 <= version_compare( $this->args['version'], '3' ) )
			$this->prefix = 'icon';

		$this->css = $this->fetch_css();
				
		// Setup icons for selected version of Font Awesome
		if ( $this->css ) {
			$this->get_icons();
		}
	}

	function fetch_css() {

		// Get transient CSS
		$transient_css_array = get_transient( self::SLUG . '-css' );
				

		$transient_css = isset( $transient_css_array[ $this->args['version'] ] ) ? $transient_css_array[ $this->args['version'] ] : '';
				
		// If the CSS transient doesn't exist, try to fetch the jsDelivr CDN CSS
		if ( ! $transient_css ) {

			// Set the correct URL protocol
			if ( isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] == "on" ) {
				$protocol = 'https:';
			} else {
				$protocol = 'http:';
			}

			$response = wp_remote_get( $protocol . $this->stylesheet_url );
			if ( is_wp_error( $response ) ) {

				// Log error for admin notice
				$this->css_fetch_error = $response->get_error_message();

				// Trigger CSS fetch failure actions
				$this->css_fetch_failure_actions();

				if ( $this->get_fallback_css() ) {

					// Get fallback CSS
					$response = $this->get_fallback_css();

					// Set stylesheet to fallback URL
					$this->stylesheet_url = plugin_dir_url( __FILE__ ) . $this->args['fallback_css_url'];

				} else {
					$response = $response->get_error_message();
					$this->css_fallback_failure_actions();
				}

			} else {
				$response = wp_remote_retrieve_body( $response );
				$this->css_fetch_succeeded = true;

				// Set CSS transient
				$transient_css_array[ $this->args['version'] ] = $response;
				set_transient( self::SLUG . '-css', $transient_css_array, 12 * HOUR_IN_SECONDS );
			}

		}
		else {
			$response = $transient_css;
		}

		return $response;
	}

	/**
	 * Get local version of Font Awesome if all else fails.
	 *
	 * @since  0.9.8
	 *
	 * @return string $css Fallback Font Awesome CSS.
	 */
	private function get_fallback_css() {

		if ( is_readable ( $this->default_args['fallback_css_file'] ) ) {
			ob_start();
			include plugin_dir_path( __FILE__ ) . $this->default_args['fallback_css_url'];
	        $css = ob_get_clean();
		} else {
			$css = false;
		}

		return $css;

	}

	/**
	 * What to do if no CSS transient is set AND the CDN fetch fails.
	 *
	 * @since  0.9.8
	 */
	function css_fetch_failure_actions() {
		add_action( 'admin_notices', array( $this, 'css_error_notice' ) );
	}

	/*
     * Create list of available icons based on selected version of Font Awesome
     */
	function get_icons() {

		// Get all CSS selectors that have a content: pseudo-element rule
		preg_match_all( '/(\.[^}]*)\s*{\s*(content:)/s', $this->css, $matches );
		$selectors = $matches[1];

		// Select all icon- and fa- selectors from and split where there are commas
		foreach ( $selectors as $selector ) {
			preg_match_all( '/\.(icon-|fa-)([^,]*)\s*:before/s', $selector, $matches );
			$clean_selectors = $matches[2];

			// Create array of selectors
			foreach ( $clean_selectors as $clean_selector )
				$this->icons[] = $clean_selector;
		}

		// Alphabetize & join with comma for use in JS array
		sort( $this->icons );

	}

	/**
	 * Output [icon] shortcode
	 *
	 * Example:
	 * [icon name="flag" class="fw 2x spin" unprefixed_class="custom_class"]
	 *
	 * @param array   $atts Shortcode attributes
	 * @return  string <i> Font Awesome output
	 */
	function render_shortcode( $atts ) {
		extract( shortcode_atts( array(
					'name' => '',
					'class' => '',
					'unprefixed_class' => '',
					'title'     => '', /* For compatibility with other plugins */
					'size'      => '', /* For compatibility with other plugins */
					'space'     => '',
				), $atts )
		);

		// Include for backwards compatibility with Font Awesome More Icons plugin
		$title = $title ? 'title="' . $title . '" ' : '';
		$space = 'true' == $space ? '&nbsp;' : '';
		$size = $size ? ' '. $this->prefix . $size : '';

		// Remove "icon-" and "fa-" from name
		// This helps both:
		//  1. Incorrect shortcodes (when user includes full class name including prefix)
		//  2. Old shortcodes from other plugins that required prefixes
		$name = str_replace( 'icon-', '', $name );
		$name = str_replace( 'fa-', '', $name );

		// Add prefix to name
		$icon_name = $this->prefix . '-' . $name;

		// Remove "icon-" and "fa-" from classes
		$class = str_replace( 'icon-', '', $class );
		$class = str_replace( 'fa-', '', $class );

		// Remove extra spaces from class
		$class = trim( $class );
		$class = preg_replace( '/\s{3,}/', ' ', $class );

		// Add prefix to each class (separated by space)
		$class = $class ? ' ' . $this->prefix . '-' . str_replace( ' ', ' ' . $this->prefix . '-', $class ) : '';

		// Add unprefixed classes
		$class .= $unprefixed_class ? ' ' . $unprefixed_class : '';

		return '<i class="fa ' . $icon_name . $class . $size . '" ' . $title . '>' . $space . '</i>';
	}

	/**
	 * Registers and enqueues stylesheets for the administration panel and the
	 * public facing site.
	 */
	function do_scripts_and_styles() {
		global $wp_styles;

		// Deregister any existing Font Awesome CSS
		if ( $this->args['remove_existing_fa'] ) {
			// Loop through all registered styles and remove any that appear to be font-awesome
			foreach ( $wp_styles->registered as $script => $details ) {
				if ( strpos( $script, 'fontawesome' ) !== false || strpos( $script, 'font-awesome' ) !== false )
					wp_dequeue_style( $script );
			}
		}

		// Enqueue Font Awesome CSS
		wp_register_style( self::SLUG . '-font-awesome', $this->stylesheet_url, '', $this->args['version'] );
		wp_enqueue_style( self::SLUG . '-font-awesome' );
	}

	/*
	 * Runs when admin is initialized
	 */
	function admin_init() {
		if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'edit_pages' ) )
			return;

		if ( get_user_option( 'rich_editing' ) == 'true' ) {
			add_filter( 'mce_external_plugins', array( $this, 'register_tinymce_plugin' ) );
			add_filter( 'mce_buttons', array( $this, 'add_tinymce_buttons' ) );
		}
	}

	/**
	 * Load TinyMCE Font Awesome dropdown plugin
	 */
	function register_tinymce_plugin( $plugin_array ) {
		global $tinymce_version;

		// >= TinyMCE v4 - include newer plugin
		if ( version_compare( $tinymce_version, '4000', '>=' ) )
			$plugin_array['bfa_plugin'] = plugins_url( 'inc/js/tinymce-icons.js', __FILE__ );
		// < TinyMCE v4 - include old plugin
		else
			$plugin_array['bfa_plugin'] = plugins_url( 'inc/js/tinymce-icons-old.js', __FILE__ );

		return $plugin_array;
	}

	/*
     * Add TinyMCE dropdown element
     */
	function add_tinymce_buttons( $buttons ) {
		array_push( $buttons, 'bfaSelect' );

		return $buttons;
	}

	/**
	 * Add PHP variables in head for use by TinyMCE JavaScript
	 */
	function admin_head_variables() {
		if ( $this->css ) {
			$icon_list = implode( ",", $this->icons );
			?>
			<!-- Better Font Awesome PHP variables for use by TinyMCE JavaScript -->
			<script type='text/javascript'>
			var bfa_vars = {
			    'fa_prefix': '<?php echo $this->prefix; ?>',
			    'fa_icons': '<?php echo $icon_list; ?>',
			};
			</script>
			<!-- End Better Font Awesome PHP variables for use by TinyMCE JavaScript -->
		    <?php
		}
	}

	/*
	 * Load admin CSS to style TinyMCE dropdown
	 */
	public function custom_admin_css() {
		wp_enqueue_style( self::SLUG . '-admin-styles', plugins_url( 'inc/css/admin-styles.css', __FILE__ ) );
	}

	public function api_error_notice() {
		?>
	    <div class="updated error">
	        <p>
	        	<?php echo __( 'The attempt to connect to the jsDelivr Font Awesome API failed with the following error: ', 'bfa' ) . '<code>' . $this->get_api_data() . '</code>'; ?>
	        </p>
	    </div>
	    <?php
	}

	public function css_error_notice() {
		?>
	    <div class="updated error">
	        <p>
	        	<?php printf( __( 'The attempt to connect to the jsDelivr Font Awesome CDN failed with the following error: %s.', 'bfa' ), "<code>$this->css_fetch_error</code>" ); ?>
	        </p>
	    </div>
	    <?php
	}

	public function get_api_data() {
		return $this->jsdelivr_fetcher->get_api_data();
	}

	public function get_api_value( $value ) {
		return $this->jsdelivr_fetcher->get_api_value( $value );
	}

	public function api_fetch_succeeded() {
		return $this->jsdelivr_fetcher->api_fetch_succeeded();
	}

}
endif;
