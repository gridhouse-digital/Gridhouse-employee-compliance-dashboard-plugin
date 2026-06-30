<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

if ( ! class_exists( 'GHCA_ECD_UI_Icons', false ) ) {

final class GHCA_ECD_UI_Icons {
  /** @return array<string,string> */
  private static function paths(): array {
    return array(
      'status'       => '<path d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" fill="none"/>',
      'courses'      => '<path d="M4.5 6.75A2.25 2.25 0 0 1 6.75 4.5h10.5A2.25 2.25 0 0 1 19.5 6.75v10.5A2.25 2.25 0 0 1 17.25 19.5H6.75A2.25 2.25 0 0 1 4.5 17.25V6.75Z" stroke="currentColor" stroke-width="1.75" fill="none"/><path d="M8.25 8.25h7.5M8.25 12h7.5M8.25 15.75H12" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" fill="none"/>',
      'calendar'     => '<path d="M6.75 3v2.25M17.25 3v2.25M3 8.25h18M5.25 5.25h13.5c1 0 1.5.5 1.5 1.5v12c0 1-.5 1.5-1.5 1.5H5.25c-1 0-1.5-.5-1.5-1.5v-12c0-1 .5-1.5 1.5-1.5Z" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" fill="none"/>',
      'due'          => '<path d="M12 6v6l4 2" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" fill="none"/><path d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" stroke="currentColor" stroke-width="1.75" fill="none"/>',
      'certificate'  => '<path d="M9 12.75 11.25 15 15 9.75" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" fill="none"/><path d="M7.5 19.5 9 17.25l1.5 2.25L12 17.25l1.5 2.25L15 17.25l1.5 2.25" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/><path d="M5.25 5.25h13.5v9H5.25v-9Z" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round" fill="none"/>',
      'handbook'     => '<path d="M12 6.75v14.25M5.25 5.25h11.25c1 0 1.5.5 1.5 1.5v12.75c0 .6-.7.95-1.2.6L12 17.25l-4.55 3.1c-.5.35-1.2 0-1.2-.6V6.75c0-1 .5-1.5 1.5-1.5Z" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" fill="none"/>',
      'policies'     => '<path d="M8.25 4.5h7.5l3 3v12.75c0 1-.5 1.5-1.5 1.5h-9c-1 0-1.5-.5-1.5-1.5V6c0-1 .5-1.5 1.5-1.5Z" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round" fill="none"/><path d="M15.75 4.5V9H19.5" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round" fill="none"/><path d="M9 13.5h6M9 16.5h4.5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" fill="none"/>',
      'support'      => '<path d="M8.25 9.75h7.5M8.25 12.75h4.5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" fill="none"/><path d="M6.75 4.5h10.5c1 0 1.5.5 1.5 1.5v8.25c0 1-.5 1.5-1.5 1.5H12l-3 3v-3H6.75c-1 0-1.5-.5-1.5-1.5V6c0-1 .5-1.5 1.5-1.5Z" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round" fill="none"/>',
      'download'     => '<path d="M12 4.5v9" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" fill="none"/><path d="M8.25 11.25 12 15l3.75-3.75" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" fill="none"/><path d="M5.25 19.5h13.5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" fill="none"/>',
      'compliance'   => '<path d="M4.5 16.5v-6l7.5-4.5 7.5 4.5v6" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round" fill="none"/><path d="M9 19.5V12l3-1.8 3 1.8v7.5" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round" fill="none"/>',
      'users'        => '<path d="M15 19.5a4.5 4.5 0 1 0-6 0" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" fill="none"/><path d="M9 10.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm7.5 1.5a2.25 2.25 0 1 0 0-4.5 2.25 2.25 0 0 0 0 4.5Z" stroke="currentColor" stroke-width="1.75" fill="none"/><path d="M18.75 19.5a3.75 3.75 0 0 0-7.5 0" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" fill="none"/>',
      'progress'     => '<path d="M4.5 12a7.5 7.5 0 0 1 13.35-4.72" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" fill="none"/><path d="M19.5 12a7.5 7.5 0 0 1-13.35 4.72" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" fill="none"/><path d="M19.5 6V3h-3" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" fill="none"/>',
      'alert'        => '<path d="M12 9v4.5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" fill="none"/><path d="M12 17.25h.007" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" fill="none"/><path d="M10.29 4.86 2.82 17.25A1.5 1.5 0 0 0 4.11 19.5h15.78a1.5 1.5 0 0 0 1.29-2.25L13.71 4.86a1.5 1.5 0 0 0-2.58 0Z" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round" fill="none"/>',
      'groups'       => '<path d="M7.5 10.5a2.25 2.25 0 1 0 0-4.5 2.25 2.25 0 0 0 0 4.5Zm9 0a2.25 2.25 0 1 0 0-4.5 2.25 2.25 0 0 0 0 4.5ZM3 18.75a4.5 4.5 0 0 1 9 0M12 18.75a4.5 4.5 0 0 1 9 0" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" fill="none"/>',
      'reports'      => '<path d="M6 19.5V10.5M10.5 19.5V6M15 19.5v-7.5M19.5 19.5V4.5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" fill="none"/>',
      'dashboard'    => '<path d="M4.5 9.75h6v9.75h-6v-9.75Zm9 0h6v5.25h-6V9.75Zm0 7.5h6v2.25h-6v-2.25Z" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round" fill="none"/>',
      'flag'         => '<path d="M5 3.25v17.5M5 4.25h13l-2.2 3.4L18 11H5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" fill="none"/>',
      'award'        => '<circle cx="12" cy="9" r="6" stroke="currentColor" stroke-width="1.75" fill="none"/><path d="M8.4 14 7 21.5l5-2.9 5 2.9L15.6 14" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" fill="none"/>',
      'clock'        => '<circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.75" fill="none"/><path d="M12 7.5v5l3 1.8" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" fill="none"/>',
      'bell'         => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9Z" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round" fill="none"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" fill="none"/>',
      'lifebuoy'     => '<circle cx="12" cy="12" r="9.5" stroke="currentColor" stroke-width="1.75" fill="none"/><circle cx="12" cy="12" r="3.8" stroke="currentColor" stroke-width="1.75" fill="none"/><path d="m5.3 5.3 3.9 3.9M14.8 14.8l3.9 3.9M14.8 9.2l3.9-3.9M9.2 14.8l-3.9 3.9" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" fill="none"/>',
      'mail'         => '<rect x="3" y="5.25" width="18" height="13.5" rx="2" stroke="currentColor" stroke-width="1.75" fill="none"/><path d="m3.5 7 8.5 5 8.5-5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" fill="none"/>',
      'arrow_right'  => '<path d="M5 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>',
      'chevron_right'=> '<path d="m9 6 6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>',
      'user'         => '<circle cx="12" cy="8.5" r="4" stroke="currentColor" stroke-width="1.75" fill="none"/><path d="M5 20a7 7 0 0 1 14 0" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" fill="none"/>',
      'document'     => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8Z" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round" fill="none"/><path d="M14 3v5h5M9 13h6M9 16.5h4" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" fill="none"/>',
    );
  }

  public static function render( string $name, string $class = '' ): string {
    $paths = self::paths();
    if ( ! isset( $paths[ $name ] ) ) {
      return '';
    }

    $class_attr = $class !== '' ? ' class="' . esc_attr( $class ) . '"' : '';

    return '<svg' . $class_attr . ' xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
  }
}

}
