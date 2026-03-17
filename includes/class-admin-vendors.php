<?php
/**
 * Admin Vendors — top-level "LT Commissions" menu.
 *
 * Routes:
 *  admin.php?page=lt-commissions              → vendor list
 *  admin.php?page=lt-commissions&vendor=123   → vendor detail (rate + products)
 */

defined( 'ABSPATH' ) || exit;

class LT_Comm_Admin_Vendors {

	public static function init() {
		add_action( 'admin_menu',            [ __CLASS__, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
		add_action( 'admin_post_lt_comm_save_vendor_rate',    [ __CLASS__, 'handle_save_vendor_rate' ] );
		add_action( 'admin_post_lt_comm_save_product_rules',  [ __CLASS__, 'handle_save_product_rules' ] );
	}

	public static function add_menu() {
		add_menu_page(
			'LT Commissions',
			'LT Commissions',
			'manage_options',
			'lt-commissions',
			[ __CLASS__, 'render' ],
			'dashicons-money-alt',
			56
		);
		add_submenu_page(
			'lt-commissions',
			'Cross-Vendor Payouts',
			'Cross-Vendor Payouts',
			'manage_options',
			'lt-commissions-payouts',
			[ __CLASS__, 'render_payouts' ]
		);
		add_submenu_page(
			'lt-commissions',
			'Commission Health Check',
			'Health Check',
			'manage_options',
			'lt-commissions-health',
			[ __CLASS__, 'render_health_check' ]
		);
	}

	public static function enqueue_scripts( $hook ) {
		if ( $hook !== 'toplevel_page_lt-commissions' ) {
			return;
		}
		wp_enqueue_script(
			'lt-admin-vendors',
			LT_COMM_URL . 'assets/admin-vendors.js',
			[ 'jquery' ],
			LT_COMM_VER,
			true
		);
	}

	// ── Router ────────────────────────────────────────────────────────────────

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$vendor_id = isset( $_GET['vendor'] ) ? (int) $_GET['vendor'] : 0;
		if ( $vendor_id ) {
			self::render_vendor_detail( $vendor_id );
		} else {
			self::render_vendors_overview();
		}
	}

	// ── Vendors overview ──────────────────────────────────────────────────────

	private static function render_vendors_overview() {
		$vendors = get_users( [
			'role__in' => [ 'seller', 'vendor' ],
			'number'   => -1,
			'orderby'  => 'display_name',
			'order'    => 'ASC',
		] );
		$global_pct = (float) get_option( 'lt_comm_platform_percentage', 10.0 );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">LT Commissions — Vendors</h1>
			<a href="<?php echo esc_url( admin_url( 'options-general.php?page=lt-commissions-settings' ) ); ?>" class="page-title-action">Global Settings</a>
			<hr class="wp-header-end">

			<?php if ( empty( $vendors ) ) : ?>
				<p>No vendors found. Make sure Dokan is active and vendors have been registered.</p>
			<?php else : ?>
			<table class="widefat striped" style="max-width:960px;margin-top:16px">
				<thead>
					<tr>
						<th>Vendor</th>
						<th>Commission Rate</th>
						<th>Lifetime Earnings</th>
						<th>Products</th>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $vendors as $vendor ) :
					$platform_pct = self::get_vendor_platform_pct( $vendor->ID );
					$vendor_pct   = 100.0 - $platform_pct;
					$earnings     = self::get_vendor_lifetime_earnings( $vendor->ID );
					$product_count = count_user_posts( $vendor->ID, 'product' );
					$is_custom     = (bool) get_user_meta( $vendor->ID, '_lt_comm_vendor_platform_pct', true );
				?>
					<tr>
						<td>
							<strong><?php echo esc_html( $vendor->display_name ); ?></strong><br>
							<small style="color:#666"><?php echo esc_html( $vendor->user_email ); ?></small>
						</td>
						<td>
							<strong><?php echo esc_html( number_format( $vendor_pct, 1 ) ); ?>%</strong> vendor /
							<strong><?php echo esc_html( number_format( $platform_pct, 1 ) ); ?>%</strong> platform
							<?php if ( $is_custom ) : ?>
								<span style="color:#c9a84c;font-size:11px;margin-left:4px">custom</span>
							<?php endif; ?>
						</td>
						<td><?php echo wp_kses_post( wc_price( $earnings ) ); ?></td>
						<td><?php echo esc_html( $product_count ); ?></td>
						<td>
							<a href="<?php echo esc_url( add_query_arg( [ 'page' => 'lt-commissions', 'vendor' => $vendor->ID ], admin_url( 'admin.php' ) ) ); ?>" class="button button-small">Manage</a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── Vendor detail ─────────────────────────────────────────────────────────

	private static function render_vendor_detail( $vendor_id ) {
		$vendor = get_userdata( $vendor_id );
		if ( ! $vendor ) {
			echo '<div class="wrap"><p>Vendor not found.</p></div>';
			return;
		}

		$platform_pct = self::get_vendor_platform_pct( $vendor_id );
		$vendor_pct   = 100.0 - $platform_pct;
		$global_pct   = (float) get_option( 'lt_comm_platform_percentage', 10.0 );
		$is_custom_rate = '' !== get_user_meta( $vendor_id, '_lt_comm_vendor_platform_pct', true );

		$products = wc_get_products( [
			'author'  => $vendor_id,
			'status'  => [ 'publish', 'draft' ],
			'limit'   => -1,
			'orderby' => 'title',
			'order'   => 'ASC',
		] );

		// All vendors for the payee dropdown (cross-vendor splits).
		$all_vendors = get_users( [
			'role__in' => [ 'seller', 'vendor' ],
			'number'   => -1,
			'orderby'  => 'display_name',
		] );

		$back_url = admin_url( 'admin.php?page=lt-commissions' );
		$save_url = admin_url( 'admin-post.php' );
		?>
		<div class="wrap">
			<h1>
				<a href="<?php echo esc_url( $back_url ); ?>" style="font-size:14px;font-weight:400;text-decoration:none;margin-right:10px">&#8592; All Vendors</a>
				<?php echo esc_html( $vendor->display_name ); ?>
			</h1>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Saved.</p></div>
			<?php endif; ?>

			<!-- ── Per-vendor commission rate ── -->
			<div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:16px 20px;max-width:480px;margin-bottom:28px">
				<h2 style="margin-top:0;font-size:15px">Commission Rate</h2>
				<form method="post" action="<?php echo esc_url( $save_url ); ?>">
					<input type="hidden" name="action"    value="lt_comm_save_vendor_rate">
					<input type="hidden" name="vendor_id" value="<?php echo esc_attr( $vendor_id ); ?>">
					<?php wp_nonce_field( 'lt_comm_save_vendor_rate_' . $vendor_id ); ?>

					<table class="form-table" style="margin:0">
						<tr>
							<th style="width:150px"><label for="lt_comm_vpct">Platform takes</label></th>
							<td>
								<input type="number" id="lt_comm_vpct" name="lt_comm_vendor_platform_pct"
									value="<?php echo esc_attr( number_format( $platform_pct, 2 ) ); ?>"
									min="0" max="100" step="0.01" style="width:80px"> %
								<?php if ( ! $is_custom_rate ) : ?>
									<span style="color:#999;font-size:12px">(global default)</span>
								<?php else : ?>
									<span style="color:#c9a84c;font-size:12px">(custom &mdash; global is <?php echo esc_html( $global_pct ); ?>%)</span>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th>Vendor receives</th>
							<td>
								<strong id="lt-vend-pct-display"><?php echo esc_html( number_format( $vendor_pct, 2 ) ); ?>%</strong>
								of item subtotal
							</td>
						</tr>
					</table>

					<p style="margin-top:12px">
						<?php submit_button( 'Save Rate', 'primary', 'submit', false ); ?>
						<?php if ( $is_custom_rate ) : ?>
							&nbsp;<button type="submit" name="lt_comm_reset_rate" value="1" class="button">Reset to Global (<?php echo esc_html( $global_pct ); ?>%)</button>
						<?php endif; ?>
					</p>
				</form>
			</div>

			<!-- ── Products ── -->
			<h2>Products
				<span style="font-size:13px;font-weight:400;color:#666;margin-left:8px"><?php echo esc_html( count( $products ) ); ?> product<?php echo count( $products ) !== 1 ? 's' : ''; ?></span>
			</h2>

			<?php if ( empty( $products ) ) : ?>
				<p>No products found for this vendor.</p>
			<?php else : ?>
			<table class="widefat" id="lt-vend-products-table" style="max-width:1100px">
				<thead>
					<tr>
						<th style="width:32%">Product</th>
						<th style="width:12%">Price</th>
						<th>Commission Split</th>
						<th style="width:80px"></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $products as $product ) :
					$pid       = $product->get_id();
					$is_custom = (bool) get_post_meta( $pid, '_lt_comm_override_enabled', true );
					$rules     = LT_Comm_Split_Rules::get_for_product( $pid, $vendor_id );

					// Price display.
					if ( $product->is_type( 'variable' ) ) {
						$price_html = wc_price( $product->get_variation_price( 'min' ) ) . ' – ' . wc_price( $product->get_variation_price( 'max' ) );
					} else {
						$price_html = wc_price( $product->get_price() ?: 0 );
					}

					// Rules summary for the collapsed row.
					$summary_parts = [];
					foreach ( $rules as $rule ) {
						if ( $rule['payee_type'] === 'platform' ) {
							$who = 'Platform';
						} elseif ( (int) $rule['payee_id'] > 0 ) {
							$u   = get_userdata( (int) $rule['payee_id'] );
							$who = $u ? esc_html( $u->display_name ) : 'User #' . (int) $rule['payee_id'];
						} else {
							$who = 'Vendor';
						}
						$val            = $rule['type'] === 'flat'
							? '$' . number_format( (float) $rule['value'], 2 )
							: number_format( (float) $rule['value'], 1 ) . '%';
						$summary_parts[] = $who . ': ' . $val;
					}
				?>
					<!-- Summary row -->
					<tr class="lt-vend-product-row">
						<td>
							<strong><?php echo esc_html( $product->get_name() ); ?></strong>
							<?php if ( $is_custom ) : ?>
								<span style="color:#c9a84c;font-size:11px;margin-left:6px">Custom</span>
							<?php endif; ?>
						</td>
						<td style="font-size:13px"><?php echo wp_kses_post( $price_html ); ?></td>
						<td style="font-size:12px;color:#666"><?php echo esc_html( implode( ' | ', $summary_parts ) ); ?></td>
						<td>
							<button type="button" class="button button-small lt-vend-edit-btn"
								data-product="<?php echo esc_attr( $pid ); ?>">Edit</button>
						</td>
					</tr>

					<!-- Inline editor row (hidden) -->
					<tr id="lt-vend-editor-<?php echo esc_attr( $pid ); ?>" style="display:none">
						<td colspan="4" style="background:#f6f7f7;padding:16px 20px;border-top:1px solid #e1e1e1">
							<form method="post" action="<?php echo esc_url( $save_url ); ?>" class="lt-vend-product-form">
								<input type="hidden" name="action"     value="lt_comm_save_product_rules">
								<input type="hidden" name="vendor_id"  value="<?php echo esc_attr( $vendor_id ); ?>">
								<input type="hidden" name="product_id" value="<?php echo esc_attr( $pid ); ?>">
								<?php wp_nonce_field( 'lt_comm_save_product_rules_' . $pid ); ?>

								<label style="display:flex;align-items:center;gap:8px;margin-bottom:14px;font-weight:600;cursor:pointer">
									<input type="checkbox" name="lt_comm_override_enabled" value="1"
										class="lt-vend-override-cb"
										<?php checked( $is_custom ); ?>>
									Use custom split rules for this product
								</label>

								<div class="lt-vend-rules-wrap"<?php if ( ! $is_custom ) echo ' style="display:none"'; /* safe: $is_custom is a boolean from local logic */ ?>>
									<table class="widefat lt-vend-splits-table"
										style="max-width:780px;margin-bottom:8px"
										data-product="<?php echo esc_attr( $pid ); ?>"
										data-next-idx="<?php echo esc_attr( count( $rules ) ); ?>">
										<thead>
											<tr>
												<th style="width:110px">Payee</th>
												<th style="width:180px">User / Vendor</th>
												<th style="width:90px">Type</th>
												<th style="width:80px">Value</th>
												<th>Label</th>
												<th style="width:36px"></th>
											</tr>
										</thead>
										<tbody>
										<?php foreach ( $rules as $ri => $rule ) : ?>
											<tr>
												<td>
													<select name="lt_comm_rules[<?php echo esc_attr( $pid ); ?>][<?php echo $ri; ?>][payee_type]"
														class="lt-vend-payee-type" style="width:100%">
														<option value="vendor"   <?php selected( $rule['payee_type'], 'vendor' ); ?>>Vendor</option>
														<option value="platform" <?php selected( $rule['payee_type'], 'platform' ); ?>>Platform</option>
													</select>
												</td>
												<td>
													<select name="lt_comm_rules[<?php echo esc_attr( $pid ); ?>][<?php echo $ri; ?>][payee_id]"
														class="lt-vend-payee-id" style="width:100%">
														<option value="0">(this vendor / platform)</option>
														<?php foreach ( $all_vendors as $v ) : ?>
															<option value="<?php echo esc_attr( $v->ID ); ?>"
																<?php selected( (int) $rule['payee_id'], $v->ID ); ?>>
																<?php echo esc_html( $v->display_name ); ?>
															</option>
														<?php endforeach; ?>
													</select>
												</td>
												<td>
													<select name="lt_comm_rules[<?php echo esc_attr( $pid ); ?>][<?php echo $ri; ?>][type]"
														class="lt-vend-type-select" style="width:100%">
														<option value="percentage" <?php selected( $rule['type'], 'percentage' ); ?>>% of item</option>
														<option value="flat"       <?php selected( $rule['type'], 'flat' ); ?>>Flat $</option>
													</select>
												</td>
												<td>
													<input type="number"
														name="lt_comm_rules[<?php echo esc_attr( $pid ); ?>][<?php echo $ri; ?>][value]"
														value="<?php echo esc_attr( $rule['value'] ); ?>"
														min="0" step="0.01" style="width:70px"
														class="lt-vend-value">
												</td>
												<td>
													<input type="text"
														name="lt_comm_rules[<?php echo esc_attr( $pid ); ?>][<?php echo $ri; ?>][label]"
														value="<?php echo esc_attr( $rule['label'] ); ?>"
														style="width:100%" placeholder="e.g. Vendor earnings">
												</td>
												<td>
													<button type="button" class="button button-small lt-vend-remove-row" title="Remove row">&times;</button>
												</td>
											</tr>
										<?php endforeach; ?>
										</tbody>
									</table>

									<button type="button" class="button lt-vend-add-row"
										data-product="<?php echo esc_attr( $pid ); ?>">+ Add Row</button>
									<span class="lt-vend-pct-total" style="margin-left:12px;font-weight:600;font-size:13px"></span>

									<p style="margin-top:6px;font-size:12px;color:#888">
										Percentages must sum to 100%. Set payee to a specific vendor to split commission across multiple accounts (e.g. designer + fulfiller).
									</p>
								</div>

								<p style="margin-top:14px">
									<?php submit_button( 'Save', 'primary', 'save_rules', false, [ 'style' => 'margin-right:6px' ] ); ?>
									<button type="button" class="button lt-vend-cancel-btn"
										data-product="<?php echo esc_attr( $pid ); ?>">Cancel</button>
									<?php if ( $is_custom ) : ?>
										<button type="submit" name="lt_comm_reset_rules" value="1"
											class="button" style="margin-left:8px;color:#a00">
											Reset to Default
										</button>
									<?php endif; ?>
								</p>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>

		<script>
		(function(){
			var inp  = document.getElementById('lt_comm_vpct');
			var disp = document.getElementById('lt-vend-pct-display');
			if ( ! inp || ! disp ) return;
			inp.addEventListener('input', function(){
				var v = parseFloat(this.value) || 0;
				disp.textContent = (100 - v).toFixed(2) + '%';
			});
		}());
		</script>
		<?php
	}

	// ── Form handlers ─────────────────────────────────────────────────────────

	public static function handle_save_vendor_rate() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}
		$vendor_id = (int) ( $_POST['vendor_id'] ?? 0 );
		check_admin_referer( 'lt_comm_save_vendor_rate_' . $vendor_id );

		if ( isset( $_POST['lt_comm_reset_rate'] ) ) {
			delete_user_meta( $vendor_id, '_lt_comm_vendor_platform_pct' );
		} else {
			$pct = max( 0.0, min( 100.0, (float) ( $_POST['lt_comm_vendor_platform_pct'] ?? 10.0 ) ) );
			update_user_meta( $vendor_id, '_lt_comm_vendor_platform_pct', $pct );
		}

		wp_redirect( add_query_arg(
			[ 'page' => 'lt-commissions', 'vendor' => $vendor_id, 'saved' => '1' ],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	public static function handle_save_product_rules() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}
		$product_id = (int) ( $_POST['product_id'] ?? 0 );
		$vendor_id  = (int) ( $_POST['vendor_id']  ?? 0 );
		check_admin_referer( 'lt_comm_save_product_rules_' . $product_id );

		if ( isset( $_POST['lt_comm_reset_rules'] ) ) {
			LT_Comm_Split_Rules::clear_for_product( $product_id );
		} elseif ( ! empty( $_POST['lt_comm_override_enabled'] ) ) {
			$raw = isset( $_POST['lt_comm_rules'][ $product_id ] )
				? (array) $_POST['lt_comm_rules'][ $product_id ]
				: [];
			$rules = [];
			foreach ( $raw as $row ) {
				$rules[] = [
					'payee_type' => sanitize_text_field( $row['payee_type'] ?? 'platform' ),
					'payee_id'   => (int) ( $row['payee_id'] ?? 0 ),
					'type'       => sanitize_text_field( $row['type'] ?? 'percentage' ),
					'value'      => (float) ( $row['value'] ?? 0 ),
					'label'      => sanitize_text_field( $row['label'] ?? '' ),
				];
			}
			$result = LT_Comm_Split_Rules::save_for_product( $product_id, $rules );
			if ( is_wp_error( $result ) ) {
				wp_die( esc_html( $result->get_error_message() ) );
			}
		} else {
			LT_Comm_Split_Rules::clear_for_product( $product_id );
		}

		wp_redirect( add_query_arg(
			[ 'page' => 'lt-commissions', 'vendor' => $vendor_id, 'saved' => '1' ],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	public static function get_vendor_platform_pct( $vendor_id ) {
		$custom = get_user_meta( $vendor_id, '_lt_comm_vendor_platform_pct', true );
		if ( $custom !== '' ) {
			return (float) $custom;
		}
		return (float) get_option( 'lt_comm_platform_percentage', 10.0 );
	}

	private static function get_vendor_lifetime_earnings( $vendor_id ) {
		$cache_key = 'lt_comm_earn_' . $vendor_id;
		$cached    = get_transient( $cache_key );
		if ( $cached !== false ) {
			return (float) $cached;
		}

		global $wpdb;
		$orders = wc_get_orders( [
			'meta_key'   => '_dokan_vendor_id',
			'meta_value' => $vendor_id,
			'limit'      => -1,
			'return'     => 'ids',
			'status'     => array_keys( wc_get_order_statuses() ),
		] );

		$total = 0.0;
		foreach ( $orders as $oid ) {
			$o = wc_get_order( $oid );
			if ( $o ) {
				$payout = (float) $o->get_meta( '_lt_comm_vendor_payout' );
				$total += $payout;
			}
		}

		set_transient( $cache_key, $total, HOUR_IN_SECONDS );
		return $total;
	}

	// ── Cross-Vendor Payouts Report ────────────────────────────────────────────

	public static function render_payouts() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Handle mark-all-paid action for a vendor.
		if (
			isset( $_POST['lt_mark_paid_nonce'] ) &&
			wp_verify_nonce( sanitize_key( $_POST['lt_mark_paid_nonce'] ), 'lt_mark_paid' ) &&
			isset( $_POST['vendor_id'] )
		) {
			$vendor_id = (int) $_POST['vendor_id'];
			$all_ids   = wc_get_orders( [
				'limit'      => -1,
				'return'     => 'ids',
				'meta_query' => [ [ 'key' => '_lt_comm_cross_vendor_payouts', 'compare' => 'EXISTS' ] ],
			] );
			$count = 0;
			foreach ( $all_ids as $oid ) {
				$o       = wc_get_order( (int) $oid );
				$payouts = $o ? $o->get_meta( '_lt_comm_cross_vendor_payouts' ) : null;
				if ( ! is_array( $payouts ) ) continue;
				$changed = false;
				foreach ( $payouts as &$p ) {
					if ( (int) $p['vendor_id'] === $vendor_id && empty( $p['paid'] ) ) {
						$p['paid']    = true;
						$p['paid_at'] = gmdate( 'c' );
						$changed      = true;
						$count++;
					}
				}
				unset( $p );
				if ( $changed ) {
					$o->update_meta_data( '_lt_comm_cross_vendor_payouts', $payouts );
					$o->save();
				}
			}
			wp_redirect( add_query_arg( [ 'page' => 'lt-commissions-payouts', 'marked' => $count ], admin_url( 'admin.php' ) ) );
			exit;
		}

		// Aggregate all cross-vendor payouts from order meta.
		// Use wc_get_orders() so the query works with both HPOS and legacy storage.
		$order_ids = wc_get_orders( [
			'limit'      => -1,
			'return'     => 'ids',
			'meta_query' => [ [ 'key' => '_lt_comm_cross_vendor_payouts', 'compare' => 'EXISTS' ] ],
		] );

		$by_vendor = [];
		foreach ( $order_ids as $oid ) {
			$o       = wc_get_order( (int) $oid );
			$payouts = $o ? $o->get_meta( '_lt_comm_cross_vendor_payouts' ) : null;
			if ( ! is_array( $payouts ) ) {
				continue;
			}
			foreach ( $payouts as $p ) {
				$vid = (int) $p['vendor_id'];
				if ( ! isset( $by_vendor[ $vid ] ) ) {
					$ud                = get_userdata( $vid );
					$by_vendor[ $vid ] = [
						'name'   => $ud ? $ud->display_name : "Vendor #{$vid}",
						'unpaid' => [],
						'paid'   => [],
					];
				}
				$entry = [
					'order_id'    => (int) $oid,
					'amount'      => (float) $p['amount'],
					'recorded_at' => $p['recorded_at'] ?? '',
					'paid_at'     => $p['paid_at'] ?? '',
				];
				if ( ! empty( $p['paid'] ) ) {
					$by_vendor[ $vid ]['paid'][] = $entry;
				} else {
					$by_vendor[ $vid ]['unpaid'][] = $entry;
				}
			}
		}
		uasort( $by_vendor, fn( $a, $b ) => strcmp( $a['name'], $b['name'] ) );
		?>
		<div class="wrap">
			<h1>Cross-Vendor Payouts</h1>
			<p>Commissions owed to secondary vendors (designers, contributors). Pay out manually at month end, then mark all unpaid entries for a vendor as paid.</p>
			<?php if ( ! empty( $_GET['marked'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo (int) $_GET['marked']; ?> commission(s) marked as paid.</p></div>
			<?php endif; ?>
			<?php if ( empty( $by_vendor ) ) : ?>
				<p>No cross-vendor commissions recorded yet.</p>
			<?php else : ?>
				<?php foreach ( $by_vendor as $vid => $data ) :
					$unpaid_total = array_sum( array_column( $data['unpaid'], 'amount' ) );
					$paid_total   = array_sum( array_column( $data['paid'], 'amount' ) );
				?>
				<div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:16px 20px;margin-bottom:24px;max-width:900px">
					<h2 style="margin:0 0 4px;font-size:16px"><?php echo esc_html( $data['name'] ); ?></h2>
					<p style="margin:0 0 12px;color:#555;font-size:13px">
						<strong style="color:#ca4a1f">Unpaid: <?php echo wc_price( $unpaid_total ); ?></strong>
						<?php if ( $paid_total > 0 ) : ?>
							&nbsp;|&nbsp;<span style="color:#0a8e27">Paid all-time: <?php echo wc_price( $paid_total ); ?></span>
						<?php endif; ?>
					</p>
					<?php if ( ! empty( $data['unpaid'] ) ) : ?>
					<table class="widefat striped" style="font-size:13px;margin-bottom:8px">
						<thead><tr><th>Order</th><th>Date Earned</th><th>Amount</th></tr></thead>
						<tbody>
						<?php foreach ( $data['unpaid'] as $e ) :
							$date_fmt  = $e['recorded_at'] ? date_i18n( get_option( 'date_format' ), strtotime( $e['recorded_at'] ) ) : '-';
							$order_url = admin_url( 'post.php?post=' . $e['order_id'] . '&action=edit' );
						?>
						<tr>
							<td><a href="<?php echo esc_url( $order_url ); ?>">#<?php echo esc_html( $e['order_id'] ); ?></a></td>
							<td><?php echo esc_html( $date_fmt ); ?></td>
							<td><strong><?php echo wc_price( $e['amount'] ); ?></strong></td>
						</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<form method="post" style="margin-top:8px">
						<?php wp_nonce_field( 'lt_mark_paid', 'lt_mark_paid_nonce' ); ?>
						<input type="hidden" name="vendor_id" value="<?php echo esc_attr( $vid ); ?>">
						<button type="submit" class="button button-primary" onclick="return confirm('Mark all unpaid entries for this vendor as paid?')">Mark All Paid (<?php echo wc_price( $unpaid_total ); ?>)</button>
					</form>
					<?php endif; ?>
					<?php if ( ! empty( $data['paid'] ) ) : ?>
					<details style="margin-top:8px">
						<summary style="cursor:pointer;font-size:12px;color:#555">Show paid history (<?php echo count( $data['paid'] ); ?> entries)</summary>
						<table class="widefat striped" style="font-size:12px;margin-top:8px;color:#888">
							<thead><tr><th>Order</th><th>Date Earned</th><th>Amount</th><th>Paid On</th></tr></thead>
							<tbody>
							<?php foreach ( $data['paid'] as $e ) :
								$order_url = admin_url( 'post.php?post=' . $e['order_id'] . '&action=edit' );
								$earned    = $e['recorded_at'] ? date_i18n( get_option( 'date_format' ), strtotime( $e['recorded_at'] ) ) : '-';
								$paid_on   = $e['paid_at'] ? date_i18n( get_option( 'date_format' ), strtotime( $e['paid_at'] ) ) : '-';
							?>
							<tr>
								<td><a href="<?php echo esc_url( $order_url ); ?>">#<?php echo esc_html( $e['order_id'] ); ?></a></td>
								<td><?php echo esc_html( $earned ); ?></td>
								<td><?php echo wc_price( $e['amount'] ); ?></td>
								<td style="color:#0a8e27">&#10003; <?php echo esc_html( $paid_on ); ?></td>
							</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</details>
					<?php endif; ?>
				</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── Commission Health Check & Reconcile ────────────────────────────────────

	public static function render_health_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;

		// Handle reconcile action.
		if (
			isset( $_POST['lt_reconcile_nonce'] ) &&
			wp_verify_nonce( sanitize_key( $_POST['lt_reconcile_nonce'] ), 'lt_reconcile' )
		) {
			$fixed = self::reconcile_all_orders();
			wp_redirect( add_query_arg( [ 'page' => 'lt-commissions-health', 'fixed' => $fixed ], admin_url( 'admin.php' ) ) );
			exit;
		}

		// Check Dokan tables exist.
		$tables_ok = true;
		$required  = [ 'dokan_orders', 'dokan_order_stats', 'dokan_vendor_balance' ];
		$missing   = [];
		foreach ( $required as $t ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $t ) );
			if ( ! $exists ) {
				$tables_ok = false;
				$missing[] = $wpdb->prefix . $t;
			}
		}

		// Get all commission-calculated orders.
		$order_ids = wc_get_orders( [
			'limit'      => -1,
			'return'     => 'ids',
			'meta_query' => [ [ 'key' => '_lt_comm_calculated_at', 'compare' => 'EXISTS' ] ],
		] );

		$mismatches = [];
		foreach ( $order_ids as $oid ) {
			$order  = wc_get_order( (int) $oid );
			if ( ! $order ) continue;

			$expected = (float) $order->get_meta( '_lt_comm_vendor_payout' );
			$vid      = (int) $order->get_meta( '_dokan_vendor_id' );
			if ( ! $vid || ! $expected ) continue;

			$issues = [];

			// Check dokan_orders.net_amount
			$net = $wpdb->get_var( $wpdb->prepare(
				"SELECT net_amount FROM {$wpdb->prefix}dokan_orders WHERE order_id = %d", $oid
			) );
			if ( $net !== null && abs( (float) $net - $expected ) > 0.01 ) {
				$issues[] = 'dokan_orders.net_amount=' . number_format( (float) $net, 2 ) ;
			}

			// Check dokan_vendor_balance.debit
			$debit = $wpdb->get_var( $wpdb->prepare(
				"SELECT debit FROM {$wpdb->prefix}dokan_vendor_balance WHERE trn_id = %d AND vendor_id = %d AND trn_type = 'dokan_orders'",
				$oid, $vid
			) );
			if ( $debit !== null && abs( (float) $debit - $expected ) > 0.01 ) {
				$issues[] = 'balance.debit=' . number_format( (float) $debit, 2 );
			}

			// Check dokan_order_stats.vendor_earning
			$earning = $wpdb->get_var( $wpdb->prepare(
				"SELECT vendor_earning FROM {$wpdb->prefix}dokan_order_stats WHERE order_id = %d", $oid
			) );
			if ( $earning !== null && abs( (float) $earning - $expected ) > 0.01 ) {
				$issues[] = 'stats.earning=' . number_format( (float) $earning, 2 );
			}

			if ( ! empty( $issues ) ) {
				$mismatches[] = [
					'order_id' => $oid,
					'expected' => $expected,
					'issues'   => $issues,
				];
			}
		}

		?>
		<div class="wrap">
			<h1>Commission Health Check</h1>
			<p>Compares our calculated payouts against Dokan's internal tables. Run this after a Dokan update to catch mismatches.</p>

			<?php if ( ! empty( $_GET['fixed'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo (int) $_GET['fixed']; ?> order(s) reconciled.</p></div>
			<?php endif; ?>

			<h2>Dokan Tables</h2>
			<?php if ( $tables_ok ) : ?>
				<p style="color:#0a8e27">&#10003; All required Dokan tables present (dokan_orders, dokan_order_stats, dokan_vendor_balance)</p>
			<?php else : ?>
				<div class="notice notice-error"><p>Missing tables: <strong><?php echo esc_html( implode( ', ', $missing ) ); ?></strong>. A Dokan update may have renamed or removed them. Commission corrections may not work until this is resolved.</p></div>
			<?php endif; ?>

			<h2>Order Audit (<?php echo count( $order_ids ); ?> orders with commissions)</h2>
			<?php if ( empty( $mismatches ) ) : ?>
				<p style="color:#0a8e27">&#10003; All orders match. No mismatches found.</p>
			<?php else : ?>
				<div class="notice notice-warning">
					<p><strong><?php echo count( $mismatches ); ?> mismatch(es) found.</strong> Dokan's tables don't match our calculated payouts.</p>
				</div>
				<table class="widefat striped" style="font-size:13px;max-width:900px;margin-bottom:16px">
					<thead><tr><th>Order</th><th>Expected Payout</th><th>Dokan Has</th></tr></thead>
					<tbody>
					<?php foreach ( $mismatches as $m ) :
						$order_url = admin_url( 'post.php?post=' . $m['order_id'] . '&action=edit' );
					?>
					<tr>
						<td><a href="<?php echo esc_url( $order_url ); ?>">#<?php echo esc_html( $m['order_id'] ); ?></a></td>
						<td><strong><?php echo wc_price( $m['expected'] ); ?></strong></td>
						<td style="color:#ca4a1f"><?php echo esc_html( implode( ' | ', $m['issues'] ) ); ?></td>
					</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<form method="post">
					<?php wp_nonce_field( 'lt_reconcile', 'lt_reconcile_nonce' ); ?>
					<button type="submit" class="button button-primary" onclick="return confirm('This will overwrite Dokan\'s values for all mismatched orders. Continue?')">Reconcile All (<?php echo count( $mismatches ); ?> orders)</button>
				</form>
			<?php endif; ?>

			<h2 style="margin-top:24px">Hook Status</h2>
			<p style="font-size:13px;color:#555">
				<code>correct_vendor_balance</code> registered at priority 999 on <code>woocommerce_order_status_changed</code>:
				<?php
				$found = has_action( 'woocommerce_order_status_changed', [ 'LT_Comm_Order_Processor', 'correct_vendor_balance' ] );
				echo $found ? '<span style="color:#0a8e27">&#10003; Active (priority ' . (int) $found . ')</span>' : '<span style="color:#ca4a1f">&#10007; Not found — commission corrections are NOT running!</span>';
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Re-sync all commission orders: overwrite Dokan's three tables with our calculated values.
	 *
	 * @return int Number of orders fixed.
	 */
	private static function reconcile_all_orders() {
		global $wpdb;

		$order_ids = wc_get_orders( [
			'limit'      => -1,
			'return'     => 'ids',
			'meta_query' => [ [ 'key' => '_lt_comm_calculated_at', 'compare' => 'EXISTS' ] ],
		] );

		$fixed = 0;
		foreach ( $order_ids as $oid ) {
			$order = wc_get_order( (int) $oid );
			if ( ! $order ) continue;

			$payout   = (float) $order->get_meta( '_lt_comm_vendor_payout' );
			$platform = (float) $order->get_meta( '_lt_comm_platform_earnings' );
			$vid      = (int) $order->get_meta( '_dokan_vendor_id' );
			if ( ! $vid || ! $payout ) continue;

			$changed = false;

			// dokan_orders
			$net = $wpdb->get_var( $wpdb->prepare(
				"SELECT net_amount FROM {$wpdb->prefix}dokan_orders WHERE order_id = %d", $oid
			) );
			if ( $net !== null && abs( (float) $net - $payout ) > 0.01 ) {
				$wpdb->update( $wpdb->prefix . 'dokan_orders', [ 'net_amount' => $payout ], [ 'order_id' => $oid ], [ '%f' ], [ '%d' ] );
				$changed = true;
			}

			// dokan_vendor_balance
			$debit = $wpdb->get_var( $wpdb->prepare(
				"SELECT debit FROM {$wpdb->prefix}dokan_vendor_balance WHERE trn_id = %d AND vendor_id = %d AND trn_type = 'dokan_orders'",
				$oid, $vid
			) );
			if ( $debit !== null && abs( (float) $debit - $payout ) > 0.01 ) {
				$wpdb->update( $wpdb->prefix . 'dokan_vendor_balance', [ 'debit' => $payout ], [ 'trn_id' => $oid, 'vendor_id' => $vid, 'trn_type' => 'dokan_orders' ], [ '%f' ], [ '%d', '%d', '%s' ] );
				$changed = true;
			}

			// dokan_order_stats
			$earning = $wpdb->get_var( $wpdb->prepare(
				"SELECT vendor_earning FROM {$wpdb->prefix}dokan_order_stats WHERE order_id = %d", $oid
			) );
			if ( $earning !== null && abs( (float) $earning - $payout ) > 0.01 ) {
				$wpdb->update( $wpdb->prefix . 'dokan_order_stats', [ 'vendor_earning' => $payout, 'admin_commission' => $platform ], [ 'order_id' => $oid ], [ '%f', '%f' ], [ '%d' ] );
				$changed = true;
			}

			if ( $changed ) {
				$fixed++;
			}
		}

		return $fixed;
	}

}
