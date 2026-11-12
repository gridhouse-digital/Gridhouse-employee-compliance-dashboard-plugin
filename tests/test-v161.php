<?php
// Run: php tests/test-v161.php
// 1.6.1: profile photo pop-up that saves through BuddyBoss's REST API.

if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return esc_html( $s ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $s, $d = null ) { return esc_attr( $s ); } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $tag, $value, ...$a ) { return $value; } }
function get_current_user_id() { return 7; }
function wp_create_nonce( $a ) { return 'wp_rest' === $a ? 'restnonce' : 'other'; }
function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . $p; }
function get_permalink( $p = null ) { return 'https://example.test/employee-dashboard/'; }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }

require_once __DIR__ . '/bootstrap.php';

$failures = 0;
function check( string $label, bool $ok ): void {
  global $failures;
  echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n";
  if ( ! $ok ) { ++$failures; }
}

// --- Size of the photo the pop-up sends -------------------------------------------------------------------
check( 'BuddyBoss defaults (300 full, 450 original max) => 450', 450 === GHCA_ECD_Redesign::photo_export_size( 300, 300, 450 ) );
check( 'BuddyPress defaults (150 full, 450 max) => 300 (twice the full size)', 300 === GHCA_ECD_Redesign::photo_export_size( 150, 150, 450 ) );
check( 'never below the full size, even when the max is smaller', 500 === GHCA_ECD_Redesign::photo_export_size( 500, 500, 450 ) );
check( 'non-square full size => the larger side', 400 === GHCA_ECD_Redesign::photo_export_size( 200, 180, 0 ) );

// --- When the pop-up is offered -----------------------------------------------------------------------------
check( 'no BuddyBoss / BuddyPress => no pop-up (falls back to the link)', array() === GHCA_ECD_Redesign::photo_config( 7 ) );

$uploads_off = false;
$has_photo   = false;
// Inside blocks so PHP defines them here, not when it compiles the file ("not installed" must be checked first).
if ( true ) {
  function bp_core_avatar_full_width() { return 300; }
  function bp_core_avatar_full_height() { return 300; }
  function bp_core_avatar_original_max_width() { return 450; }
  function bp_disable_avatar_uploads() { global $uploads_off; return $uploads_off; }
  function bp_get_user_has_avatar( $id ) { global $has_photo; return $has_photo; }
  function bp_core_fetch_avatar( $a ) { return 'https://example.test/avatars/7/abc-bpfull.jpg'; }
  function bp_members_get_user_url( $id ) { return 'https://example.test/members/ade/'; }
}

check( 'BuddyBoss without its REST API => no pop-up', array() === GHCA_ECD_Redesign::photo_config( 7 ) );

if ( true ) {
  final class BP_REST_Attachments_Member_Avatar_Endpoint {}
}

$cfg = GHCA_ECD_Redesign::photo_config( 7 );
check( 'BuddyBoss REST API present => pop-up offered', ! empty( $cfg ) );
check( 'REST address is the member avatar endpoint for this user', 'https://example.test/wp-json/buddyboss/v1/members/7/avatar' === $cfg['rest'] );
check( 'uses the WordPress REST nonce (cookie auth)', 'restnonce' === $cfg['nonce'] );
check( 'minimum photo size = BuddyBoss full avatar size', 300 === $cfg['min'] );
check( 'sends 450 x 450', 450 === $cfg['size'] );
check( 'no uploaded photo => has_photo false, no photo URL', false === $cfg['has_photo'] && '' === $cfg['photo'] );
check( 'fallback is the BuddyBoss change-photo screen', 0 === strpos( $cfg['fallback'], 'https://example.test/members/ade/profile/change-avatar/' ) );
check( 'only for your own account (another user id => no pop-up)', array() === GHCA_ECD_Redesign::photo_config( 8 ) );
check( 'no user => no pop-up', array() === GHCA_ECD_Redesign::photo_config( 0 ) );
$uploads_off = true;
check( 'BuddyBoss uploads switched off => no pop-up', array() === GHCA_ECD_Redesign::photo_config( 7 ) );
$uploads_off = false;
$has_photo   = true;
$cfg         = GHCA_ECD_Redesign::photo_config( 7 );
check( 'uploaded photo => has_photo true with its URL', true === $cfg['has_photo'] && '' !== $cfg['photo'] );

// --- Pop-up markup -------------------------------------------------------------------------------------------
$cfg['initials'] = 'A"O';
$html = GHCA_ECD_Redesign::photo_dialog_html( $cfg );
check( 'dialog is labelled and modal', false !== strpos( $html, 'role="dialog" aria-modal="true" aria-labelledby="ghca-ecd-ph-t"' ) );
check( 'starts hidden', false !== strpos( $html, 'id="ghca-ecd-photo" hidden aria-hidden="true"' ) );
check( 'carries the REST address, nonce and sizes', false !== strpos( $html, 'data-rest="https://example.test/wp-json/buddyboss/v1/members/7/avatar"' ) && false !== strpos( $html, 'data-nonce="restnonce"' ) && false !== strpos( $html, 'data-min="300"' ) && false !== strpos( $html, 'data-size="450"' ) );
check( 'initials are escaped', false !== strpos( $html, 'data-initials="A&quot;O"' ) && false === strpos( $html, 'data-initials="A"O"' ) );
check( 'file input accepts JPG, PNG and WebP only', false !== strpos( $html, 'accept="image/jpeg,image/png,image/webp"' ) );
check( 'states the size rule', false !== strpos( $html, 'at least 300 × 300 pixels' ) );
check( 'Remove photo shown when there is a photo', 1 === preg_match( '/<button[^>]*data-ghca-ph-remove>/', $html ) && 0 === preg_match( '/<button[^>]*hidden data-ghca-ph-remove>/', $html ) );
check( 'remove asks before removing', false !== strpos( $html, 'Remove your photo? Your initials will show instead.' ) && false !== strpos( $html, 'data-ghca-ph-confirm' ) );
check( 'fallback link to the profile page is offered for errors', false !== strpos( $html, 'data-ghca-ph-alt' ) );
$cfg['has_photo'] = false;
$cfg['photo']     = '';
$html = GHCA_ECD_Redesign::photo_dialog_html( $cfg );
check( 'Remove photo hidden without a photo', 1 === preg_match( '/<button[^>]*hidden data-ghca-ph-remove>/', $html ) );
check( 'current photo shows the initials when there is none', false !== strpos( $html, 'data-ghca-ph-current>A&quot;O</span>' ) );

// --- Whole page (preview harness): avatar opens the pop-up; the meta-row remove form is gone -------------------
$php  = escapeshellarg( PHP_BINARY );
$page = static function ( array $get ) use ( $php ): string {
  // A small script file, not `php -r`: Windows shell quoting would mangle the code's quotes.
  $file = tempnam( sys_get_temp_dir(), 'ghca' ) . '.php';
  file_put_contents( $file, '<?php $_GET = ' . var_export( $get, true ) . '; $_SERVER[\'REQUEST_METHOD\'] = \'GET\'; include ' . var_export( __DIR__ . '/render-redesign-preview.php', true ) . ';' );
  $out = (string) shell_exec( $php . ' ' . escapeshellarg( $file ) . ' 2>&1' );
  unlink( $file );
  return $out;
};
$h = $page( array( 'photo' => '1' ) );
check( 'page: avatar opens the pop-up and keeps the change-photo link', 1 === preg_match( '/<a class="gh-avatar[^"]*gh-ecd-avatar has-photo" href="[^"]*change-avatar[^"]*"[^>]*data-ghca-ph-open>/', $h ) );
check( 'page: pop-up rendered once', 1 === substr_count( $h, 'id="ghca-ecd-photo"' ) );
check( 'page: no separate "Remove photo" form beside the welcome', false === strpos( $h, 'gh-ecd-rmphoto' ) );
check( 'page: status message area for screen readers', false !== strpos( $h, 'data-ghca-ph-live' ) );
$h = $page( array( 'photo' => '1', 'pop' => '0' ) );
check( 'page without the REST API: plain link + Remove form as in 1.6.0', false === strpos( $h, 'data-ghca-ph-open' ) && false !== strpos( $h, 'gh-ecd-rmphoto' ) && false === strpos( $h, 'id="ghca-ecd-photo"' ) );
$h = $page( array( 'photo' => '0', 'upl' => '0' ) );
check( 'page with uploads switched off: avatar is not a link or button', false === strpos( $h, 'data-ghca-ph-open' ) && false === strpos( $h, 'change-avatar' ) && 1 === preg_match( '/<span class="gh-avatar[^"]*gh-ecd-avatar" aria-hidden="true">AO</', $h ) );

echo "\n" . ( $failures ? $failures . ' failure(s).' : 'All tests passed.' ) . "\n";
exit( $failures ? 1 : 0 );
