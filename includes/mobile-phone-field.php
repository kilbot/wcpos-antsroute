<?php

namespace WCPOS\AntsRoute;

use WC_Order;

class Mobile_Phone_Field {

	public function __construct() {
		add_action( 'woocommerce_before_order_object_save', array( $this, 'before_order_object_save' ), 20, 2 );
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
		if ( 'woocommerce-pos' !== $order->get_created_via() ) {
			return;
		}

		$mobile_phone  = $order->get_meta( '_billing_mobile_wpbiztextwc_phone' );
		$billing_phone = $order->get_billing_phone();

		// if there is no mobile phone meta and there is a billing phone, use that
		if ( empty( $mobile_phone ) && ! empty( $billing_phone ) ) {
			$order->update_meta_data( '_billing_mobile_wpbiztextwc_phone', $this->wpbiztextwc_format_mobile_number( $billing_phone ) );
		}
	}

	/**
	 * This a direct copy from the wpbiztextw plugin to match their formatting
	 */
	private function wpbiztextwc_format_mobile_number( $phone_number ) {
		// remove all characters
		$pattern_remove     = '/\D/';
		$replacement_remove = '';

		// format (xxx) xxx-xxxx
		$mobile_phone       = preg_replace( $pattern_remove, $replacement_remove, $phone_number );
		$pattern_format     = '/(\d{3})(\d{3})(\d{4})/';
		$replacement_format = '($1) $2-$3';

		$formated_number = preg_replace( $pattern_format, $replacement_format, $mobile_phone );

		return $formated_number;
	}
}
