<?php
/**
 * Vendor Dashboard — Dokan "Earnings" tab.
 *
 * Shows vendors their commission breakdown per order:
 *  - Item subtotal (the base)
 *  - Their earnings
 *  - Platform commission
 *  - Excluded amounts (shipping, tax, fees)
 */

defined( 'ABSPATH' ) || exit;

class LT_Comm_Vendor_Dashboard {

	public static function init() {
		add_action( 'init',                       [ __CLASS__, 'register_endpoint' ] );
		add_filter( 'dokan_get_dashboard_nav',    [ __CLASS__, 'add_nav_item' ] );
		add_action( 'dokan_load_custom_template', [ __CLASS__, 'load_template' ] );
	}

	public static function register_endpoint() {
		add_rewrite_endpoint( 'lt-earnings', EP_PAGES );
	}

	public static function add_nav_item( $nav ) {
		$nav['lt-earnings'] = [
			'title' => __( 'Earnings', 'loothtool-commissions' ),
			'icon'  => '<i class="fa fa-dollar"></i>',
			'url'   => dokan_get_navigation_url( 'lt-earnings' ),
			'pos'   => 45,
		];
		return $nav;
	}

	public static function load_template( $query_vars ) {
		if ( isset( $query_vars['lt-earnings'] ) ) {
			self::render();
		}
	}

	public static function render() {
		$vendor_id = get_current_user_id();
		if ( ! $vendor_id ) return;

		// Fetch this vendor's orders that have been commission-calculated.
		$orders = wc_get_orders( [
			'meta_key'   => '_lt_comm_calculated_at',
			'meta_value' => '', // any non-empty value
			'meta_compare' => '!=',
			'limit'      => 50,
			'orderby'    => 'date',
			'order'      => 'DESC',
			'meta_query' => [
				'relation' => 'AND',
				[
					'key'     => '_dokan_vendor_id',
					'value'   => $vendor_id,
					'compare' => '=',
					'type'    => 'NUMERIC',
				],
				[
					'key'     => '_lt_comm_calculated_at',
					'compare' => 'EXISTS',
				],
			],
		] );

		// Monthly summary.
		$this_month_earn = 0.0;
		$all_time_earn   = 0.0;
		$month_start     = strtotime( 'first day of this month midnight' );

		foreach ( $orders as $order ) {
			$earn = (float) $order->get_meta( '_lt_comm_vendor_earnings' );
			$all_time_earn += $earn;
			$order_ts = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;
			if ( $order_ts >= $month_start ) {
				$this_month_earn += $earn;
			}
		}

		dokan_get_template_part( 'global/dokan-dashboard-header', '', [
			'header' => __( 'My Earnings', 'loothtool-commissions' ),
		] );
		?>
		<div class="dokan-dashboard-wrap">
			<?php do_action( 'dokan_dashboard_content_before' ); ?>
			<div class="dokan-dashboard-content dokan-earnings-content">

				<article>
					<div class="entry-header">
						<h3>Commission Breakdown</h3>
					</div>

					<div class="dokan-w6" style="display:flex;gap:24px;flex-wrap:wrap;margin-bottom:24px">
						<div class="dokan-panel dokan-panel-default" style="flex:1;min-width:200px">
							<div class="dokan-panel-heading"><strong>This Month</strong></div>
							<div class="dokan-panel-body" style="font-size:1.6rem;font-weight:700;padding:16px">
								<?php echo wp_kses_post( wc_price( $this_month_earn ) ); ?>
							</div>
						</div>
						<div class="dokan-panel dokan-panel-default" style="flex:1;min-width:200px">
							<div class="dokan-panel-heading"><strong>All Time</strong></div>
							<div class="dokan-panel-body" style="font-size:1.6rem;font-weight:700;padding:16px">
								<?php echo wp_kses_post( wc_price( $all_time_earn ) ); ?>
							</div>
						</div>
					</div>

					<?php if ( empty( $orders ) ) : ?>
						<p>No commission data yet. Your earnings will appear here once orders are processed.</p>
					<?php else : ?>
					<table class="dokan-table dokan-table-striped">
						<thead>
							<tr>
								<th>Order</th>
								<th>Date</th>
								<th>Item Subtotal</th>
								<th>Your Earnings</th>
								<th>Platform Cut</th>
								<th>Excl. Shipping</th>
								<th>Excl. Tax</th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $orders as $order ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( $order->get_view_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a></td>
								<td><?php echo esc_html( $order->get_date_created() ? $order->get_date_created()->date( 'M j, Y' ) : '—' ); ?></td>
								<td><?php echo wp_kses_post( wc_price( $order->get_meta( '_lt_comm_item_subtotal' ) ) ); ?></td>
								<td style="font-weight:700"><?php echo wp_kses_post( wc_price( $order->get_meta( '_lt_comm_vendor_earnings' ) ) ); ?></td>
								<td><?php echo wp_kses_post( wc_price( $order->get_meta( '_lt_comm_platform_earnings' ) ) ); ?></td>
								<td><?php echo wp_kses_post( wc_price( $order->get_meta( '_lt_comm_shipping_total' ) ) ); ?></td>
								<td><?php echo wp_kses_post( wc_price( $order->get_meta( '_lt_comm_tax_total' ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p style="font-size:12px;color:#888;margin-top:8px">Showing last 50 orders. All earnings are calculated on item subtotal only.</p>
					<?php endif; ?>

				</article>
			</div><!-- .dokan-dashboard-content -->
		</div><!-- .dokan-dashboard-wrap -->
		<?php
	}
}
