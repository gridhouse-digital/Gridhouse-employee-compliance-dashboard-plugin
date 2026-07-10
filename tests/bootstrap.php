<?php
// Standalone bootstrap so pure logic can run without WordPress.
// The main plugin file self-boots (::init() at the bottom), so the hook
// registration functions are stubbed as no-ops before it loads.
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'WP_PLUGIN_DIR' ) ) { define( 'WP_PLUGIN_DIR', __DIR__ ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }

if ( ! function_exists( 'add_action' ) ) { function add_action( ...$args ) {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter( ...$args ) {} }

require_once __DIR__ . '/../gridhouse-employee-compliance-dashboard.php';
