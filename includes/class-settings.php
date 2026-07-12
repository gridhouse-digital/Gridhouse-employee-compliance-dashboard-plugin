<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Settings page and option accessors for the employee dashboard.
 */
final class GHCA_ECD_Settings {

  public static function init(): void {
    add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
    add_action( 'admin_menu', array( __CLASS__, 'register_settings_page' ) );

    // Option-level changes affect every user's snapshot.
    add_action( 'update_option_' . GHCA_Employee_Compliance_Dashboard::OPTION_DUE_DATE, array( 'GHCA_ECD_Data_Provider', 'bust_all_caches' ) );
    add_action( 'update_option_' . GHCA_Employee_Compliance_Dashboard::OPTION_CACHE_TTL, array( 'GHCA_ECD_Data_Provider', 'bust_all_caches' ) );

    if ( class_exists( 'GHCA_Course_Lifespans' ) ) {
      add_action( 'update_option_' . GHCA_Course_Lifespans::OPTION_LIFESPANS, array( 'GHCA_ECD_Data_Provider', 'bust_all_caches' ) );
      add_action( 'update_option_' . GHCA_Course_Lifespans::OPTION_WARNING_DAYS, array( 'GHCA_ECD_Data_Provider', 'bust_all_caches' ) );
    }
    if ( class_exists( 'GHCA_Compliance_Program' ) ) {
      add_action( 'update_option_' . GHCA_Compliance_Program::OPTION_NEW_HIRE_GROUPS, array( 'GHCA_ECD_Data_Provider', 'bust_all_caches' ) );
      add_action( 'update_option_' . GHCA_Compliance_Program::OPTION_NEW_HIRE_DAYS, array( 'GHCA_ECD_Data_Provider', 'bust_all_caches' ) );
    }
  }

  public static function register_settings(): void {
    register_setting(
      'ghca_employee_dashboard',
      GHCA_Employee_Compliance_Dashboard::OPTION_DUE_DATE,
      array(
        'type'              => 'string',
        'sanitize_callback' => array( __CLASS__, 'sanitize_due_date' ),
        'default'           => '2026-07-31',
      )
    );

    register_setting(
      'ghca_employee_dashboard',
      GHCA_Employee_Compliance_Dashboard::OPTION_CACHE_TTL,
      array(
        'type'              => 'integer',
        'sanitize_callback' => array( __CLASS__, 'sanitize_cache_ttl' ),
        'default'           => 600,
      )
    );
  }

  public static function sanitize_due_date( string $value ): string {
    $value = trim( $value );
    if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
      return $value;
    }
    return '2026-07-31';
  }

  /** @param mixed $value */
  public static function sanitize_cache_ttl( $value ): int {
    return max( 0, min( 3600, (int) $value ) );
  }

  public static function get_cache_ttl(): int {
    $ttl = (int) get_option( GHCA_Employee_Compliance_Dashboard::OPTION_CACHE_TTL, 600 );
    return max( 0, min( 3600, $ttl ) );
  }

  public static function register_settings_page(): void {
    add_options_page(
      'Employee Compliance Dashboard',
      'Employee Dashboard',
      'manage_options',
      'ghca-employee-dashboard',
      array( __CLASS__, 'render_settings_page' )
    );
  }

  public static function render_settings_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
      return;
    }

    $due = get_option( GHCA_Employee_Compliance_Dashboard::OPTION_DUE_DATE, '2026-07-31' );
    ?>
    <div class="wrap">
      <h1><?php esc_html_e( 'Employee Compliance Dashboard', 'ghca-ecd' ); ?></h1>
      <form method="post" action="options.php">
        <?php settings_fields( 'ghca_employee_dashboard' ); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="ghca_compliance_due_date"><?php esc_html_e( 'Compliance due date', 'ghca-ecd' ); ?></label></th>
            <td><input type="date" id="ghca_compliance_due_date" name="<?php echo esc_attr( GHCA_Employee_Compliance_Dashboard::OPTION_DUE_DATE ); ?>" value="<?php echo esc_attr( $due ); ?>" class="regular-text" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="ghca_ecd_cache_ttl"><?php esc_html_e( 'Dashboard cache lifetime (seconds)', 'ghca-ecd' ); ?></label></th>
            <td>
              <input type="number" id="ghca_ecd_cache_ttl" name="<?php echo esc_attr( GHCA_Employee_Compliance_Dashboard::OPTION_CACHE_TTL ); ?>" value="<?php echo esc_attr( (string) self::get_cache_ttl() ); ?>" min="0" max="3600" step="1" class="small-text" />
              <p class="description"><?php esc_html_e( 'How long each employee\'s dashboard snapshot is cached (0 disables caching, max 3600). The cache also refreshes automatically at midnight and whenever training data changes.', 'ghca-ecd' ); ?></p>
            </td>
          </tr>
        </table>
        <?php submit_button(); ?>
      </form>
    </div>
    <?php
  }
}
