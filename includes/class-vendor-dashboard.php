<?php
/**
 * Vendor Dashboard — Dokan "Earnings Preview" tab.
 *
 * Shows vendors:
 *  - Their per-product earnings estimate (price × vendor %)
 *  - Commission rate breakdown (vendor cut vs platform cut)
 *  - How payment method fees work (deducted from vendor payout)
 *  - A quick earnings calculator
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
		$vendor_id    = get_current_user_id();
		if ( ! $vendor_id ) return;

		$user = wp_get_current_user();
		if ( ! array_intersect( [ 'seller', 'vendor', 'administrator' ], (array) $user->roles ) ) {
			echo '<p>You do not have permission to view this page.</p>';
			return;
		}

		$platform_pct = (float) get_option( 'lt_comm_platform_percentage', 10.0 );
		$vendor_pct   = 100.0 - $platform_pct;

		// Payment method fee schedules (deducted from vendor payout after commission split).
		$payment_methods = [
			'Credit / Debit Card (Stripe)' => [ 'pct' => 2.9,  'flat' => 0.30 ],
			'PayPal'                        => [ 'pct' => 3.49, 'flat' => 0.49 ],
			'ACH / Bank Transfer'           => [ 'pct' => 0.8,  'flat' => 0.00, 'cap' => 5.00 ],
		];

		// Fetch vendor's published products.
		$products = wc_get_products( [
			'author'  => $vendor_id,
			'status'  => 'publish',
			'limit'   => 200,
			'orderby' => 'title',
			'order'   => 'ASC',
		] );

		dokan_get_template_part( 'global/dokan-dashboard-header', '', [
			'header' => __( 'Earnings Preview', 'loothtool-commissions' ),
		] );
		?>
		<div class="dokan-dashboard-wrap">
			<?php do_action( 'dokan_dashboard_content_before' ); ?>
			<div class="dokan-dashboard-content dokan-earnings-content">
				<article>

					<!-- ── Commission rate banner ── -->
					<div style="background:#1a3a3c;border:1px solid #2d6063;border-radius:6px;padding:16px 20px;margin-bottom:24px;display:flex;gap:32px;flex-wrap:wrap;align-items:center">
						<div style="text-align:center;min-width:100px">
							<div style="font-size:2rem;font-weight:700;color:#2ecc71"><?php echo esc_html( number_format( $vendor_pct, 0 ) ); ?>%</div>
							<div style="font-size:12px;color:#aaa;margin-top:2px">Your Cut</div>
						</div>
						<div style="text-align:center;min-width:100px">
							<div style="font-size:2rem;font-weight:700;color:#e74c3c"><?php echo esc_html( number_format( $platform_pct, 0 ) ); ?>%</div>
							<div style="font-size:12px;color:#aaa;margin-top:2px">Platform Cut</div>
						</div>
						<div style="flex:1;min-width:200px;font-size:13px;color:#ccc;line-height:1.6">
							<strong style="color:#fff">Commissions are based on item price only.</strong><br>
							Shipping and sales tax are not included in the commission calculation. Payment processing fees (Stripe, PayPal, etc.) are <strong>deducted from your payout</strong> — see breakdown below.
						</div>
					</div>

					<!-- ── Per-product earnings table ── -->
					<h3 style="margin:0 0 12px;font-size:15px;text-transform:uppercase;letter-spacing:.05em">Your Product Earnings</h3>

					<?php if ( empty( $products ) ) : ?>
						<p>No published products found.</p>
					<?php else : ?>
					<div style="overflow-x:auto">
					<table class="dokan-table dokan-table-striped" style="width:100%;font-size:13px">
						<thead>
							<tr>
								<th style="text-align:left">Product</th>
								<th style="text-align:right">Sale Price</th>
								<th style="text-align:right">Your Earnings<br><span style="font-weight:400;color:#aaa">(<?php echo esc_html( number_format( $vendor_pct, 0 ) ); ?>%)</span></th>
								<th style="text-align:right">Platform Cut<br><span style="font-weight:400;color:#aaa">(<?php echo esc_html( number_format( $platform_pct, 0 ) ); ?>%)</span></th>
								<th style="text-align:left;color:#aaa">Commission Rule</th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $products as $product ) :
							$pid   = $product->get_id();
							$rules = LT_Comm_Split_Rules::get_for_product( $pid, $vendor_id );
							$is_custom = (bool) get_post_meta( $pid, '_lt_comm_override_enabled', true );

							// Figure out vendor's pct and any flat amounts from rules.
							$vendor_rule_pct  = 0.0;
							$vendor_rule_flat = 0.0;
							foreach ( $rules as $rule ) {
								$is_mine = ( $rule['payee_type'] === 'vendor' && ( (int) $rule['payee_id'] === 0 || (int) $rule['payee_id'] === $vendor_id ) );
								if ( ! $is_mine ) continue;
								if ( $rule['type'] === 'percentage' ) $vendor_rule_pct  += (float) $rule['value'];
								else                                   $vendor_rule_flat += (float) $rule['value'];
							}

							// Price range.
							if ( $product->is_type( 'variable' ) ) {
								$min = (float) $product->get_variation_price( 'min' );
								$max = (float) $product->get_variation_price( 'max' );
								$price_html   = wc_price( $min ) . ( $min !== $max ? ' – ' . wc_price( $max ) : '' );
								$earn_min     = round( $min * $vendor_rule_pct / 100 + $vendor_rule_flat, 2 );
								$earn_max     = round( $max * $vendor_rule_pct / 100 + $vendor_rule_flat, 2 );
								$earn_html    = wc_price( $earn_min ) . ( $earn_min !== $earn_max ? ' – ' . wc_price( $earn_max ) : '' );
								$plat_min     = round( $min - $earn_min, 2 );
								$plat_max     = round( $max - $earn_max, 2 );
								$plat_html    = wc_price( $plat_min ) . ( $plat_min !== $plat_max ? ' – ' . wc_price( $plat_max ) : '' );
							} else {
								$price        = (float) $product->get_price();
								$price_html   = wc_price( $price );
								$earn_val     = round( $price * $vendor_rule_pct / 100 + $vendor_rule_flat, 2 );
								$earn_html    = wc_price( $earn_val );
								$plat_html    = wc_price( round( $price - $earn_val, 2 ) );
							}
						?>
							<tr>
								<td>
									<a href="<?php echo esc_url( get_edit_post_link( $pid ) ); ?>" style="font-weight:600"><?php echo esc_html( $product->get_name() ); ?></a>
								</td>
								<td style="text-align:right"><?php echo wp_kses_post( $price_html ); ?></td>
								<td style="text-align:right;font-weight:700;color:#2ecc71"><?php echo wp_kses_post( $earn_html ); ?></td>
								<td style="text-align:right;color:#e74c3c"><?php echo wp_kses_post( $plat_html ); ?></td>
								<td style="color:#aaa;font-size:12px"><?php echo $is_custom ? '<span style="color:#c9a84c">Custom split</span>' : 'Global default'; ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					</div>
					<?php endif; ?>

					<!-- ── Payment method info ── -->
					<h3 style="margin:32px 0 12px;font-size:15px;text-transform:uppercase;letter-spacing:.05em">Payment Method Fees</h3>
					<p style="font-size:13px;color:#ccc;margin-bottom:12px">
						These fees are charged by payment processors and are <strong>deducted from your payout</strong> after your commission is calculated. The table below shows approximate rates by payment method.
					</p>
					<div style="overflow-x:auto">
					<table class="dokan-table" style="width:100%;max-width:600px;font-size:13px">
						<thead>
							<tr>
								<th style="text-align:left">Payment Method</th>
								<th style="text-align:right">Fee Structure</th>
								<th style="text-align:right;color:#aaa">Example: $50 sale</th>
								<th style="text-align:right;color:#aaa">Example: $100 sale</th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $payment_methods as $method => $fee ) :
							$fee50  = isset( $fee['cap'] ) ? min( round( 50  * $fee['pct'] / 100 + $fee['flat'], 2 ), $fee['cap'] ) : round( 50  * $fee['pct'] / 100 + $fee['flat'], 2 );
							$fee100 = isset( $fee['cap'] ) ? min( round( 100 * $fee['pct'] / 100 + $fee['flat'], 2 ), $fee['cap'] ) : round( 100 * $fee['pct'] / 100 + $fee['flat'], 2 );
							$fee_label = $fee['pct'] . '%' . ( $fee['flat'] > 0 ? ' + $' . number_format( $fee['flat'], 2 ) : '' );
							if ( isset( $fee['cap'] ) ) $fee_label .= ' (max $' . number_format( $fee['cap'], 2 ) . ')';
						?>
							<tr>
								<td><?php echo esc_html( $method ); ?></td>
								<td style="text-align:right;color:#aaa"><?php echo esc_html( $fee_label ); ?></td>
								<td style="text-align:right;color:#aaa">$<?php echo esc_html( number_format( $fee50, 2 ) ); ?></td>
								<td style="text-align:right;color:#aaa">$<?php echo esc_html( number_format( $fee100, 2 ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					</div>
					<p style="font-size:11px;color:#666;margin-top:8px">Fee rates shown are approximate and may vary. These fees are deducted from your payout, not from the commission split shown above.</p>

					<!-- ── Cross-vendor commissions ── -->
				<?php
				// Query orders with cross-vendor payouts; wc_get_orders() handles HPOS.
				$cv_order_ids = wc_get_orders( [
					'limit'      => -1,
					'return'     => 'ids',
					'meta_query' => [ [ 'key' => '_lt_comm_cross_vendor_payouts', 'compare' => 'EXISTS' ] ],
				] );
				$cv_entries = [];
				foreach ( $cv_order_ids as $oid ) {
					$o_obj   = wc_get_order( (int) $oid );
					$payouts = $o_obj ? $o_obj->get_meta( '_lt_comm_cross_vendor_payouts' ) : null;
					if ( ! is_array( $payouts ) ) continue;
					foreach ( $payouts as $p ) {
						if ( (int) $p['vendor_id'] !== $vendor_id ) continue;
						$o = wc_get_order( (int) $oid );
						if ( ! $o ) continue;
						$cv_entries[] = [
							'order_id'     => (int) $oid,
							'amount'       => (float) $p['amount'],
							'recorded_at'  => $p['recorded_at'] ?? '',
							'paid'         => ! empty( $p['paid'] ),
							'order_status' => $o->get_status(),
						];
					}
				}
				usort( $cv_entries, fn( $a, $b ) => strcmp( $b['recorded_at'], $a['recorded_at'] ) );
				if ( ! empty( $cv_entries ) ) :
					$total_paid    = 0.0;
					$total_pending = 0.0;
					foreach ( $cv_entries as $e ) {
						if ( $e['paid'] ) $total_paid += $e['amount'];
						else $total_pending += $e['amount'];
					}
				?>
				<h3 style="margin:32px 0 8px;font-size:15px;text-transform:uppercase;letter-spacing:.05em">Cross-Vendor Commissions</h3>
				<p style="font-size:13px;color:#ccc;margin-bottom:12px">
					Commissions earned from products you designed or contributed to, fulfilled by another vendor. Paid out monthly by Loothtool admin.
				</p>
				<div style="display:flex;gap:24px;margin-bottom:16px;flex-wrap:wrap">
					<?php if ( $total_pending > 0 ) : ?>
					<div style="background:#1a3a3c;border:1px solid #2d6063;border-radius:6px;padding:12px 20px;min-width:160px;text-align:center">
						<div style="font-size:1.6rem;font-weight:700;color:#c9a84c"><?php echo wp_kses_post( wc_price( $total_pending ) ); ?></div>
						<div style="font-size:12px;color:#aaa;margin-top:2px">Pending Payout</div>
					</div>
					<?php endif; ?>
					<?php if ( $total_paid > 0 ) : ?>
					<div style="background:#1a3a3c;border:1px solid #2d6063;border-radius:6px;padding:12px 20px;min-width:160px;text-align:center">
						<div style="font-size:1.6rem;font-weight:700;color:#2ecc71"><?php echo wp_kses_post( wc_price( $total_paid ) ); ?></div>
						<div style="font-size:12px;color:#aaa;margin-top:2px">Paid Out (all time)</div>
					</div>
					<?php endif; ?>
				</div>
				<div style="overflow-x:auto">
				<table class="dokan-table dokan-table-striped" style="width:100%;font-size:13px">
					<thead>
						<tr>
							<th style="text-align:left">Order</th>
							<th style="text-align:left">Date</th>
							<th style="text-align:right">Amount</th>
							<th style="text-align:left">Order Status</th>
							<th style="text-align:left">Payout</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $cv_entries as $e ) :
						$order_url    = admin_url( 'post.php?post=' . $e['order_id'] . '&action=edit' );
						$status_label = wc_get_order_status_name( 'wc-' . $e['order_status'] );
						$date_fmt     = $e['recorded_at'] ? date_i18n( get_option( 'date_format' ), strtotime( $e['recorded_at'] ) ) : '—';
					?>
						<tr>
							<td><a href="<?php echo esc_url( $order_url ); ?>">#<?php echo esc_html( $e['order_id'] ); ?></a></td>
							<td style="color:#aaa"><?php echo esc_html( $date_fmt ); ?></td>
							<td style="text-align:right;font-weight:700;color:#2ecc71"><?php echo wp_kses_post( wc_price( $e['amount'] ) ); ?></td>
							<td style="color:#aaa;font-size:12px"><?php echo esc_html( $status_label ); ?></td>
							<td>
								<?php if ( $e['paid'] ) : ?>
									<span style="color:#2ecc71;font-size:12px">&#10003; Paid</span>
								<?php else : ?>
									<span style="color:#c9a84c;font-size:12px">&#9679; Pending</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
				<?php endif; ?>

				<!-- ── Quick calculator ── -->
					<h3 style="margin:32px 0 12px;font-size:15px;text-transform:uppercase;letter-spacing:.05em">Quick Earnings Calculator</h3>
					<div style="background:#1a3a3c;border:1px solid #2d6063;border-radius:6px;padding:16px 20px;max-width:400px">
						<label style="display:block;margin-bottom:8px;font-size:13px;color:#ccc">Enter a sale price:</label>
						<div style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
							<span style="color:#aaa;font-size:16px">$</span>
							<input type="number" id="lt-earn-calc-input" min="0" step="0.01" placeholder="0.00"
								style="width:120px;padding:6px 10px;border:1px solid #2d6063;border-radius:4px;background:#0d2527;color:#fff;font-size:15px">
						</div>
						<div style="font-size:13px;line-height:2">
							<div>Your earnings <strong style="color:#2ecc71">(<?php echo esc_html( number_format( $vendor_pct, 0 ) ); ?>%):</strong>
								<strong id="lt-earn-result" style="color:#2ecc71;margin-left:8px">—</strong></div>
							<div style="color:#aaa">Platform cut <span>(<?php echo esc_html( number_format( $platform_pct, 0 ) ); ?>%):</span>
								<span id="lt-plat-result" style="margin-left:8px">—</span></div>
						</div>
					</div>
					<script>
					(function(){
						var input = document.getElementById('lt-earn-calc-input');
						var earnEl = document.getElementById('lt-earn-result');
						var platEl = document.getElementById('lt-plat-result');
						var vPct = <?php echo esc_js( $vendor_pct ); ?>;
						var pPct = <?php echo esc_js( $platform_pct ); ?>;
						if (!input) return;
						input.addEventListener('input', function(){
							var v = parseFloat(this.value);
							if (isNaN(v) || v <= 0) { earnEl.textContent = '—'; platEl.textContent = '—'; return; }
							earnEl.textContent = '$' + (v * vPct / 100).toFixed(2);
							platEl.textContent = '$' + (v * pPct / 100).toFixed(2);
						});
					}());
					</script>

				</article>
			</div><!-- .dokan-dashboard-content -->
		</div><!-- .dokan-dashboard-wrap -->
		<?php
	}
}
