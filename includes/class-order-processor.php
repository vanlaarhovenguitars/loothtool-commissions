<?php
/**
 * Order Processor — hooks into WooCommerce/Dokan order events.
 *
 * Responsibilities:
 *  - Capture the current order into global context for the commission calculator.
 *  - After Dokan writes _dokan_vendor_to_pay, overwrite it with the split result.
 *  - Write a full audit trail to order meta.
 *
 * Audit meta written per order:
 *  _lt_comm_item_subtotal      float   Base used for all commission math
 *  _lt_comm_shipping_total     float   Excluded from commission base (audit only)
 *  _lt_comm_tax_total          float   Excluded from commission base (audit only)
 *  _lt_comm_processing_fees    float   Sum of WC fee line items (platform keeps these)
 *  _lt_comm_splits             array   Per-line-item breakdown
 *  _lt_comm_vendor_earnings    float   Amount Dokan will pay the primary vendor
 *  _lt_comm_platform_earnings  float   Loothtool's total take
 *  _lt_comm_calculated_at      string  ISO 8601 timestamp
 */

defined( 'ABSPATH' ) || exit;

class LT_Comm_Order_Processor {

	public static function init() {
		// Set global order context so the commission calculator can read the order.
		add_action( 'dokan_checkout_update_order_meta',       [ __CLASS__, 'capture_by_id' ], 5 );
		add_action( 'woocommerce_order_status_processing',    [ __CLASS__, 'capture_by_id' ], 5 );
		add_action( 'woocommerce_checkout_order_created',     [ __CLASS__, 'capture_by_order' ], 5 );

		// After Dokan finalises vendor payout amount — overwrite + write audit trail.
		add_action( 'dokan_new_order_processing_charge', [ __CLASS__, 'process_order' ], 20, 2 );

		// Also process on order status change (catches manual orders / admin edits).
		add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'on_status_change' ], 10, 3 );
	}

	public static function capture_by_id( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$GLOBALS['lt_comm_current_order'] = $order;
		}
	}

	public static function capture_by_order( $order ) {
		if ( $order instanceof WC_Order ) {
			$GLOBALS['lt_comm_current_order'] = $order;
		}
	}

	public static function on_status_change( $order_id, $old_status, $new_status ) {
		if ( $new_status !== 'processing' ) {
			return;
		}
		// Only process if not already calculated.
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( '_lt_comm_calculated_at' ) ) {
			return;
		}
		$vendor_id = (int) get_post_meta( $order_id, '_dokan_vendor_id', true );
		if ( ! $vendor_id ) {
			return;
		}
		self::process_order( $vendor_id, $order );
	}

	/**
	 * Main calculation entry point.
	 * Called by dokan_new_order_processing_charge (fires per vendor sub-order).
	 *
	 * @param int      $vendor_id
	 * @param WC_Order $order
	 */
	public static function process_order( $vendor_id, $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		// ── 1. Compute base amounts ──────────────────────────────────────────
		$item_subtotal   = 0.0;
		$processing_fees = 0.0;

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$item_subtotal += (float) $item->get_total();
		}
		foreach ( $order->get_items( 'fee' ) as $fee ) {
			// Fees may be negative (gateway surcharges stored as negative values).
			$processing_fees += abs( (float) $fee->get_total() );
		}

		$shipping_total = (float) $order->get_shipping_total();
		$tax_total      = (float) $order->get_total_tax();

		// ── 2. Compute per-line-item splits ──────────────────────────────────
		$all_splits          = [];
		$total_vendor_earn   = 0.0;
		$total_platform_earn = 0.0;

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$product_id = (int) $item->get_product_id();
			$line_total = (float) $item->get_total();
			$rules      = LT_Comm_Split_Rules::get_for_product( $product_id );
			$splits     = LT_Comm_Split_Rules::compute_splits( $rules, $line_total, $vendor_id );

			$all_splits[ $item_id ] = [
				'product_id'   => $product_id,
				'product_name' => $item->get_name(),
				'line_total'   => $line_total,
				'splits'       => $splits,
			];

			foreach ( $splits as $split ) {
				if ( $split['payee_type'] === 'platform' ) {
					$total_platform_earn += $split['amount'];
				} elseif ( (int) $split['payee_id'] === (int) $vendor_id ) {
					$total_vendor_earn += $split['amount'];
				}
				// Cross-vendor splits (designer ≠ fulfiller) are recorded in
				// audit trail; Dokan sub-order payouts handle only the primary
				// vendor. Secondary payees are settled via admin payout queue.
			}
		}

		// ── 3. Overwrite _dokan_vendor_to_pay with our calculated amount ─────
		$order->update_meta_data( '_dokan_vendor_to_pay', round( $total_vendor_earn, 2 ) );

		// ── 4. Write full audit trail ────────────────────────────────────────
		$order->update_meta_data( '_lt_comm_item_subtotal',     round( $item_subtotal, 2 ) );
		$order->update_meta_data( '_lt_comm_shipping_total',    round( $shipping_total, 2 ) );
		$order->update_meta_data( '_lt_comm_tax_total',         round( $tax_total, 2 ) );
		$order->update_meta_data( '_lt_comm_processing_fees',   round( $processing_fees, 2 ) );
		$order->update_meta_data( '_lt_comm_splits',            $all_splits );
		$order->update_meta_data( '_lt_comm_vendor_earnings',   round( $total_vendor_earn, 2 ) );
		$order->update_meta_data( '_lt_comm_platform_earnings', round( $total_platform_earn, 2 ) );
		$order->update_meta_data( '_lt_comm_calculated_at',     gmdate( 'c' ) );
		$order->save();

		// ── 5. Optionally write to order notes ───────────────────────────────
		if ( get_option( 'lt_comm_audit_log_enabled' ) ) {
			$note = sprintf(
				'LT Commissions: base=%s | vendor=%s | platform=%s | shipping=%s | tax=%s | fees=%s',
				wc_price( $item_subtotal ),
				wc_price( $total_vendor_earn ),
				wc_price( $total_platform_earn ),
				wc_price( $shipping_total ),
				wc_price( $tax_total ),
				wc_price( $processing_fees )
			);
			$order->add_order_note( $note );
		}
	}
}
