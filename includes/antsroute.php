<?php

namespace WCPOS\AntsRoute;

use WC_Order;
use WC_Order_Item_Shipping;

class AntsRoute_Checkout {

  public function __construct() {
    add_filter( 'woocommerce_pos_locate_template', array( $this, 'custom_payment_template' ), 10, 2 );
    // add_action( 'woocommerce_pos_before_pay', array( $this, 'calculate_shipping' ) );
  }

  /**
   * Load custom payment template for AntsRoute
   */
  public function custom_payment_template( $template, $template_name ) {
    if ( 'payment.php' === $template_name ) {
      $this->calculate_shipping();
      $template = plugin_dir_path( __FILE__ ) . '../templates/payment.php';
    }
    return $template;
  }

  /**
   * Calculate shipping for AntsRoute
   */
  private function calculate_shipping() {
    try {
      $package = false;
      $order_id = get_query_var('order-pay');
      $order = wc_get_order($order_id);

      if ($order) {
        $contents = array();
          foreach ($order->get_items() as $item_id => $item) {
              // Prepare the array element for each item
              $contents[$item_id] = array(
                  'data'     => $item->get_product(), // WC_Product object
                  'quantity' => $item->get_quantity(),
                  // Include other keys as needed, like 'line_total', 'line_subtotal', etc.
              );
          }
      
        $package = array(
            'contents'        => $contents, // Items in the order
            'contents_cost'   => $order->get_subtotal(), // Subtotal of items
            'applied_coupons' => $order->get_used_coupons(), // Coupons applied to the order
            'user'            => array('ID' => $order->get_user_id()), // User data
            'destination'     => array(
                'country'   => $order->get_shipping_country(),
                'state'     => $order->get_shipping_state(),
                'postcode'  => $order->get_shipping_postcode(),
                'city'      => $order->get_shipping_city(),
                'address'   => $order->get_shipping_address_1(),
                'address_2' => $order->get_shipping_address_2()
            ),
        );

        WC()->session->set('shipping_for_package_0', array('package' => $package));
        WC()->shipping->calculate_shipping(array($package));
        $shipping_methods = WC()->shipping->get_packages()[0]['rates'];
        $shipping_method = $order->get_shipping_method();
        $chosen_method = reset( $shipping_methods ); // default to first shipping method

        // Check if the shipping has changed
        if ( isset( $_POST['shipping_method'] ) ) {
          $shipping_method = $_POST['shipping_method'];
        }

        // Find the chosen shipping method
        foreach ( $shipping_methods as $method ) {
          if ( $method->get_id() === $shipping_method || $method->get_label() === $shipping_method ) {
            $chosen_method = $method;
            break;
          }
        }

        // Clear existing shipping methods
        foreach ($order->get_items('shipping') as $item_id => $item) {
            $order->remove_item($item_id);
        }
      
        // Add the chosen shipping method
        $item = new WC_Order_Item_Shipping();
        $item->set_method_title($chosen_method->get_label());
        $item->set_method_id($chosen_method->get_id());
        $item->set_total($chosen_method->get_cost());
      
        // Add shipping item to the order
        $order->add_item($item);
        $order->calculate_totals();
        $order->save();
      }
    } catch ( \Exception $e ) {
      echo 'Error: ' . $e->getMessage();
    }
  }

}