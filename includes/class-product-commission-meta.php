<?php
/**
 * Product Commission Meta — admin + vendor product editor integration.
 *
 * Admin (WC product editor): full split rule builder with dynamic rows.
 * Vendor (Dokan editor): read-only summary of their earnings breakdown.
 */

defined( 'ABSPATH' ) || exit;

class LT_Comm_Product_Meta {

	public static function init() {
		// WC admin product editor.
		add_action( 'woocommerce_product_options_general_product_data', [ __CLASS__, 'render_admin_fields' ] );
		add_action( 'woocommerce_process_product_meta',                 [ __CLASS__, 'save_admin_fields' ] );

		// Dokan vendor product editor (read-only summary).
		add_action( 'dokan_product_edit_after_main', [ __CLASS__, 'render_vendor_summary' ], 30, 2 );

		// Enqueue JS for the split rule builder.
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
	}

	public static function enqueue_scripts( $hook ) {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) return;
		if ( get_post_type() !== 'product' ) return;

		wp_enqueue_script(
			'lt-comm-product',
			LT_COMM_URL . 'assets/product-commission.js',
			[ 'jquery' ],
			LT_COMM_VER,
			true
		);
	}

	// ── Admin product editor ──────────────────────────────────────────────────

	public static function render_admin_fields() {
		global $post;
		$product_id      = $post->ID;
		$override_enabled = get_post_meta( $product_id, '_lt_comm_override_enabled', true );
		$rules           = $override_enabled
			? LT_Comm_Split_Rules::get_for_product( $product_id )
			: [];

		// Get all Dokan vendors for the payee dropdown.
		$vendors = get_users( [ 'role__in' => [ 'seller', 'vendor', 'administrator' ], 'number' => 200 ] );

		echo '<div class="options_group lt-comm-admin-box" style="padding:12px 16px;border-top:1px solid #ddd">';
		echo '<h4 style="margin:0 0 10px;font-size:13px;text-transform:uppercase;letter-spacing:.05em;color:#555">Split Commission Rules</h4>';

		// Override checkbox.
		echo '<label style="display:flex;align-items:center;gap:8px;margin-bottom:12px">';
		echo '<input type="checkbox" name="lt_comm_override_enabled" value="yes" ' . checked( $override_enabled, 'yes', false ) . '>';
		echo '<span>Override global commission rules for this product</span>';
		echo '</label>';

		echo '<div id="lt-comm-splits-wrap" style="' . ( $override_enabled ? '' : 'display:none' ) . '">';

		// Split rules table.
		echo '<table id="lt-comm-splits-table" class="widefat" style="margin-bottom:8px">';
		echo '<thead><tr>';
		echo '<th>Payee Type</th><th>Payee</th><th>Type</th><th>Value</th><th>Label</th><th></th>';
		echo '</tr></thead><tbody>';

		$display_rules = $rules ?: LT_Comm_Split_Rules::get_global_default();
		foreach ( $display_rules as $i => $rule ) {
			self::render_rule_row( $i, $rule, $vendors );
		}

		echo '</tbody></table>';

		// Add row button + percentage total.
		echo '<button type="button" class="button" id="lt-comm-add-row">+ Add Payee</button>';
		echo ' <span id="lt-comm-pct-total" style="margin-left:16px;font-weight:600"></span>';

		// Hidden template row for JS to clone.
		echo '<table style="display:none"><tbody id="lt-comm-row-template">';
		self::render_rule_row( '__IDX__', [
			'payee_type' => 'vendor',
			'payee_id'   => 0,
			'type'       => 'percentage',
			'value'      => 0,
			'label'      => '',
		], $vendors );
		echo '</tbody></table>';

		echo '</div>'; // #lt-comm-splits-wrap
		echo '</div>'; // .options_group
	}

	private static function render_rule_row( $i, $rule, $vendors ) {
		$n = "lt_comm_splits[{$i}]";
		echo '<tr class="lt-comm-row">';

		// Payee type.
		echo '<td><select name="' . esc_attr( $n ) . '[payee_type]">';
		foreach ( [ 'vendor' => 'Vendor', 'platform' => 'Platform (Loothtool)' ] as $val => $label ) {
			echo '<option value="' . esc_attr( $val ) . '" ' . selected( $rule['payee_type'] ?? '', $val, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td>';

		// Payee (vendor dropdown or "Loothtool" for platform).
		echo '<td>';
		echo '<select name="' . esc_attr( $n ) . '[payee_id]" class="lt-comm-payee-select">';
		echo '<option value="0">' . esc_html__( '— Product vendor —' ) . '</option>';
		foreach ( $vendors as $v ) {
			echo '<option value="' . esc_attr( $v->ID ) . '" ' . selected( (int) ( $rule['payee_id'] ?? 0 ), $v->ID, false ) . '>' . esc_html( $v->display_name ) . '</option>';
		}
		echo '</select>';
		echo '</td>';

		// Type.
		echo '<td><select name="' . esc_attr( $n ) . '[type]" class="lt-comm-type-select">';
		foreach ( [ 'percentage' => '%', 'flat' => 'Flat $' ] as $val => $label ) {
			echo '<option value="' . esc_attr( $val ) . '" ' . selected( $rule['type'] ?? 'percentage', $val, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td>';

		// Value.
		echo '<td><input type="number" name="' . esc_attr( $n ) . '[value]" value="' . esc_attr( $rule['value'] ?? 0 ) . '" min="0" step="0.01" style="width:80px" class="lt-comm-value"></td>';

		// Label.
		echo '<td><input type="text" name="' . esc_attr( $n ) . '[label]" value="' . esc_attr( $rule['label'] ?? '' ) . '" placeholder="e.g. Designer cut" style="width:140px"></td>';

		// Remove.
		echo '<td><button type="button" class="button lt-comm-remove-row">✕</button></td>';
		echo '</tr>';
	}

	public static function save_admin_fields( $product_id ) {
		$override = isset( $_POST['lt_comm_override_enabled'] ) && $_POST['lt_comm_override_enabled'] === 'yes';

		if ( ! $override ) {
			LT_Comm_Split_Rules::clear_for_product( $product_id );
			return;
		}

		$raw_rules = isset( $_POST['lt_comm_splits'] ) && is_array( $_POST['lt_comm_splits'] )
			? array_values( $_POST['lt_comm_splits'] )
			: [];

		if ( empty( $raw_rules ) ) {
			LT_Comm_Split_Rules::clear_for_product( $product_id );
			return;
		}

		$result = LT_Comm_Split_Rules::save_for_product( $product_id, $raw_rules );
		if ( is_wp_error( $result ) ) {
			// Store error to show as admin notice.
			set_transient( 'lt_comm_save_error_' . $product_id, $result->get_error_message(), 30 );
		}
	}

	// ── Dokan vendor editor (read-only summary) ───────────────────────────────

	public static function render_vendor_summary( $post, $post_id ) {
		$vendor_id = get_current_user_id();
		$rules     = LT_Comm_Split_Rules::get_for_product( $post_id );

		echo '<div class="lt-comm-vendor-summary" style="background:#f8f8f8;border:1px solid #ddd;padding:14px 16px;margin-top:16px;border-radius:4px">';
		echo '<h4 style="margin:0 0 10px;font-size:13px;text-transform:uppercase;letter-spacing:.05em;color:#555">Your Commission Breakdown</h4>';
		echo '<table style="width:100%;font-size:13px;border-collapse:collapse">';
		foreach ( $rules as $rule ) {
			$is_you = ( $rule['payee_type'] === 'vendor' && ( $rule['payee_id'] === 0 || (int) $rule['payee_id'] === $vendor_id ) );
			$label  = $rule['label'] ?: ( $rule['payee_type'] === 'platform' ? 'Platform (Loothtool)' : 'Vendor' );
			$value  = $rule['type'] === 'percentage' ? esc_html( $rule['value'] ) . '%' : '$' . esc_html( number_format( $rule['value'], 2 ) );
			$style  = $is_you ? 'font-weight:700;color:#2271b1' : 'color:#666';
			echo '<tr><td style="' . esc_attr( $style ) . ';padding:3px 0">' . esc_html( $label ) . ( $is_you ? ' (you)' : '' ) . '</td>';
			echo '<td style="text-align:right;' . esc_attr( $style ) . '">' . esc_html( $value ) . '</td></tr>';
		}
		echo '</table>';
		echo '<p style="margin:10px 0 0;font-size:12px;color:#888">Calculated on item subtotal only — shipping, tax, and payment fees are excluded.</p>';
		echo '</div>';
	}
}
