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

// --- loopback cookies for the certificate fetch (1.6.2) -------------------------
$raw = array(
  'wordpress_logged_in_abc' => 'ruth|1800000000|tok|hash',
  'wordpress_sec_abc'       => 'ruth|1800000000|tok|hash2',
  'PHPSESSID'               => 'sess123',
  'wp-settings'             => array( 'editor' => 'tinymce' ), // `name[key]` cookies arrive as arrays.
  'cf_clearance'            => 'x',
);
$c = GHCA_ECD_Cert_Packet::loopback_cookies( $raw, 'PHPSESSID' );
check( 'login cookies are forwarded', isset( $c['wordpress_logged_in_abc'], $c['wordpress_sec_abc'] ) );
check( 'PHP session cookie is not forwarded (session lock would stall the fetch)', ! isset( $c['PHPSESSID'] ) );
check( 'array cookies are dropped (the HTTP library throws on them)', ! isset( $c['wp-settings'] ) );
check( 'other plain cookies still forwarded (host / proxy cookies)', isset( $c['cf_clearance'] ) );
check( 'every forwarded value is a string', count( $c ) === count( array_filter( $c, 'is_string' ) ) );
check( 'no session name known => nothing extra dropped', isset( GHCA_ECD_Cert_Packet::loopback_cookies( $raw )['PHPSESSID'] ) );

if ( $failures > 0 ) {
  echo "\n{$failures} test(s) failed.\n";
  exit( 1 );
}

echo "\nAll tests passed.\n";
