<?php

namespace WCPOS\AntsRoute;

class AntsRoute_Checkout {

	public function __construct() {
		add_filter( 'woocommerce_pos_locate_template', array( $this, 'custom_payment_template' ), 10, 2 );
		add_action( 'woocommerce_pay_order_before_payment', array( $this, 'add_order_delivery_fields' ) );
		add_action( 'woocommerce_before_pay_action', array( $this, 'add_order_delivery_info' ) );
		add_action( 'wp_ajax_wc_antsroute_get_slots_on_date', array( $this, 'get_slots_on_date' ), 1 );
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
					$found = false;

					// Loop through existing shipping items
					foreach ( $order->get_items( 'shipping' ) as $item_id => $item ) {
						if ( $item->get_method_id() === $chosen_method->get_method_id() ) {
								// Update the total if this shipping method already exists
								$item->set_total( $chosen_method->get_cost() );
								$order->calculate_totals();
								$found = true;
						} else {
								// Remove other shipping methods
								$order->remove_item( $item_id );
						}
					}

					// Add the chosen method if it wasn't found
					if ( ! $found ) {
							$item = new \WC_Order_Item_Shipping();
							$item->set_method_title( $chosen_method->get_label() );
							$item->set_method_id( $chosen_method->get_id() );
							$item->set_total( $chosen_method->get_cost() );
							$order->add_item( $item );
					}

					// set the chosen shipping method to the session, needed by AntsRoute later
					WC()->session->set( 'chosen_shipping_methods', null );
					WC()->session->set( 'chosen_shipping_methods', array( $chosen_method->get_id() ) );
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
		// Check if AntsRoute configured for the shipping method
		$order_id           = get_query_var( 'order-pay' );
		$order              = wc_get_order( $order_id );
		$shipping_method_id = '';

		if ( $order ) {
			foreach ( $order->get_items( 'shipping' ) as $item_id => $item ) {
				$shipping_method_id = $item->get_method_id();
			}
		}

		if ( 'local_pickup' === $shipping_method_id ) {
			return;
		}

		try {
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

	/**
	 *
	 */
	public function get_slots_on_date() {
		// check if pos
		$referer        = wp_get_referer();
		$url_components = parse_url( $referer );
		$path           = $url_components['path'];
		if ( strpos( $path, 'wcpos-checkout/order-pay' ) === false ) {
			return;
		}

		$path_segments = explode( '/', trim( $path, '/' ) );
		$order_id      = end( $path_segments );

		$response = array(
			'success'     => false,
			'reservation' => false,
		);

		$posted_data = wp_unslash( $_POST );
		if ( empty( $posted_data['date'] ) ) {
			wp_send_json( $response );
		}

		$checkout_data = $posted_data['checkout'];
		if ( empty( $checkout_data ) ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Invalid Billing details.', 'wc-antsroute' ),
				)
			);
		}
		$_POST['order_id']       = $order_id;
		$_POST['force_order_id'] = 'yes';
		if ( empty( $order_id ) ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Invalid order id.', 'wc-antsroute' ),
				)
			);
		}
		// if ( ! \WC_AntsRoute_Config::is_admin_page() ) {
		// wp_send_json_error(
		// array(
		// 'message' => esc_html__( 'Invalid Request.', 'wc-antsroute' ),
		// )
		// );
		// }
		$posted_date = $posted_data['date'];
		$mode_case   = \WC_AntsRoute_Config::mode_case( $order_id );
		/**
		 * Case 1 & 2
		 */
		$token  = \WC_AntsRoute_Config::get_config( 'api_key', $order_id );
		$substr = strtolower( substr( $token, 0, 10 ) );
		if ( \WC_AntsRoute::AVAILABILITY_CASE === $mode_case ) {

			$external_id                        = $substr . '-' . $checkout_data['customer_user'];
			$checkout_data['different_address'] = 1;
			$customer                           = \WC_AntsRoute_Cart::build_customer_data( $checkout_data, true, $external_id );
			$user_id                            = $customer['externalId'];
			$body['customer']                   = $customer;
			$antsroute_api                      = new \WC_AntsRoute_Api( $token, \WC_AntsRoute_Api::AVAILABILITY_SEARCH, $body, $mode_case, $order_id );
			/**
			 * Delete UnReserved/ Unconfirmed Availabilities
			 */
			$antsroute_api->remove_availabilities( $user_id );
			/**
			 * Case 1 Enable availability check: See availabilities
			 */
			$response['html']    = \WC_AntsRoute_TimeSlot::see_availabilities_btn( $posted_date );
			$response['success'] = true;
		}
		/**
		 * Case 2 Disable availability check: Customer is king
		 */
		if ( \WC_AntsRoute::CUSTOMER_KING_CASE === $mode_case || \WC_AntsRoute::SHOPOWNER_KING_CASE === $mode_case ) {
			$posted_date    = filter_input( INPUT_POST, 'date' );
			$date_formatted = \WC_AntsRoute_TimeSlot::get_date_formatted( $posted_date );
			if ( \WC_AntsRoute::SHOPOWNER_KING_CASE === $mode_case ) {
				$timeslots = \WC_AntsRoute_TimeSlot::get_predefined_time_slots( $posted_date, $order_id );
			}
			if ( \WC_AntsRoute::CUSTOMER_KING_CASE === $mode_case ) {
				$timeslots = \WC_AntsRoute_TimeSlot::get_timeslot_data( $posted_date, $order_id );
			}

			if ( $timeslots ) {
				$response['html']    = \WC_AntsRoute_TimeSlot::time_slot_html( $timeslots, $date_formatted ) . \WC_AntsRoute_TimeSlot::confirm_btn();
				$response['success'] = true;
			}
		}
		wp_send_json( $response );
	}
}
