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
 *  _lt_comm_item_subtotal          float   Base used for all commission math
 *  _lt_comm_shipping_total         float   Excluded from commission base (audit only)
 *  _lt_comm_tax_total              float   Excluded from commission base (audit only)
 *  _lt_comm_processing_fees        float   Sum of WC fee line items (platform keeps these)
 *  _lt_comm_splits                 array   Per-line-item breakdown
 *  _lt_comm_vendor_earnings        float   Amount Dokan will pay the primary vendor
 *  _lt_comm_platform_earnings      float   Loothtool's total take
 *  _lt_comm_calculated_at          string  ISO 8601 timestamp
 *  _lt_comm_cross_vendor_payouts   array   Secondary vendor credits written to dokan_vendor_balance
 *                                          Each entry: { vendor_id, vendor_name, amount, credited_at }
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

		// Late balance correction — runs after ALL Dokan hooks have finished
		// so our calculated payout survives Dokan's own balance writes.
		add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'correct_vendor_balance' ], 999, 3 );

		// Cross-vendor commissions are tracked in order meta only (Option C):
		// admin pays out manually at month end. No Dokan balance rows needed.
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
		$vendor_id = (int) $order->get_meta( '_dokan_vendor_id' );
		if ( ! $vendor_id ) {
			return;
		}
		self::process_order( $vendor_id, $order );
	}

	/**
	 * Late balance correction — runs at priority 999 on woocommerce_order_status_changed,
	 * which fires AFTER woocommerce_order_status_processing (where Dokan inserts/updates
	 * its balance rows). Reads the already-calculated _lt_comm_vendor_payout from meta
	 * and overwrites Dokan's balance row so the vendor payout matches our split.
	 */
	public static function correct_vendor_balance( $order_id, $old_status, $new_status ) {
		if ( ! in_array( $new_status, [ 'processing', 'completed' ], true ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$vendor_payout = $order->get_meta( '_lt_comm_vendor_payout' );
		if ( ! $vendor_payout && $vendor_payout !== 0 ) {
			return;
		}
		$vendor_id = (int) $order->get_meta( '_dokan_vendor_id' );
		if ( ! $vendor_id ) {
			return;
		}
		global $wpdb;

		// Correct the balance table (used for withdrawals).
		$wpdb->update(
			$wpdb->prefix . 'dokan_vendor_balance',
			[ 'debit' => (float) $vendor_payout ],
			[ 'trn_id' => $order_id, 'vendor_id' => $vendor_id, 'trn_type' => 'dokan_orders' ],
			[ '%f' ],
			[ '%d', '%d', '%s' ]
		);

		// Correct the order stats table (analytics/reporting).
		$platform_earnings = (float) $order->get_meta( '_lt_comm_platform_earnings' );
		$wpdb->update(
			$wpdb->prefix . 'dokan_order_stats',
			[
				'vendor_earning'    => (float) $vendor_payout,
				'admin_commission'  => $platform_earnings,
			],
			[ 'order_id' => $order_id ],
			[ '%f', '%f' ],
			[ '%d' ]
		);

		// Correct the dokan_orders table (vendor dashboard "Earning" column).
		$wpdb->update(
			$wpdb->prefix . 'dokan_orders',
			[ 'net_amount' => (float) $vendor_payout ],
			[ 'order_id' => $order_id ],
			[ '%f' ],
			[ '%d' ]
		);
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
		$cross_vendor_totals = []; // payee_id => total amount

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
				} else {
					// Cross-vendor split — accumulate for secondary payout.
					$payee_id = (int) $split['payee_id'];
					if ( $payee_id > 0 ) {
						$cross_vendor_totals[ $payee_id ] = ( $cross_vendor_totals[ $payee_id ] ?? 0.0 ) + $split['amount'];
					}
				}
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

		// ── 8. Credit secondary (cross-vendor) payees ────────────────────────
		if ( ! empty( $cross_vendor_totals ) ) {
			self::credit_cross_vendor_payees( $order, $vendor_id, $cross_vendor_totals );
		}
	}

	/**
	 * Record cross-vendor commission payouts in order meta.
	 *
	 * Cross-vendor payees are tracked here only — admin pays them out manually
	 * at month end via the LT Commissions → Cross-Vendor Payouts report.
	 * No Dokan balance rows are written; this keeps us fully isolated from
	 * Dokan's internal balance/withdrawal system.
	 *
	 * @param WC_Order $order
	 * @param int      $primary_vendor_id
	 * @param array    $cross_vendor_totals  payee_id => float amount
	 */
	private static function credit_cross_vendor_payees( WC_Order $order, $primary_vendor_id, array $cross_vendor_totals ) {
		// Skip if already recorded (idempotent on reprocess).
		if ( $order->get_meta( '_lt_comm_cross_vendor_payouts' ) ) {
			return;
		}

		$recorded = [];

		foreach ( $cross_vendor_totals as $payee_id => $amount ) {
			$payee_id = (int) $payee_id;
			$amount   = round( (float) $amount, 2 );
			if ( $amount < 0.01 ) {
				continue;
			}

			$vendor_data = get_userdata( $payee_id );
			$vendor_name = $vendor_data ? $vendor_data->display_name : "Vendor #{$payee_id}";

			$recorded[] = [
				'vendor_id'   => $payee_id,
				'vendor_name' => $vendor_name,
				'amount'      => $amount,
				'recorded_at' => gmdate( 'c' ),
				'paid'        => false,
			];

			if ( get_option( 'lt_comm_audit_log_enabled' ) ) {
				$order->add_order_note( sprintf(
					'LT Commissions: cross-vendor commission %s owed to %s (vendor #%d) — pending manual payout.',
					wc_price( $amount ),
					esc_html( $vendor_name ),
					$payee_id
				) );
			}
		}

		if ( ! empty( $recorded ) ) {
			$order->update_meta_data( '_lt_comm_cross_vendor_payouts', $recorded );
			$order->save();
		}
	}

	/**
	 * Calculate the payment gateway processing fee for an order.
	 *
	 * For Dokan sub-orders the actual Stripe/PayPal charge happens on the
	 * parent order — one fixed component ($0.30 / $0.49) per transaction,
	 * not per vendor. We compute the real fee on the parent total and then
	 * prorate each sub-order's share proportionally so the fixed component
	 * is split fairly instead of duplicated.
	 *
	 * @param  WC_Order $order
	 * @return float
	 */
	public static function calculate_gateway_fee( WC_Order $order ) : float {
		$parent_id = $order->get_parent_id();

		// Not a sub-order — calculate directly.
		if ( ! $parent_id ) {
			return self::calculate_raw_gateway_fee( $order );
		}

		// Sub-order: compute the fee on the parent and prorate.
		$parent = wc_get_order( $parent_id );
		if ( ! $parent ) {
			return self::calculate_raw_gateway_fee( $order );
		}

		$parent_total = (float) $parent->get_total();
		if ( $parent_total <= 0 ) {
			return 0.0;
		}

		$parent_fee   = self::calculate_raw_gateway_fee( $parent );
		$sub_total    = (float) $order->get_total();
		$share        = $sub_total / $parent_total;

		return round( $parent_fee * $share, 2 );
	}

	/**
	 * Raw fee calculation on a single order total — no sub-order awareness.
	 *
	 * Rates current as of 2026. Update here if Stripe/PayPal change pricing.
	 *
	 * @param  WC_Order $order
	 * @return float
	 */
	private static function calculate_raw_gateway_fee( WC_Order $order ) : float {
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
