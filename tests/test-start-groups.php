<?php
// Run: php tests/test-start-groups.php
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

// --- sanitize_start_group_ids -----------------------------------------------
check( 'non-array input returns empty array', GHCA_ECD_Settings::sanitize_start_group_ids( 'junk' ) === array() );
check( 'null input returns empty array (all boxes unchecked)', GHCA_ECD_Settings::sanitize_start_group_ids( null ) === array() );
check( 'string IDs are cast to int', GHCA_ECD_Settings::sanitize_start_group_ids( array( '54', '1428' ) ) === array( 54, 1428 ) );
check( 'duplicates removed', GHCA_ECD_Settings::sanitize_start_group_ids( array( 54, 54, 1428 ) ) === array( 54, 1428 ) );
check( 'zero and negatives dropped', GHCA_ECD_Settings::sanitize_start_group_ids( array( 0, -3, 12 ) ) === array( 12 ) );
check( 'garbage strings dropped', GHCA_ECD_Settings::sanitize_start_group_ids( array( 'abc', '9' ) ) === array( 9 ) );

// --- resolve_start_group_ids: default vs deliberately-cleared ----------------
check(
  'option never saved (false) => historical default [54, 1428]',
  GHCA_ECD_Settings::resolve_start_group_ids( false ) === array( 54, 1428 )
);
check(
  'option saved as empty array => stays empty (user cleared it)',
  GHCA_ECD_Settings::resolve_start_group_ids( array() ) === array()
);
check(
  'option saved with values => those values',
  GHCA_ECD_Settings::resolve_start_group_ids( array( '7', '7', '21' ) ) === array( 7, 21 )
);
check(
  'non-array DB corruption falls back to default',
  GHCA_ECD_Settings::resolve_start_group_ids( 'oops' ) === array( 54, 1428 )
);

if ( $failures > 0 ) {
  echo "\n{$failures} test(s) failed.\n";
  exit( 1 );
}

echo "\nAll tests passed.\n";
