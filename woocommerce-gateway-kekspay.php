<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Plugin Name: KEKS Pay for WooCommerce
 * Plugin URI: https://www.kekspay.hr/
 * Description: KEKS Pay payment gateway for WooCommerce.
 * Version: 1.0
 * Requires at least: 5.0
 * Requires PHP: 5.6
 * Author: Neuralab
 * License: GPL v3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: kekspay
 * Domain Path: /languages
 *
 * WC requires at least: 3.3
 * WC tested up to: 4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

if ( ! function_exists( 'kekspay_wc_active' ) ) {
  /**
   * Return true if the WooCommerce plugin is active or false otherwise.
   *
   * @since 0.1
   * @return boolean
   */
  function kekspay_wc_active() {
    if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ), true ) ) {
      return true;
    }
    return false;
  }
}

if ( ! function_exists( 'kekspay_admin_notice_missing_woocommerce' ) ) {
  /**
   * Echo admin notice HTML for missing WooCommerce plugin.
   *
   * @since 0.1
   */
  function kekspay_admin_notice_missing_woocommerce() {
    /** translators: 1. URL link. */
    echo '<div class="error"><p><strong>' . sprintf( esc_html__( 'KEKS Pay requires WooCommerce to be installed and active. You can download %s here.', 'kekspay' ), '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>' ) . '</strong></p></div>';
  }
}
if ( ! kekspay_wc_active() ) {
  add_action( 'admin_notices', 'kekspay_admin_notice_missing_woocommerce' );
  return;
}

if ( ! class_exists( 'WC_Kekspay' ) ) {
  /**
   * The main plugin class.
   *
   * @since 0.1
   */
  class WC_Kekspay {
    /**
     * Instance of the current class, null before first usage.
     *
     * @var WC_Kekspay
     */
    protected static $instance = null;

    /**
     * Instance of Kekspay Instant Payment Notification class.
     *
     * @var Kekspay_IPN
     */
    private $ipn;

    /**
     * Instance of Kekspay Data class.
     *
     * @var Kekspay_Data
     */
    private $data;

    /**
     * Instance of Kekspay Logger class.
     *
     * @var Kekspay_Logger
     */
    private $logger;

    /**
     * Class constructor, initialize constants and settings.
     *
     * @since 0.1
     */
    protected function __construct() {
      self::register_constants();

      add_action( 'plugins_loaded', array( $this, 'check_requirements' ) );

      require_once( 'includes/utilities/class-kekspay-data.php' );
      require_once( 'includes/utilities/class-kekspay-logger.php' );

      require_once( 'includes/core/class-kekspay-ipn.php' );
      require_once( 'includes/core/class-kekspay-payment-gateway.php' );

      add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateway' ) );
      add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_settings_link' ) );

      add_action( 'admin_enqueue_scripts', array( $this, 'register_admin_script' ) );
      add_action( 'wp_enqueue_scripts', array( $this, 'register_client_script' ) );
      add_action( 'plugins_loaded', array( $this, 'load_textdomain' ), 5 );
      add_action( 'admin_init', array( $this, 'check_settings' ), 20 );
      add_action( 'admin_init', array( $this, 'check_for_other_kekspay_gateways' ), 1 );
      add_action( 'activated_plugin', array( $this, 'set_kekspay_plugins_check_required' ) );
      add_action( 'woocommerce_admin_field_payment_gateways', array( $this, 'set_kekspay_plugins_check_required' ) );
    }

    /**
     * Register plugin's constants.
     */
    public static function register_constants() {
      if ( ! defined( 'KEKSPAY_PLUGIN_ID' ) ) {
        define( 'KEKSPAY_PLUGIN_ID', 'erste-kekspay-woocommerce' );
      }
      if ( ! defined( 'KEKSPAY_PLUGIN_VERSION' ) ) {
        define( 'KEKSPAY_PLUGIN_VERSION', '1.0' );
      }
      if ( ! defined( 'KEKSPAY_DIR_PATH' ) ) {
        define( 'KEKSPAY_DIR_PATH', plugin_dir_path( __FILE__ ) );
      }
      if ( ! defined( 'KEKSPAY_DIR_URL' ) ) {
        define( 'KEKSPAY_DIR_URL', plugin_dir_url( __FILE__ ) );
      }
      if ( ! defined( 'KEKSPAY_ADMIN_SETTINGS_URL' ) ) {
        define( 'KEKSPAY_ADMIN_SETTINGS_URL', get_admin_url( null, 'admin.php?page=wc-settings&tab=checkout&section=' . KEKSPAY_PLUGIN_ID ) );
      }
      if ( ! defined( 'KEKSPAY_REQUIRED_PHP_VERSION' ) ) {
        define( 'KEKSPAY_REQUIRED_PHP_VERSION', '5.6' );
      }
      if ( ! defined( 'KEKSPAY_REQUIRED_WC_VERSION' ) ) {
        define( 'KEKSPAY_REQUIRED_WC_VERSION', '3.3' );
      }
    }

    /**
     * Load plugin's textdomain.
     */
    public function load_textdomain() {
      load_plugin_textdomain( 'kekspay', false, basename( dirname( __FILE__ ) ) . '/languages' );
    }

    /**
     * Check versions of requirements.
     */
    public function check_requirements() {
      $requirements = array(
        'php' => array(
          'current_version' => phpversion(),
          'requred_version' => KEKSPAY_REQUIRED_PHP_VERSION,
          'name'            => 'PHP',
        ),
        'wc'  => array(
          'current_version' => WC_VERSION,
          'requred_version' => KEKSPAY_REQUIRED_WC_VERSION,
          'name'            => 'WooCommerce',
        ),
      );

      $error_notices = array();

      foreach ( $requirements as $requirement ) {
        if ( version_compare( $requirement['current_version'], $requirement['requred_version'], '<' ) ) {
          $error_notices[] = sprintf(
            __( 'The minimum required version of %1$s is %2$s. The version you are running is %3$s. Please update %1$s in order to use KEKS Pay.', 'kekspay' ),
            $requirement['name'],
            $requirement['requred_version'],
            $requirement['current_version']
          );
        }
      }

      if ( $error_notices ) {
        add_action( 'admin_init', array( $this, 'deactivate_self' ) );

        foreach ( $error_notices as $error_notice ) {
          $this->admin_notice( $error_notice );
        }
      }
    }

    /**
     * Add KEKS Pay payment method.
     */
    public function add_gateway( $methods ) {
      $methods[] = 'Kekspay_Payment_Gateway';
      return $methods;
    }

    /**
     * Check gateway settings and dispatch notice.
     */
    public function check_settings() {
      // If payment gateway is not enabled bail.
      if ( ! Kekspay_Data::enabled() ) {
        return;
      }

      // Check if gateway is currently in test mode.
      if ( Kekspay_Data::test_mode() ) {
        $this->admin_notice( __( 'KEKS Pay is currently in sandbox/test mode, disable it for live web shops.', 'kekspay' ), 'warning' );
      }

      // Check if all setting keys required for gateway to work are set.
      if ( ! Kekspay_Data::required_keys_set() ) {
        $this->admin_notice( __( 'KEKS Pay is almost ready for use, Webshop data needs to be set up before using the gateway.', 'kekspay' ), 'info' );
      }

      // Check if correct currency is set in webshop.
      if ( ! Kekspay_Data::currency_supported() ) {
        $this->admin_notice( __( 'KEKS Pay does not support your currency, please set it to "HRK".', 'kekspay' ), 'warning' );
      }
    }

    /**
     * Check if there are other KEKS Pay gateways.
     */
    public static function check_for_other_kekspay_gateways() {
      if ( ! get_option( 'kekspay_plugins_check_required' ) ) {
        return;
      }

      delete_option( 'kekspay_plugins_check_required' );

      // Check if there already is payment method with id "nrlb-kekspay-woocommerce".
      $payment_gateways = WC_Payment_Gateways::instance()->payment_gateways();

      if ( isset( $payment_gateways[ KEKSPAY_PLUGIN_ID ] ) && ! $payment_gateways[ KEKSPAY_PLUGIN_ID ] instanceof Kekspay_Payment_Gateway ) {
        self::admin_notice( __( 'You can only have one KEKS Pay Payment Gateway active at the same time. KEKS Pay for WooCommerce plugin has been deactivated.', 'kekspay' ) );

        self::deactivate_self();
      }
    }

    /**
     * Set check required.
     */
    public static function set_kekspay_plugins_check_required() {
      update_option( 'kekspay_plugins_check_required', 'yes' );
    }

    /**
     * Deactivate plugin.
     */
    public static function deactivate_self() {
      remove_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( self::get_instance(), 'add_settings_link' ) );
      remove_action( 'admin_init', array( self::get_instance(), 'check_settings' ), 20 );

      deactivate_plugins( plugin_basename( __FILE__ ) );
      unset( $_GET['activate'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    }

    /**
     * Add admin notice.
     *
     * @param  string $notice Notice content.
     * @param  string $type   Notice type.
     */
    public static function admin_notice( $notice, $type = 'error' ) {
      add_action(
        'admin_notices',
        function() use ( $notice, $type ) {
          printf( '<div class="notice notice-%2$s"><p>%1$s</p></div>', $notice, $type );
        }
      );
    }

    /**
     * Register plugin's admin JS script.
     */
    public function register_admin_script() {
      $screen    = get_current_screen();
      $screen_id = $screen ? $screen->id : '';

      if ( strpos( $screen_id, 'wc-settings' ) !== false ) {
        $section = filter_input( INPUT_GET, 'section', FILTER_SANITIZE_STRING );

        if ( isset( $section ) && KEKSPAY_PLUGIN_ID === $section ) {
          wp_enqueue_style( 'kekspay-admin-style', KEKSPAY_DIR_URL . '/assets/css/kekspay-admin.css', array(), KEKSPAY_PLUGIN_VERSION );
          wp_enqueue_script( 'kekspay-admin-script', KEKSPAY_DIR_URL . '/assets/js/kekspay-admin.js', array( 'jquery' ), KEKSPAY_PLUGIN_VERSION, true );
          wp_localize_script(
            'kekspay-admin-script',
            'kekspayAdminScript',
            array(
              'url'               => admin_url( 'admin-ajax.php' ),
              'test_mode'         => Kekspay_Data::test_mode(),
              'msg_error_default' => __( 'Something went wrong, please refresh the page and try again.', 'kekspay' ),
            )
          );
        }
      }
    }

    /**
     * Register plugin's client JS script.
     */
    public function register_client_script() {
      wp_enqueue_style( 'kekspay-client-style', KEKSPAY_DIR_URL . '/assets/css/kekspay.css', array(), KEKSPAY_PLUGIN_VERSION );
      wp_enqueue_script( 'kekspay-client-script', KEKSPAY_DIR_URL . '/assets/js/kekspay.js', array( 'jquery' ), KEKSPAY_PLUGIN_VERSION, true );
    }

    /**
     * Adds the link to the settings page on the plugins WP page.
     *
     * @param array   $links
     * @return array
     */
    public function add_settings_link( $links ) {
      $settings_link = '<a href="' . KEKSPAY_ADMIN_SETTINGS_URL . '">' . __( 'Settings', 'kekspay' ) . '</a>';
      array_unshift( $links, $settings_link );

      return $links;
    }

    /**
     * Installation procedure.
     *
     * @static
     */
    public static function install() {
      if ( ! current_user_can( 'activate_plugins' ) ) {
        return false;
      }

      self::set_kekspay_plugins_check_required();
      self::register_constants();
    }

    /**
     * Uninstallation procedure.
     *
     * @static
     */
    public static function uninstall() {
      if ( ! current_user_can( 'activate_plugins' ) ) {
        return false;
      }

      self::register_constants();
      Kekspay_Data::delete_settings();

      wp_cache_flush();
    }

    /**
     * Deactivation function.
     *
     * @static
     */
    public static function deactivate() {
      if ( ! current_user_can( 'activate_plugins' ) ) {
        return false;
      }

      self::register_constants();
    }

    /**
     * Return class instance.
     *
     * @static
     * @return WC_Kekspay
     */
    public static function get_instance() {
      if ( is_null( self::$instance ) ) {
        self::$instance = new self();
      }
      return self::$instance;
    }

    /**
     * Cloning is forbidden.
     *
     * @since 0.1
     */
    public function __clone() {
      return wp_die( 'Cloning is forbidden!' );
    }

    /**
     * Unserializing instances of this class is forbidden.
     *
     * @since 0.1
     */
    public function __wakeup() {
      return wp_die( 'Unserializing instances is forbidden!' );
    }
  }
}

register_activation_hook( __FILE__, array( 'WC_Kekspay', 'install' ) );
register_uninstall_hook( __FILE__, array( 'WC_Kekspay', 'uninstall' ) );
register_deactivation_hook( __FILE__, array( 'WC_Kekspay', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'WC_Kekspay', 'get_instance' ), 0 );
