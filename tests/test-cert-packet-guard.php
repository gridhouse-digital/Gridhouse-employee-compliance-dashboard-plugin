<?php
// Run: php tests/test-cert-packet-guard.php
// Pure security-guard tests for the self-service certificate packet:
// the admin job store's manifest validation + the employee self-scope check.
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../gridhouse-admin-compliance-dashboard/includes/class-audit-pdf-jobs.php';

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

$now = 1_800_000_000;

// --- job id validation (path-traversal surface) -------------------------------
check( 'valid 32-hex job id accepted', GHCA_Audit_PDF_Jobs::is_valid_job_id( str_repeat( 'a1', 16 ) ) );
check( 'short id rejected', ! GHCA_Audit_PDF_Jobs::is_valid_job_id( 'abc123' ) );
check( 'uppercase hex rejected', ! GHCA_Audit_PDF_Jobs::is_valid_job_id( strtoupper( str_repeat( 'a1', 16 ) ) ) );
check( 'path traversal rejected', ! GHCA_Audit_PDF_Jobs::is_valid_job_id( '../../etc/passwd0000000000000000' ) );

// --- manifest validation (owner + expiry) --------------------------------------
$manifest = array( 'owner' => 7, 'user_id' => 7, 'tracker' => 'employee', 'created' => $now - 60 );
check( 'own fresh manifest validates', true === GHCA_Audit_PDF_Jobs::validate_manifest( $manifest, 7, $now ) );
check( 'other user\'s manifest -> owner_mismatch', 'owner_mismatch' === GHCA_Audit_PDF_Jobs::validate_manifest( $manifest, 8, $now ) );
check( 'expired manifest -> expired', 'expired' === GHCA_Audit_PDF_Jobs::validate_manifest( array( 'owner' => 7, 'created' => $now - HOUR_IN_SECONDS - 1 ), 7, $now ) );
check( 'missing manifest -> not_found', 'not_found' === GHCA_Audit_PDF_Jobs::validate_manifest( false, 7, $now ) );

// --- employee self-scope (defense in depth) ------------------------------------
check(
  'self-scoped employee manifest accepted',
  GHCA_ECD_Cert_Packet::manifest_is_self_scoped( array( 'owner' => 7, 'user_id' => 7, 'tracker' => 'employee' ), 7 )
);
check(
  'admin-created audit job rejected even when targeting self (tracker mismatch)',
  ! GHCA_ECD_Cert_Packet::manifest_is_self_scoped( array( 'owner' => 7, 'user_id' => 7, 'tracker' => 'annual' ), 7 )
);
check(
  'job targeting another user rejected',
  ! GHCA_ECD_Cert_Packet::manifest_is_self_scoped( array( 'owner' => 7, 'user_id' => 9, 'tracker' => 'employee' ), 7 )
);
check(
  'job owned by someone else rejected',
  ! GHCA_ECD_Cert_Packet::manifest_is_self_scoped( array( 'owner' => 9, 'user_id' => 7, 'tracker' => 'employee' ), 7 )
);
check(
  'anonymous user (id 0) always rejected',
  ! GHCA_ECD_Cert_Packet::manifest_is_self_scoped( array( 'owner' => 0, 'user_id' => 0, 'tracker' => 'employee' ), 0 )
);
check(
  'empty manifest rejected',
  ! GHCA_ECD_Cert_Packet::manifest_is_self_scoped( array(), 7 )
);

if ( $failures > 0 ) {
  echo "\n{$failures} test(s) failed.\n";
  exit( 1 );
}

echo "\nAll tests passed.\n";
