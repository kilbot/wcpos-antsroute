<?php

namespace WCPOS\AntsRoute;

class AntsRoute_Checkout {

	public function __construct() {
		add_filter( 'woocommerce_pos_locate_template', array( $this, 'custom_payment_template' ), 10, 2 );
		add_action( 'woocommerce_pay_order_before_payment', array( $this, 'add_order_delivery_fields' ) );
		add_action( 'woocommerce_before_pay_action', array( $this, 'add_order_delivery_info' ) );
	}

	/**
	 * Load custom payment template for AntsRoute.
	 */
	public function custom_payment_template( $path, $template ) {
		if ( 'payment.php' === $template ) {
			$this->calculate_shipping();
			$path = plugin_dir_path( __FILE__ ) . '../templates/payment.php';
		}

		return $path;
	}

	/**
	 * Calculate shipping for AntsRoute.
	 */
	private function calculate_shipping() {
		try {
			$package  = false;
			$order_id = get_query_var( 'order-pay' );
			$order    = wc_get_order( $order_id );

			if ( $order ) {
				$contents = array();
				foreach ( $order->get_items() as $item_id => $item ) {
					// Prepare the array element for each item
					$contents[ $item_id ] = array(
						'data'     => $item->get_product(), // WC_Product object
						'quantity' => $item->get_quantity(),
						// Include other keys as needed, like 'line_total', 'line_subtotal', etc.
					);
				}

				$package = array(
					'contents'        => $contents, // Items in the order
					'contents_cost'   => $order->get_subtotal(), // Subtotal of items
					'applied_coupons' => $order->get_used_coupons(), // Coupons applied to the order
					'user'            => array( 'ID' => $order->get_user_id() ), // User data
					'destination'     => array(
						'country'   => $order->get_shipping_country(),
						'state'     => $order->get_shipping_state(),
						'postcode'  => $order->get_shipping_postcode(),
						'city'      => $order->get_shipping_city(),
						'address'   => $order->get_shipping_address_1(),
						'address_2' => $order->get_shipping_address_2(),
					),
				);

				WC()->session->set( 'shipping_for_package_0', array( 'package' => $package ) );
				WC()->shipping->calculate_shipping( array( $package ) );
				$shipping_methods = WC()->shipping->get_packages()[0]['rates'];
				$shipping_method  = $order->get_shipping_method();
				$chosen_method    = reset( $shipping_methods ); // default to first shipping method

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
				foreach ( $order->get_items( 'shipping' ) as $item_id => $item ) {
					$order->remove_item( $item_id );
				}

				// Do sanity check for shipping address
				if ( ! $order->has_shipping_address() ) {
					$local_pickup = false;

					// check if 'local_pickup' is a valid shipping method
					foreach ( $shipping_methods as $method_id => $method ) {
						if ( strpos( $method->get_method_id(), 'local_pickup' ) !== false ) {
								$local_pickup = $method;
								break;
						}
					}

					// Alert the user to let them know that they need to enter a shipping address
					if ( isset( $_POST['shipping_method'] ) && $_POST['shipping_method'] !== 'local_pickup' ) {
						\wc_add_notice( 'Customer has no shipping address', 'error' );
					}

					// If 'local_pickup' is a valid shipping method, use it
					$chosen_method = $local_pickup ? $local_pickup : null;
				}

				// Add the chosen shipping method
				if ( $chosen_method ) {
					$item = new \WC_Order_Item_Shipping();
					$item->set_method_title( $chosen_method->get_label() );
					$item->set_method_id( $chosen_method->get_id() );
					$item->set_total( $chosen_method->get_cost() );
					$order->add_item( $item );
				}

				// Recalculate and save the order totals
				$order->calculate_totals();
				$order->save();
			}
		} catch ( \Exception $e ) {
			echo 'Error: ' . $e->getMessage();
		}
	}

	/**
	 * Add order delivery fields.
	 */
	public function add_order_delivery_fields() {
		try {
			// Check if AntsRoute configured for the shipping method
			$order_id = get_query_var( 'order-pay' );
			$order    = wc_get_order( $order_id );

			if ( ! \WC_AntsRoute_Config::method_is_configured( \WC_AntsRoute_Config::activate_mode(), $order->get_meta( '_shipping_method_id' ) ) ) {
				return;
			}

			// Check if the class exists
			if ( class_exists( 'WC_AntsRoute_Checkout' ) ) {
				// Call the static method init to get the instance
				$antsRouteCheckout = \WC_AntsRoute_Checkout::init();

				// Call the instance method display_checkout_fields
				if ( method_exists( $antsRouteCheckout, 'display_checkout_fields' ) ) {
					$antsRouteCheckout->display_checkout_fields();
				} else {
					throw new \Exception( 'Method display_checkout_fields does not exist in WC_AntsRoute_Checkout' );
				}
			} else {
				throw new \Exception( 'Class WC_AntsRoute_Checkout does not exist' );
			}
		} catch ( \Exception $e ) {
			// Handle exception
			echo 'Caught exception: ', $e->getMessage(), "\n";
		}
	}

	/**
	 * Add order delivery info.
	 *
	 * @param \WC_Order $order
	 */
	public function add_order_delivery_info( $order ) {
		try {
			if ( class_exists( 'WC_AntsRoute_Checkout' ) ) {
				$antsRouteCheckout = \WC_AntsRoute_Checkout::init();
				// Call the instance method display_checkout_fields
				if ( method_exists( $antsRouteCheckout, 'add_order_delivery_info' ) ) {
					$antsRouteCheckout->add_order_delivery_info( $order->get_id() );
				} else {
					throw new \Exception( 'Method display_checkout_fields does not exist in WC_AntsRoute_Checkout' );
				}
			}
		} catch ( \Exception $e ) {
			echo 'Error: ' . $e->getMessage();
		}
	}
}
