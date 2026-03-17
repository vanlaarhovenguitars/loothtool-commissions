<?php
/**
 * Admin Settings — WP Admin → Settings → Loothtool Commissions
 */

defined( 'ABSPATH' ) || exit;

class LT_Comm_Admin_Settings {

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'add_page' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'admin_post_lt_comm_recalculate', [ __CLASS__, 'handle_recalculate' ] );
	}

	public static function add_page() {
		add_options_page(
			'Loothtool Commissions',
			'LT Commissions',
			'manage_options',
			'lt-commissions-settings',
			[ __CLASS__, 'render' ]
		);
	}

	public static function register_settings() {
		register_setting( 'lt_comm_settings', 'lt_comm_platform_percentage', [
			'type'              => 'number',
			'sanitize_callback' => function( $v ) { return max( 0, min( 100, (float) $v ) ); },
			'default'           => 10.0,
		] );
		register_setting( 'lt_comm_settings', 'lt_comm_default_commission_type', [
			'type'              => 'string',
			'sanitize_callback' => function( $v ) { return in_array( $v, [ 'percentage', 'flat' ] ) ? $v : 'percentage'; },
			'default'           => 'percentage',
		] );
		register_setting( 'lt_comm_settings', 'lt_comm_audit_log_enabled', [
			'type'    => 'string',
			'default' => '1',
		] );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$pct   = (float) get_option( 'lt_comm_platform_percentage', 10.0 );
		$type  = get_option( 'lt_comm_default_commission_type', 'percentage' );
		$audit = get_option( 'lt_comm_audit_log_enabled', '1' );
		?>
		<div class="wrap">
			<h1>Loothtool Commissions</h1>

			<form method="post" action="options.php">
				<?php settings_fields( 'lt_comm_settings' ); ?>

				<h2>Global Commission Settings</h2>
				<table class="form-table">
					<tr>
						<th><label for="lt_comm_platform_percentage">Platform Commission %</label></th>
						<td>
							<input type="number" id="lt_comm_platform_percentage"
								name="lt_comm_platform_percentage"
								value="<?php echo esc_attr( $pct ); ?>"
								min="0" max="100" step="0.01" style="width:80px"> %
							<p class="description">Loothtool's cut from each sale. Applied to item subtotal only.</p>
						</td>
					</tr>
					<tr>
						<th><label for="lt_comm_default_commission_type">Commission Type</label></th>
						<td>
							<select id="lt_comm_default_commission_type" name="lt_comm_default_commission_type">
								<option value="percentage" <?php selected( $type, 'percentage' ); ?>>Percentage</option>
								<option value="flat"       <?php selected( $type, 'flat' ); ?>>Flat Fee</option>
							</select>
						</td>
					</tr>
					<tr>
						<th>Payout Model</th>
						<td>
							<strong>Vendor payout = item commission + shipping + tax &minus; payment processing fees</strong><br>
							<p class="description">Commission is calculated on item subtotal only. Vendors receive their item commission share plus the full shipping and tax amounts (they purchase the shipping label). Payment processing fees (Stripe/PayPal) are deducted from the vendor payout — Loothtool does not absorb these.</p>
						</td>
					</tr>
					<tr>
						<th><label for="lt_comm_audit_log_enabled">Write to Order Notes</label></th>
						<td>
							<input type="checkbox" id="lt_comm_audit_log_enabled"
								name="lt_comm_audit_log_enabled" value="1"
								<?php checked( $audit, '1' ); ?>>
							<label for="lt_comm_audit_log_enabled">Add commission breakdown to each order's notes</label>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr>
			<h2>Tools</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="lt_comm_recalculate">
				<?php wp_nonce_field( 'lt_comm_recalculate' ); ?>
				<table class="form-table">
					<tr>
						<th>Recalculate Recent Orders</th>
						<td>
							<?php submit_button( 'Recalculate unprocessed orders (last 30 days)', 'secondary', 'recalc', false ); ?>
							<p class="description">Runs the commission engine on any "processing" orders from the last 30 days that haven't been calculated yet.</p>
						</td>
					</tr>
				</table>
			</form>

			<?php self::render_recent_commissions(); ?>
		</div>
		<?php
	}

	public static function handle_recalculate() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
		check_admin_referer( 'lt_comm_recalculate' );

		$orders = wc_get_orders( [
			'status'       => [ 'processing', 'completed' ],
			'date_created' => '>' . ( time() - 30 * DAY_IN_SECONDS ),
			'limit'        => 200,
			'meta_query'   => [ [
				'key'     => '_lt_comm_calculated_at',
				'compare' => 'NOT EXISTS',
			] ],
		] );

		$count = 0;
		foreach ( $orders as $order ) {
			$vendor_id = (int) $order->get_meta( '_dokan_vendor_id' );
			if ( ! $vendor_id ) continue;
			LT_Comm_Order_Processor::process_order( $vendor_id, $order );
			$count++;
		}

		wp_redirect( add_query_arg( [
			'page'    => 'lt-commissions-settings',
			'recalc'  => $count,
		], admin_url( 'options-general.php' ) ) );
		exit;
	}

	private static function render_recent_commissions() {
		if ( isset( $_GET['recalc'] ) ) {
			echo '<div class="notice notice-success inline"><p>Recalculated ' . (int) $_GET['recalc'] . ' orders.</p></div>';
		}

		// Summary of last 10 processed orders.
		$orders = wc_get_orders( [
			'status'   => [ 'processing', 'completed' ],
			'limit'    => 10,
			'orderby'  => 'date',
			'order'    => 'DESC',
			'meta_key' => '_lt_comm_calculated_at',
		] );

		if ( empty( $orders ) ) return;
		?>
		<h2>Recent Commissions</h2>
		<table class="widefat striped" style="max-width:1100px">
			<thead>
				<tr>
					<th>Order</th>
					<th>Date</th>
					<th>Item Subtotal</th>
					<th>Item Commission (vendor %)</th>
					<th>Platform Cut</th>
					<th>+ Shipping</th>
					<th>+ Tax</th>
					<th>&minus; Proc. Fees</th>
					<th><strong>Vendor Payout</strong></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $orders as $order ) : ?>
				<tr>
					<td><a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a></td>
					<td><?php echo esc_html( $order->get_date_created()->date( 'Y-m-d' ) ); ?></td>
					<td><?php echo wp_kses_post( wc_price( $order->get_meta( '_lt_comm_item_subtotal' ) ) ); ?></td>
					<td><?php echo wp_kses_post( wc_price( $order->get_meta( '_lt_comm_vendor_earnings' ) ) ); ?></td>
					<td><?php echo wp_kses_post( wc_price( $order->get_meta( '_lt_comm_platform_earnings' ) ) ); ?></td>
					<td><?php echo wp_kses_post( wc_price( $order->get_meta( '_lt_comm_shipping_total' ) ) ); ?></td>
					<td><?php echo wp_kses_post( wc_price( $order->get_meta( '_lt_comm_tax_total' ) ) ); ?></td>
					<td><?php echo wp_kses_post( wc_price( $order->get_meta( '_lt_comm_processing_fees' ) ) ); ?></td>
					<td><strong><?php echo wp_kses_post( wc_price( $order->get_meta( '_lt_comm_vendor_payout' ) ) ); ?></strong></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
