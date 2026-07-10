<?php
// Run: php tests/test-cache-key.php
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

// --- build_cache_key format ------------------------------------------------
check(
  'key format is ghca_ecd_snap_{version}_{user}_{ymd}',
  GHCA_Employee_Compliance_Dashboard::build_cache_key( '1.2.0', 42, '20260710' ) === 'ghca_ecd_snap_1.2.0_42_20260710'
);

check(
  'different users get different keys',
  GHCA_Employee_Compliance_Dashboard::build_cache_key( '1.2.0', 1, '20260710' )
    !== GHCA_Employee_Compliance_Dashboard::build_cache_key( '1.2.0', 2, '20260710' )
);

check(
  'different days get different keys (midnight rollover)',
  GHCA_Employee_Compliance_Dashboard::build_cache_key( '1.2.0', 1, '20260710' )
    !== GHCA_Employee_Compliance_Dashboard::build_cache_key( '1.2.0', 1, '20260711' )
);

check(
  'different plugin versions get different keys (upgrade self-bust)',
  GHCA_Employee_Compliance_Dashboard::build_cache_key( '1.2.0', 1, '20260710' )
    !== GHCA_Employee_Compliance_Dashboard::build_cache_key( '1.3.0', 1, '20260710' )
);

check(
  'current VERSION constant produces the expected prefix',
  strpos(
    GHCA_Employee_Compliance_Dashboard::build_cache_key( GHCA_Employee_Compliance_Dashboard::VERSION, 7, '20260101' ),
    'ghca_ecd_snap_' . GHCA_Employee_Compliance_Dashboard::VERSION . '_'
  ) === 0
);

// --- sanitize_cache_ttl clamping --------------------------------------------
check( 'TTL negative clamps to 0', GHCA_Employee_Compliance_Dashboard::sanitize_cache_ttl( -5 ) === 0 );
check( 'TTL 0 stays 0 (caching disabled)', GHCA_Employee_Compliance_Dashboard::sanitize_cache_ttl( 0 ) === 0 );
check( 'TTL numeric string is cast', GHCA_Employee_Compliance_Dashboard::sanitize_cache_ttl( '250' ) === 250 );
check( 'TTL above max clamps to 3600', GHCA_Employee_Compliance_Dashboard::sanitize_cache_ttl( 99999 ) === 3600 );
check( 'TTL garbage clamps to 0', GHCA_Employee_Compliance_Dashboard::sanitize_cache_ttl( 'abc' ) === 0 );

if ( $failures > 0 ) {
  echo "\n{$failures} test(s) failed.\n";
  exit( 1 );
}

echo "\nAll tests passed.\n";
