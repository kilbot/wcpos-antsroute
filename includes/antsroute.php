<?php

namespace WCPOS\AntsRoute;

use WC_Order;

class AntsRoute {

  public function __construct() {
    // add_action( 'woocommerce_before_order_object_save', array( $this, 'before_order_object_save' ), 20, 2 );
  }

  /**
   * Add additional meta data to the order before it is saved.
   *
   * @param WC_Order $order The object being saved.
   *
   * @throws WC_Data_Exception
   */
  public function before_order_object_save( WC_Order $order ): void {
    // only target orders created via POS
    if( 'woocommerce-pos' !== $order->get_created_via() ) {
      return;
    }
  }

}
