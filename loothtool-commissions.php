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
