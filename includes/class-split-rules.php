<?php
/**
 * Split Rules — data layer for per-product commission rules.
 *
 * Rules are stored as JSON in product meta (_lt_comm_split_rules).
 * Each rule specifies a payee, whether they get a percentage or flat amount,
 * and how much.
 *
 * Percentages across all percentage-type rules must sum to exactly 100.
 * Flat rules are paid first; percentages apply to what remains.
 */

defined( 'ABSPATH' ) || exit;

class LT_Comm_Split_Rules {

	const META_KEY = '_lt_comm_split_rules';

	// ── Read ──────────────────────────────────────────────────────────────────

	/**
	 * Get split rules for a product.
	 * Falls back to the global default if no per-product rules exist.
	 *
	 * @param  int   $product_id
	 * @return array
	 */
	public static function get_for_product( $product_id ) {
		if ( ! get_post_meta( $product_id, '_lt_comm_override_enabled', true ) ) {
			return self::get_global_default();
		}
		$raw = get_post_meta( $product_id, self::META_KEY, true );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			return $raw;
		}
		return self::get_global_default();
	}

	/**
	 * Global default split: vendor gets (100 - platform_pct)%, platform gets platform_pct%.
	 * payee_id 0 for vendor = resolved at runtime to the product's vendor.
	 */
	public static function get_global_default() {
		$platform_pct = (float) get_option( 'lt_comm_platform_percentage', 10.0 );
		$vendor_pct   = 100.0 - $platform_pct;
		return [
			[
				'payee_type' => 'vendor',
				'payee_id'   => 0,
				'type'       => 'percentage',
				'value'      => $vendor_pct,
				'label'      => 'Vendor earnings',
			],
			[
				'payee_type' => 'platform',
				'payee_id'   => 0,
				'type'       => 'percentage',
				'value'      => $platform_pct,
				'label'      => 'Platform commission',
			],
		];
	}

	// ── Write ─────────────────────────────────────────────────────────────────

	/**
	 * Validate and save split rules for a product.
	 *
	 * @param  int   $product_id
	 * @param  array $rules
	 * @return true|WP_Error
	 */
	public static function save_for_product( $product_id, array $rules ) {
		$validated = self::validate( $rules );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		update_post_meta( $product_id, '_lt_comm_override_enabled', 'yes' );
		update_post_meta( $product_id, self::META_KEY, $validated );
		return true;
	}

	public static function clear_for_product( $product_id ) {
		delete_post_meta( $product_id, '_lt_comm_override_enabled' );
		delete_post_meta( $product_id, self::META_KEY );
	}

	// ── Validate ──────────────────────────────────────────────────────────────

	/**
	 * Sanitise and validate a rules array.
	 * Returns cleaned array or WP_Error.
	 */
	public static function validate( array $rules ) {
		$clean     = [];
		$pct_total = 0.0;

		foreach ( $rules as $rule ) {
			$type  = sanitize_text_field( $rule['type'] ?? 'percentage' );
			$value = max( 0.0, (float) ( $rule['value'] ?? 0 ) );

			if ( ! in_array( $type, [ 'percentage', 'flat' ], true ) ) {
				return new WP_Error( 'lt_comm_invalid_type', 'Commission type must be "percentage" or "flat".' );
			}
			if ( $type === 'percentage' ) {
				$pct_total += $value;
			}

			$clean[] = [
				'payee_type' => sanitize_text_field( $rule['payee_type'] ?? 'platform' ),
				'payee_id'   => (int) ( $rule['payee_id'] ?? 0 ),
				'type'       => $type,
				'value'      => $value,
				'label'      => sanitize_text_field( $rule['label'] ?? '' ),
			];
		}

		if ( $pct_total > 0 && abs( $pct_total - 100.0 ) > 0.01 ) {
			return new WP_Error(
				'lt_comm_pct_mismatch',
				sprintf( 'Percentage rules must sum to 100%%.' )
			);
		}

		return $clean;
	}

	// ── Compute ───────────────────────────────────────────────────────────────

	/**
	 * Compute each payee's dollar amount for a given item subtotal.
	 *
	 * @param  array $rules
	 * @param  float $item_subtotal  Pure product subtotal (excl. shipping/tax/fees).
	 * @param  int   $vendor_id      Resolved when payee_id = 0.
	 * @return array  [ [ 'payee_type', 'payee_id', 'amount', 'label' ], ... ]
	 */
	public static function compute_splits( array $rules, $item_subtotal, $vendor_id ) {
		$splits    = [];
		$remaining = $item_subtotal;

		// Flat rules paid first.
		foreach ( $rules as $rule ) {
			if ( $rule['type'] !== 'flat' ) {
				continue;
			}
			$amount     = min( (float) $rule['value'], $remaining );
			$remaining -= $amount;
			$splits[]   = [
				'payee_type' => $rule['payee_type'],
				'payee_id'   => $rule['payee_id'] ?: (int) $vendor_id,
				'amount'     => round( $amount, 2 ),
				'label'      => $rule['label'],
			];
		}

		// Percentage rules against the remaining pool.
		$pct_rules = array_values( array_filter( $rules, fn( $r ) => $r['type'] === 'percentage' ) );
		$last_idx  = count( $pct_rules ) - 1;
		$assigned  = 0.0;

		foreach ( $pct_rules as $i => $rule ) {
			if ( $i === $last_idx ) {
				$amount = round( $remaining - $assigned, 2 );
			} else {
				$amount = round( $remaining * ( (float) $rule['value'] / 100.0 ), 2 );
			}
			$assigned += $amount;
			$splits[]  = [
				'payee_type' => $rule['payee_type'],
				'payee_id'   => $rule['payee_id'] ?: (int) $vendor_id,
				'amount'     => $amount,
				'label'      => $rule['label'],
			];
		}

		return $splits;
	}
}
