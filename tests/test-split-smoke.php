<?php
// Run: php tests/test-split-smoke.php
// Verifies the Phase-B class split: all classes load and the shortcode map is intact.
if ( ! function_exists( 'add_shortcode' ) ) {
  $GLOBALS['ghca_test_shortcodes'] = array();
  function add_shortcode( $tag, $cb ) {
    $GLOBALS['ghca_test_shortcodes'][ $tag ] = $cb;
  }
}

require_once __DIR__ . '/bootstrap.php';

$failures = 0;

function check( string $label, bool $ok ): void {
  global $failures;
  if ( $ok ) {
    echo "PASS  {$label}\n";
  } else {
    ++$failures;
    echo "FAIL  {$label}\n";
  }
}

foreach ( array(
  'GHCA_Employee_Compliance_Dashboard',
  'GHCA_ECD_Data_Provider',
  'GHCA_ECD_Shortcodes',
  'GHCA_ECD_Settings',
  'GHCA_ECD_UI_Icons',
) as $class ) {
  check( "class {$class} exists", class_exists( $class ) );
}

GHCA_ECD_Shortcodes::register_shortcodes();

$expected = array(
  'employee_compliance_login_gate',
  'employee_compliance_notification',
  'employee_compliance_welcome',
  'employee_compliance_status_cards',
  'employee_next_required_course',
  'employee_required_actions',
  'employee_compliance_announcements',
  'employee_compliance_quick_links',
  'employee_compliance_support',
  'employee_compliance_trio',
  'employee_compliance_dashboard',
);

check( '11 shortcodes registered', count( $GLOBALS['ghca_test_shortcodes'] ) === 11 );

foreach ( $expected as $tag ) {
  $cb = $GLOBALS['ghca_test_shortcodes'][ $tag ] ?? null;
  check(
    "shortcode [{$tag}] maps to a callable GHCA_ECD_Shortcodes method",
    is_array( $cb ) && 'GHCA_ECD_Shortcodes' === $cb[0] && method_exists( $cb[0], $cb[1] )
  );
}

// Cross-plugin contract: the admin plugin calls these wrappers.
check( 'bust_cache wrapper exists on main class', method_exists( 'GHCA_Employee_Compliance_Dashboard', 'bust_cache' ) );
check( 'bust_all_caches wrapper exists on main class', method_exists( 'GHCA_Employee_Compliance_Dashboard', 'bust_all_caches' ) );

if ( $failures > 0 ) {
  echo "\n{$failures} test(s) failed.\n";
  exit( 1 );
}

echo "\nAll tests passed.\n";
