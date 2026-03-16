<?php
/**
 * Plugin Name: Loothtool Commissions
 * Description: Split commission engine for Dokan Pro. Bases all commissions on
 *              item subtotal only (excludes shipping, tax, and processing fees).
 *              Supports multiple payees per product (designer, printer, platform).
 * Version: 1.0.0
 * Requires Plugins: woocommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'LT_COMM_PATH', plugin_dir_path( __FILE__ ) );
define( 'LT_COMM_URL',  plugin_dir_url( __FILE__ ) );
define( 'LT_COMM_VER',  '1.0.0' );

/**
 * The Dokan major version this plugin was last tested and deployed against.
 * Update this constant AFTER verifying the plugin still works on a new major.
 */
define( 'LT_COMM_DOKAN_TESTED_MAJOR', 4 );

// ── Dokan compatibility guard ─────────────────────────────────────────────────

add_action( 'plugins_loaded', function() {
	lt_comm_run_compat_checks();
}, 25 ); // After our own init (priority 20) so Dokan constants are available.

function lt_comm_run_compat_checks() {
	global $wpdb;
	$issues = [];

	// ── 1. Dokan version sentinel ────────────────────────────────────────────
	if ( defined( 'DOKAN_PLUGIN_VERSION' ) ) {
		$current_major = (int) explode( '.', DOKAN_PLUGIN_VERSION )[0];
		$last_major    = (int) get_option( 'lt_comm_dokan_last_major', 0 );

		if ( $last_major === 0 ) {
			// First run — just record it, no alarm.
			update_option( 'lt_comm_dokan_last_major', $current_major );
		} elseif ( $current_major !== $last_major ) {
			// Major version changed since last load.
			update_option( 'lt_comm_dokan_last_major', $current_major );
			$issues[] = sprintf(
				'Dokan major version changed from %d → %d. Verify commission hooks, _dokan_vendor_to_pay, and wp_dokan_vendor_balance are still intact before processing orders.',
				$last_major,
				$current_major
			);
		}

		if ( $current_major > LT_COMM_DOKAN_TESTED_MAJOR ) {
			$issues[] = sprintf(
				'Dokan %s has not been tested with LT Commissions (tested up to major version %d). Update LT_COMM_DOKAN_TESTED_MAJOR in loothtool-commissions.php after verifying.',
				DOKAN_PLUGIN_VERSION,
				LT_COMM_DOKAN_TESTED_MAJOR
			);
		}
	}

	// ── 2. Balance table existence check (cached 24 h) ───────────────────────
	$table_ok = get_transient( 'lt_comm_balance_table_ok' );
	if ( false === $table_ok ) {
		$table_name = $wpdb->prefix . 'dokan_vendor_balance';
		$exists     = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		$table_ok   = $exists ? '1' : '0';
		set_transient( 'lt_comm_balance_table_ok', $table_ok, DAY_IN_SECONDS );
	}
	if ( $table_ok === '0' ) {
		$issues[] = 'Table wp_dokan_vendor_balance not found. Vendor balance corrections will not apply until Dokan re-creates it.';
	}

	// ── 3. Surface issues ────────────────────────────────────────────────────
	if ( empty( $issues ) ) {
		return;
	}

	foreach ( $issues as $msg ) {
		error_log( '[LT Commissions] COMPAT WARNING: ' . $msg );
	}

	// Show admin notice to anyone who can manage options.
	add_action( 'admin_notices', function() use ( $issues ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>LT Commissions — Compatibility Warning</strong></p>';
		echo '<ul>';
		foreach ( $issues as $msg ) {
			echo '<li><strong>' . esc_html( $msg ) . '</strong></li>';
		}
		echo '</ul>';
		echo '<p><strong>Do not process new orders until you have completed these steps:</strong></p>';
		echo '<ol style="list-style:decimal;margin-left:20px;line-height:1.8">';
		echo '<li>Check the Dokan changelog for anything mentioning <em>commission</em>, <em>vendor balance</em>, or <em>order processing</em>.</li>';
		echo '<li>SSH into the server and check the PHP error log for related errors:<br><code>sudo tail -100 /opt/bitnami/php/logs/php-fpm.log | grep -i "dokan\|lt_comm\|vendor_balance"</code></li>';
		echo '<li>Place a test order on the staging site using a test payment method.</li>';
		echo '<li>Verify the commission calculated correctly by running the reprocess script on that order:<br><code>sudo /opt/bitnami/php/bin/php /tmp/reprocess_ORDER_ID.php</code></li>';
		echo '<li>Check WP Admin &rarr; LT Commissions to confirm the vendor payout figure is correct.</li>';
		echo '<li>If everything looks right, update <code>LT_COMM_DOKAN_TESTED_MAJOR</code> in <code>loothtool-commissions.php</code> to the new major version number to clear this notice.</li>';
		echo '<li>If something is broken, <strong>do not update the constant</strong> — this notice will keep showing as a reminder. Roll back Dokan via WP Admin &rarr; Plugins if needed.</li>';
		echo '</ol>';
		echo '<p style="color:#888;font-size:12px">This message is also recorded in the PHP error log with the prefix <code>[LT Commissions] COMPAT WARNING</code>.</p>';
		echo '</div>';
	} );
}

// ── Load after all plugins (Dokan loads at priority 15) ───────────────────────

add_action( 'plugins_loaded', function() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	require_once LT_COMM_PATH . 'includes/class-split-rules.php';
	require_once LT_COMM_PATH . 'includes/class-commission-calculator.php';
	require_once LT_COMM_PATH . 'includes/class-order-processor.php';
	require_once LT_COMM_PATH . 'includes/class-admin-settings.php';
	require_once LT_COMM_PATH . 'includes/class-admin-vendors.php';
	require_once LT_COMM_PATH . 'includes/class-product-commission-meta.php';
	require_once LT_COMM_PATH . 'includes/class-vendor-dashboard.php';

	LT_Comm_Commission_Calculator::init();
	LT_Comm_Order_Processor::init();
	LT_Comm_Admin_Settings::init();
	LT_Comm_Admin_Vendors::init();
	LT_Comm_Product_Meta::init();
	LT_Comm_Vendor_Dashboard::init();
}, 20 );

// ── Activation ────────────────────────────────────────────────────────────────

register_activation_hook( __FILE__, function() {
	if ( false === get_option( 'lt_comm_platform_percentage' ) ) {
		update_option( 'lt_comm_platform_percentage', 10.0 );
	}
	if ( false === get_option( 'lt_comm_default_commission_type' ) ) {
		update_option( 'lt_comm_default_commission_type', 'percentage' );
	}
	if ( false === get_option( 'lt_comm_audit_log_enabled' ) ) {
		update_option( 'lt_comm_audit_log_enabled', '1' );
	}
	flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function() {
	flush_rewrite_rules();
} );
