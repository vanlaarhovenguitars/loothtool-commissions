<?php
/**
 * Commission Calculator — intercepts Dokan's commission hooks.
 *
 * Key principle: all commissions are calculated on item subtotal ONLY.
 * Shipping, tax, and payment processing fees are never part of the base.
 *
 * Hook sequence on a new order:
 *  1. dokan_checkout_update_order_meta       → capture_order_context (order-processor)
 *  2. dokan_prepare_for_calculation          → use_item_subtotal_as_base (this class)
 *  3. Dokan writes initial _dokan_vendor_to_pay
 *  4. dokan_new_order_processing_charge      → process_order (order-processor) overwrites it
 */

defined( 'ABSPATH' ) || exit;

class LT_Comm_Commission_Calculator {

	public static function init() {
		// Replace Dokan's base amount with item subtotal before % is applied.
		add_filter(
			'dokan_prepare_for_calculation',
			[ __CLASS__, 'use_item_subtotal_as_base' ],
			10, 4
		);

		// Override global commission % with our setting.
		add_filter(
			'dokan_get_vendor_percentage_commissions',
			[ __CLASS__, 'override_global_percentage' ],
			10, 2
		);
	}

	/**
	 * Return item subtotal as the base for commission calculation.
	 *
	 * Dokan passes the sub-order net amount (which can include shipping).
	 * We substitute the pure item subtotal so shipping/tax/fees are excluded.
	 *
	 * @param  float  $net_amount
	 * @param  string $commission_type
	 * @param  float  $commission_percentage
	 * @param  int    $order_item_count
	 * @return float
	 */
	public static function use_item_subtotal_as_base(
		$net_amount,
		$commission_type,
		$commission_percentage,
		$order_item_count
	) {
		$order = $GLOBALS['lt_comm_current_order'] ?? null;
		if ( ! $order instanceof WC_Order ) {
			return $net_amount; // Safe fallback.
		}

		$item_subtotal = 0.0;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$item_subtotal += (float) $item->get_total();
		}

		return $item_subtotal > 0 ? $item_subtotal : $net_amount;
	}

	/**
	 * Return the platform commission % from our settings.
	 *
	 * @param  float $percentage  Dokan's current value.
	 * @param  int   $vendor_id
	 * @return float
	 */
	public static function override_global_percentage( $percentage, $vendor_id ) {
		return (float) get_option( 'lt_comm_platform_percentage', 10.0 );
	}
}
