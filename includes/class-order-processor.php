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

		// Reliable fallback: fires after Dokan has saved _dokan_vendor_id to the order.
		// Catches COD/offline orders where status→processing fires before Dokan saves vendor ID.
		add_action( 'dokan_checkout_update_order_meta', [ __CLASS__, 'maybe_process_after_dokan' ], 99 );

		// Also process on order status change (catches manual orders / admin edits).
		add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'on_status_change' ], 10, 3 );
	}

	/**
	 * Fires late on dokan_checkout_update_order_meta — at this point Dokan has
	 * already written _dokan_vendor_id. If the order is already processing and
	 * hasn't been calculated yet, run it now.
	 */
	public static function maybe_process_after_dokan( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( '_lt_comm_calculated_at' ) ) {
			return;
		}
		if ( ! in_array( $order->get_status(), [ 'processing', 'completed' ], true ) ) {
			return;
		}
		$vendor_id = (int) $order->get_meta( '_dokan_vendor_id' );
		if ( ! $vendor_id ) {
			return;
		}
		$GLOBALS['lt_comm_current_order'] = $order;
		self::process_order( $vendor_id, $order );
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

		// Most gateways don't add WC fee line items — calculate from payment method.
		if ( $processing_fees < 0.01 ) {
			$processing_fees = self::calculate_gateway_fee( $order );
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
			$rules      = LT_Comm_Split_Rules::get_for_product( $product_id, $vendor_id );
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

		// ── 3. Compute full vendor payout ────────────────────────────────────
		// Vendor receives their item commission + full shipping + full tax.
		// Payment processing fees are deducted from the vendor (not the platform).
		$vendor_payout = round( $total_vendor_earn + $shipping_total + $tax_total - $processing_fees, 2 );

		// ── 4. Overwrite _dokan_vendor_to_pay with our calculated payout ─────
		$order->update_meta_data( '_dokan_vendor_to_pay', $vendor_payout );

		// ── 5. Write full audit trail ────────────────────────────────────────
		$order->update_meta_data( '_lt_comm_item_subtotal',     round( $item_subtotal, 2 ) );
		$order->update_meta_data( '_lt_comm_shipping_total',    round( $shipping_total, 2 ) );
		$order->update_meta_data( '_lt_comm_tax_total',         round( $tax_total, 2 ) );
		$order->update_meta_data( '_lt_comm_processing_fees',   round( $processing_fees, 2 ) );
		$order->update_meta_data( '_lt_comm_splits',            $all_splits );
		$order->update_meta_data( '_lt_comm_vendor_earnings',   round( $total_vendor_earn, 2 ) );
		$order->update_meta_data( '_lt_comm_vendor_payout',     $vendor_payout );
		$order->update_meta_data( '_lt_comm_platform_earnings', round( $total_platform_earn, 2 ) );
		$order->update_meta_data( '_lt_comm_calculated_at',     gmdate( 'c' ) );
		$order->save();

		// ── 6. Correct Dokan vendor balance table ────────────────────────────
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'dokan_vendor_balance',
			[ 'debit' => $vendor_payout ],
			[ 'trn_id' => $order->get_id(), 'vendor_id' => $vendor_id, 'trn_type' => 'dokan_orders' ],
			[ '%f' ],
			[ '%d', '%d', '%s' ]
		);

		// ── 7. Optionally write to order notes ───────────────────────────────
		if ( get_option( 'lt_comm_audit_log_enabled' ) ) {
			$note = sprintf(
				'LT Commissions: item_base=%s | item_commission=%s | platform=%s | shipping=%s | tax=%s | fees=-%s | vendor_payout=%s',
				wc_price( $item_subtotal ),
				wc_price( $total_vendor_earn ),
				wc_price( $total_platform_earn ),
				wc_price( $shipping_total ),
				wc_price( $tax_total ),
				wc_price( $processing_fees ),
				wc_price( $vendor_payout )
			);
			$order->add_order_note( $note );
		}
	}

	/**
	 * Calculate the payment gateway processing fee for an order.
	 *
	 * Most gateways (Stripe, PayPal) never add fee line items to WC orders,
	 * so we calculate based on published rates per payment method.
	 *
	 * Rates current as of 2026. Update here if Stripe/PayPal change pricing.
	 *
	 * @param  WC_Order $order
	 * @return float
	 */
	public static function calculate_gateway_fee( WC_Order $order ) : float {
		$method = $order->get_payment_method();
		$total  = (float) $order->get_total();

		if ( ! $method || $total <= 0 ) {
			return 0.0;
		}

		// ── Stripe ────────────────────────────────────────────────────────────
		if ( strpos( $method, 'stripe' ) !== false ) {

			// Buy Now Pay Later — higher rates.
			if ( $method === 'stripe_affirm' ) {
				return round( $total * 0.06 + 0.30, 2 );       // 6% + $0.30
			}
			if ( $method === 'stripe_afterpay_clearpay' ) {
				return round( $total * 0.06 + 0.30, 2 );       // 6% + $0.30
			}
			if ( $method === 'stripe_klarna' ) {
				return round( $total * 0.0599 + 0.30, 2 );     // 5.99% + $0.30
			}
			if ( $method === 'stripe_zip' ) {
				return round( $total * 0.045 + 0.30, 2 );      // 4.5% + $0.30
			}

			// ACH / bank debits — low rate, capped.
			if ( in_array( $method, [
				'stripe_ach_debit',
				'stripe_ach_credit_transfer',
				'stripe_sepa',
				'stripe_sepa_debit',
				'stripe_becs_debit',
				'stripe_bacs_debit',
			], true ) ) {
				return min( round( $total * 0.008, 2 ), 5.00 ); // 0.8%, max $5
			}

			// iDEAL — flat fee.
			if ( $method === 'stripe_ideal' ) {
				return 0.80;
			}

			// Bank redirects (Bancontact, SOFORT, EPS, giropay).
			if ( in_array( $method, [
				'stripe_bancontact',
				'stripe_sofort',
				'stripe_eps',
				'stripe_giropay',
			], true ) ) {
				return round( $total * 0.014 + 0.30, 2 );      // 1.4% + $0.30
			}

			// Przelewy24.
			if ( $method === 'stripe_p24' ) {
				return round( $total * 0.022 + 0.30, 2 );      // 2.2% + $0.30
			}

			// Default Stripe: cards, Apple Pay, Google Pay, Alipay, WeChat Pay.
			// 2.9% + $0.30 — international adds +1.5% but we can't detect that from WC.
			return round( $total * 0.029 + 0.30, 2 );
		}

		// ── PayPal ────────────────────────────────────────────────────────────
		if ( strpos( $method, 'paypal' ) !== false || strpos( $method, 'ppcp' ) !== false ) {

			// Venmo (US only).
			if ( strpos( $method, 'venmo' ) !== false ) {
				return round( $total * 0.019 + 0.10, 2 );      // 1.9% + $0.10
			}

			// Pay Later / Buy Now Pay Later.
			if ( strpos( $method, 'pay_later' ) !== false || strpos( $method, 'paylater' ) !== false ) {
				return round( $total * 0.0499 + 0.49, 2 );     // 4.99% + $0.49
			}

			// Guest checkout (card entered directly on PayPal).
			if ( strpos( $method, 'card' ) !== false ) {
				return round( $total * 0.0299 + 0.49, 2 );     // 2.99% + $0.49
			}

			// Standard PayPal checkout (PayPal account / button).
			return round( $total * 0.0349 + 0.49, 2 );         // 3.49% + $0.49
		}

		// Offline methods (COD, bank transfer, cheque) — no fee.
		return 0.0;
	}
}
