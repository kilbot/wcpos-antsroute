<?php
/**
 * Plugin Name: WooCommerce POS AntsRoute Integration
 * Description: AntsRoute integration for WooCommerce POS (plus mobile phone field added to checkout)
 * Version: 0.0.1
 * Author: kilbot
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: my-plugin
 */

namespace WCPOS\AntsRoute;

if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly
}

class Init {
  private static $instance = null;

  public static function get_instance() {
      if ( null === self::$instance ) {
          self::$instance = new self();
      }
      return self::$instance;
  }

  public function __construct() {
    add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );
    add_filter( 'woocommerce_pos_locate_template', array( $this, 'custom_payment_template' ), 10, 2 );
  }

  /**
   * Only init our integrations for WC REST API requests
   */
  public function rest_api_init(): void {
    if ( function_exists('woocommerce_pos_request') && woocommerce_pos_request() ) {
      // init the mobile phone field for REST API
      include_once 'includes/mobile-phone-field.php';
      new Mobile_Phone_Field();
    }
  }

  /**
   * Load custom payment template for AntsRoute
   */
  public function custom_payment_template( $template, $template_name ) {
    if ( 'payment.php' === $template_name ) {
      $template = plugin_dir_path( __FILE__ ) . 'templates/payment.php';
    }
    return $template;
  }
}

// Initialize the plugin
Init::get_instance();
