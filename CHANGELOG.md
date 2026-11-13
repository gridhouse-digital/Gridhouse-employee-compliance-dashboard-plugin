# Changelog

All notable changes to the Gridhouse Employee Compliance Dashboard are documented here.

## [1.6.3]

### Fixed

- **Root cause of the "Download all as one PDF" 500:** the merge step called the admin plugin's
  `GHCA_Audit_PDF::append_certificate( $pdf, $path )` with two arguments. Since admin 1.7.3-beta.14 (incl.
  1.7.3-rc.8) it is `append_certificate( Fpdi $pdf, string $path, int &$total_pages )` with a required third
  argument, so every merge threw `ArgumentCountError` (an `\Error`), which nothing caught. The `is_available()`
  check only tested that the method exists, not its signature.
  - The merge now keeps a running page count, starting from the pages after the cover (`$pdf->getPage()`, as the
    admin plugin's own packet builder does), and passes it by reference on every call.
  - Works with both signatures: `ReflectionMethod` reads the parameter count once per request, and the count is
    passed only when the method takes it. With the older two-argument method the count is read from the document.
  - The merge response also returns `pages` (the packet's page count).
- **Init:** `GHCA_Audit_PDF_Jobs::create_job()` can throw `LogicException` (a build is already starting or
  still processing) or `RuntimeException` (job folders can't be created). Init now answers with a JSON error and
  writes `[ghca-ecd] Certificate packet init failed …` to the error log, instead of a 500.
- The 1.6.2 hardening stays (`\Throwable` catch in fetch and merge, raised limits, plain-cookie filter,
  step-named errors in the modal). 1.6.2 would have turned this 500 into "The server stopped while merging",
  but every merge would still have failed.

### Tests

- `tests/test-v163.php` runs the real `ajax_merge()` / `ajax_init()` against a stub `GHCA_Audit_PDF` with the
  3-argument by-reference signature and with the 2-argument one (one PHP process each), checks there is no
  `ArgumentCountError`, the page count (cover 1 + certificates 1 + 2 + 1 = 5), a refused certificate, an
  `\Error` during merge, and both `create_job()` exceptions.

## [1.6.2]

### Fixed

- **"Download all as one PDF" failed with a bare server error (HTTP 500)** and the generic "Packet generation
  failed" message. The packet code is unchanged since 1.4.5; the hardening covers the ways it can crash:
  - Fetching and merging raise PHP's memory and time limits the way WordPress does for image work (where the
    host allows it). Merging several certificates with large background images can pass a host's defaults.
  - Crashes inside a step (TCPDF/FPDI throw `\Error` as well as `\Exception`) now end the job cleanly with a
    readable message, and the reason goes to the PHP error log as `[ghca-ecd] Certificate packet …`.
  - The certificate fetch forwards only plain string cookies and not the PHP session cookie: array cookies made
    the HTTP library throw, and a shared PHP session made the fetch wait on its own session lock.
  - If the server still stops (out of memory or time), the modal names the step and error code ("The server
    stopped while merging your certificates (error 500)…"), and the browser console keeps the server's answer.
- `dashboard.js` is versioned by file date, so browsers pick up the new script straight away.

## [1.6.1]

### Added

- **Profile photo pop-up.** Clicking the welcome avatar opens a pop-up instead of leaving the dashboard: choose a
  photo (or drag one in), position it in the circle, zoom, save. "Remove photo" moved into the pop-up and asks
  first. The dashboard avatar and the BuddyBoss avatars elsewhere on the page (header) update without a reload.
- Saving goes through BuddyBoss's public REST API (`POST` / `DELETE /buddyboss/v1/members/{id}/avatar`) with the
  WordPress REST nonce, so BuddyBoss checks the login and permission, stores the photo with its own upload code
  and fires `xprofile_avatar_uploaded`. The browser sends an already-square JPEG at twice BuddyBoss's full avatar
  size (capped at its original-photo limit; 450 × 450 with BuddyBoss defaults), so BuddyBoss's automatic centre
  crop keeps exactly what the employee saw.
- Photos must be JPG, PNG or WebP, at least BuddyBoss's full avatar size (300 × 300 by default), 20 MB at most.
  Zoom stops before the crop would use fewer pixels than that.
- Clear messages for an expired session, a BuddyBoss upload error and a missing REST API, with a link to the
  BuddyBoss profile page as the fallback.
- New filter `ghca_ecd_photo_popup` (return false to keep the 1.6.0 link to the change-photo screen).

### Unchanged fallback

- Without BuddyBoss's REST API, or before the page script has loaded, the avatar still links to BuddyBoss's
  change-photo screen and the separate "Remove photo" form shows as in 1.6.0. With BuddyBoss photo uploads
  switched off, the avatar is not clickable.

### Fixed

- **Phones:** a long course name on a "What's blocking my packet" button ("Continue Prevention, Detection &
  Reporting of Abuse") made the page wider than the screen (433px on a 375px phone), so every card looked cut
  off on the right. The buttons now wrap inside the card and take the full card width on phones.

## [1.6.0]

Redesign fixes. Design system, fonts and colours unchanged. No change to the admin plugin's schema, roles,
capabilities, rewrite rules or sending logic.

### Changed

- **Legacy section shortcodes** (login gate, notification, welcome, status cards, next required course,
  required actions, announcements, quick links, support, trio) stay registered. With the redesign on, the first
  dashboard shortcode on a page renders the full new dashboard and later ones render nothing, so it appears once.
  Admins see "This page uses a legacy shortcode. Replace it with [employee_compliance_dashboard]."
- **ODP bulletins** on the employee dashboard always use the employee audience (employee or both), for every
  role, including admins viewing the page. Ticker, drawer and its count use the same set.
- **Required actions:** every item can be completed. "Review company policies" is done when the employee
  completes the policies LearnDash course (new setting; falls back to a course titled "Polic…", flagged to
  admins; hidden when none). The policy page is a secondary "Read the policy" link and never counts.
  "Contact admin" is removed (contact stays in the Support card).
- **Per-course due dates:** Next up and Still to earn use each course's own due/expiry date (course lifespans),
  falling back to the cycle or new-hire date. Overdue, Due soon (30 days) and Locked (LearnDash prerequisites
  only) chips.
- **Role badge** shows the job role and never bbPress forum roles (e.g. Keymaster); hidden when there is none.
- **Tablet status strip:** Training status on its own row, the other four below (no orphan cell).
- Course titles keep acronyms in capitals (IDD, ODP, CPR, …).

### Added

- **"What's blocking my packet" card** (below the status strip, above Quick links): plain-language reasons from
  the admin plugin's packet calculation (`GHCA_Audit_Calculator::calculate_employee_audit_data`, read-only):
  orientation not complete, ODP 6100 annual hours short (X of 24), missing required training topics, plus
  expired certificates and completed courses with no certificate. Each links to the course to complete; outside
  training goes through the Jotform step inside the course. "You're all set" when nothing blocks.
- **Announcements empty state:** "No current announcements" with a muted megaphone, same card height.
- **Profile photo:** the welcome avatar shows the BuddyBoss/BuddyPress photo, or initials from the real name
  (never the username). Clicking it opens BuddyBoss's change-avatar screen (with a link back to the dashboard);
  "Remove photo" deletes it through BuddyPress. Uploads started there are limited to JPG, PNG and WebP.
- **ODP ticker on phones (≤719px):** row 1 label pill + pause + All bulletins, row 2 the headline at full
  width; "Saved copy" becomes its clock icon with a label. The scroll speed is recalculated on resize and
  orientation change. Reduced motion: no scrolling, the newest headline truncated, still a link.
- New option `ghca_ecd_policies_course_id` (Settings → Employee Dashboard → Company policies course).

### Fixed

- The certificate-packet count loop ran twice and the snapshot key was set twice.
- Starter announcements are seeded once: `add_option()` claims the seed atomically, so repeated or concurrent
  loads cannot create duplicates.
- The outside-certificate upload teaser (`ghca_employee_outside_certificate_url`) is removed.
- The welcome avatar showed the first letter of the username ("M") instead of the person's initials.

## [1.5.0]

### Added

- **Redesigned employee home and certificates screens (opt-in).** New setting
  "Redesigned screens" (`ghca_ecd_ui_redesign`, off by default) switches
  `[employee_compliance_dashboard]` to the Emp-Home board and the new
  `[employee_certificates]` shortcode to the Emp-Certificates board, on the
  shared gh-* design system used by the admin dashboard. Theme follows the admin
  dashboard unless set here. The WordPress theme keeps the header and navigation.
- `[employee_certificates]`: earned certificates (issued date, expiry, Download
  PDF, View, the packet download) and certificates still to earn.
- ODP bulletin ticker on the redesigned home when the admin plugin provides it.
  Headlines open the **ODP bulletin side drawer** (ODP-Drawer-Employee board):
  the admin plugin's own drawer markup and audience-filtered payload, with
  newer/older stepping, source link, Escape to close and focus return.
- **Certificate viewer** (Modal-CertViewer): "View" on a certificate opens a
  modal with the PDF in the browser's viewer (page, zoom, fit, print, open in
  new tab), certificate details and Download PDF. Phones get a full-screen
  sheet. Same behaviour as the admin dashboard's viewer.

### Changed

- **Release note — new typeface on the redesigned screens:** Wix Madefor
  Display and Text replace Plus Jakarta Sans, self-hosted (no Google Fonts
  request). Employees will notice this more than the colour changes.
- Snapshot courses now carry `completed_ts`; the cache key changes with the
  version, so every snapshot rebuilds once.

### Fixed

- **Announcements respect the admin "Who sees it" setting.** Announcements
  marked "Admins only" are no longer shown to employees, and group
  announcements only to members of that LearnDash group. Announcements saved
  before the setting existed stay visible to everyone. Applies to the previous
  screens as well as the redesign.

## [1.4.4]

### Fixed (architecture-review findings)

- **Cached page loads are query-free again.** The packet button's
  `has_certificates()` check re-ran the LearnDash course loop on every render —
  after v1.4.3 moved the button into the status cards (which are on the live
  dashboard page), this partially undid the v1.2.0 caching win. The snapshot
  now stores `packet_certificate_count` and the button reads it.
- **First-ever save of a setting now busts caches.** WordPress fires
  `add_option_{name}` (not `update_option_{name}`) the first time a
  not-yet-persisted option is saved, so the bust hooks never fired on first
  save. Both hooks are now registered per option — here and in the admin
  plugin, which had the identical latent bug.
- **Cancelling a packet build no longer locks the user out.** The
  one-job-per-user lock used to reject new builds for up to 5 minutes after a
  cancelled modal. `ajax_init` now deletes the abandoned job (which also
  terminates any still-running fetch loop) and starts fresh.
- **Elementor pages containing only `[employee_certificate_packet]`** are now
  detected by the asset-enqueue gate (the substring check matched
  `employee_compliance` only).

### Housekeeping

- Removed dead `resolve_due_date()` (unused since the v1.1 cycle logic).
- Added `uninstall.php` (removes plugin-owned options, snapshot caches, and
  packet locks; leaves the shared due-date option and announcement posts).
- Added `.gitattributes` pinning CRLF (golden-output tests depend on stable
  line endings inside ob_start template blocks).
- `load_plugin_textdomain()` is now called so translations can actually load.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.4.2]

### Fixed — traffic-light dots never rendered on LearnDash profile pages

- Two stacked bugs, present since the feature shipped in v1.1.0:
  1. The Profile page uses the `learndash/ld-profile` **block** (not a
     dashboard shortcode), so `page_uses_dashboard()` was false and
     `dashboard.css` — including all traffic-light rules — was never enqueued
     there at all.
  2. Even where the stylesheet did load, the dot colors referenced custom
     properties defined only on the `.ghca-ecd` scope, and LearnDash profile
     markup is not inside that wrapper — the `var()` lookups resolved to
     nothing and the dots rendered transparent.
- Fix: traffic-light rules moved to a new self-contained
  `assets/traffic-light.css` that defines its tokens on `.ghca-traffic-light`
  itself (values mirror the dashboard palette; the amber stays brand-aware via
  `var(--ghca-accent, #f3ab12)`). It is enqueued on any page using the
  `[ld_profile]` shortcode, the `learndash/ld-profile` block, or an Elementor
  layout containing `ld_profile` — deliberately without the `ghca-ecd-page`
  body class, so the dashboard's page-width overrides do not leak onto
  profile pages.
- The previously unstyled `incomplete` state now gets a neutral grey dot
  (`#9aa3ac`) — with the dot base style finally loading, an uncolored ring
  would have looked broken.
- Verified on the live site: Profile page (ID 32) enqueues only
  traffic-light.css; the Elementor dashboard page (ID 2264) enqueues only
  dashboard.css; all four dot states compute to the exact palette colors
  outside any `.ghca-ecd` wrapper.

## [1.4.1]

### Changed — design-token adoption (zero visual change)

- `assets/dashboard.css` now uses the admin plugin's `--ent-*` design-token
  system. Brand tokens read the branding contract vars with fallbacks
  (`--ent-brand: var(--ghca-primary, #176cad)` …); status/surface/layout
  tokens carry the employee palette's existing values unchanged.
- The six branding-contract names (`--ghca-primary`, `--ghca-primary-dark`,
  `--ghca-primary-soft`, `--ghca-secondary`, `--ghca-accent`, `--ghca-ring`)
  stay raw values — `GHCA_Dashboard_Branding`'s inline CSS overrides them at
  runtime; aliasing them would create circular `var()` references.
- All other old `--ghca-*` names remain defined as aliases of their `--ent-*`
  equivalents (PHP inline styles and the traffic-light rules reference them).
- Verified: computed styles of all 583 rendered dashboard elements × 29 CSS
  properties are identical before/after, both with the live branding inline
  CSS applied and with it absent; branding recolor confirmed to flow through
  the new tokens (button gradient + progress ring).

## [1.4.0]

### Added — Self-service certificate packet

- **"Download my certificate packet"**: a logged-in employee can download a
  single merged PDF containing a summary cover page (course / completed on /
  valid until table, compliance status, cycle due date) followed by every
  certificate they have earned. Button appears in the welcome card and via a
  new `[employee_certificate_packet]` shortcode — both render nothing when no
  certificates exist yet.
- Client-driven async build with a progress modal (`assets/dashboard.js`, the
  plugin's first JavaScript): init → fetch one certificate at a time → merge →
  download, adapted from the admin plugin's packet builder.
- **Reuses the admin plugin's PDF machinery** (`GHCA_Audit_PDF_Jobs` job store
  with hardened upload dirs and GC; TCPDF/FPDI loading and page merging from
  `GHCA_Audit_PDF`) as a soft dependency: when the admin plugin is inactive
  the button, endpoints, and script are simply absent — no fatals.
- **Strictly self-scoped security**: own nonce (`ghca_ecd_pkt`); no user id is
  ever read from the request; jobs are created with owner = target = the
  current user and tagged `tracker: employee`; every phase re-validates
  ownership *and* self-scope (an admin-created audit job cannot be replayed
  through these endpoints, and vice versa). One active build per user
  (5-minute lock) prevents request stacking.
- **ABORT policy inherited from the admin packet**: any failed certificate
  fetch or merge deletes the whole job — a packet is never produced with a
  certificate silently missing.
- Test: `php tests/test-cert-packet-guard.php` (job-id validation, manifest
  owner/expiry, self-scope matrix). Live-verified: bad nonce rejected,
  cross-user job replay rejected, double-click rate-limited, abort cleans the
  job and releases the lock.

## [1.3.1]

### Added

- **Cycle start groups setting** (`ghca_ecd_start_group_ids`). The LearnDash
  group IDs used to detect an employee's platform start date were hardcoded
  (`54`, `1428`); they are now a checkbox list on the settings page. The
  historical IDs remain the default until the option is first saved, so
  existing sites see no behavior change. Clearing every checkbox falls back to
  the account registration date only. Also filterable via
  `ghca_ecd_start_group_ids`. Saving the option busts all snapshot caches.
- Test: `php tests/test-start-groups.php` (sanitizer + default-vs-cleared
  resolution).

### Docs

- README: documented all three settings and the test harness; admin plugin
  README version corrected (was stale at 1.1.0 while the plugin is 1.2.0).

## [1.3.0]

### Changed

- **Architecture split (no behavior change).** The single-file god class was
  decomposed following the admin plugin's Phase-6 recipe:
  - `includes/class-data-provider.php` — `GHCA_ECD_Data_Provider`: snapshot,
    required courses, compliance cycle, notification, new-hire mapping,
    certificate URL lookup, and all caching/bust logic.
  - `includes/class-shortcodes.php` — `GHCA_ECD_Shortcodes`: all 11 shortcode
    renderers, action items, quick links, announcements.
  - `includes/class-settings.php` — `GHCA_ECD_Settings`: settings page,
    sanitizers, option accessors, option-save bust hooks.
  - The main plugin file is now a slim bootstrap (constants, asset enqueue,
    page detection, traffic-light filter) plus `bust_cache`/`bust_all_caches`
    delegating wrappers kept for the admin plugin's cross-plugin calls.
- Verified byte-identical shortcode output before vs after the split
  (sha1-matched golden render of all 11 shortcodes for a real user).
- New smoke test: `php tests/test-split-smoke.php`.

## [1.2.0]

### Performance

- **Per-request memoization.** `get_required_courses()` and
  `get_compliance_notification()` are now memoized per user for the duration of
  the request. Previously the full per-course LearnDash query loop ran 3–4×
  per page load (snapshot build, annual-training check, and the notification
  banner each recomputed it independently).
- **Persistent snapshot cache.** The assembled dashboard snapshot is stored in
  a transient keyed `ghca_ecd_snap_{version}_{user}_{Ymd}` (mirrors the admin
  dashboard's aggregate cache). The date stamp self-invalidates at site-timezone
  midnight so day-based countdowns and traffic lights never go stale overnight.
  Measured locally: 46 queries on a cold render → 0 on a warm render, with
  byte-identical HTML.
- New setting **Dashboard cache lifetime** (`ghca_ecd_cache_ttl`, default 600 s,
  clamp 0–3600, 0 disables caching).
- The notification banner shortcode now reads from the snapshot instead of
  recomputing, so cached page loads issue no LearnDash queries at all.

### Added

- **Cache busting** on `learndash_course_completed`,
  `learndash_update_course_access`, `ld_added_group_access` /
  `ld_removed_group_access`, and on saves of the due-date, cache-TTL,
  course-lifespan, warning-days, and new-hire options.
- Cross-plugin bust: the admin dashboard's Edit Records and Save Employee AJAX
  handlers (which intentionally skip LearnDash completion hooks to avoid
  notification emails) now bust the affected employee's snapshot directly.
- Plain-PHP test harness under `tests/` (`php tests/test-cache-key.php`),
  following the admin plugin's pattern.

## [1.1.0]

### Added — Phase 2: Rolling Expirations & Traffic Light

- **Traffic light on the LearnDash course table.** Hooked `learndash-course-row-class`
  to append a `ghca-traffic-light--{state}` class to each course row. CSS draws a
  colored dot on `.ld-course-title::before`: 🟢 green (`current`/`completed`/`compliant`),
  🟡 amber (`expiring_soon`), 🔴 red (`expired`/`new_hire_overdue`).
- **Rolling-expiration awareness in the KPI status card.** When the Admin Compliance
  Dashboard's `GHCA_Course_Lifespans` class is present, each required course is
  decorated with `compliance_state`, `expiration_ts`, and `expiration_label` via
  `GHCA_Course_Lifespans::decorate()`. The dashboard rolls these up (worst-wins) so
  the status card can show **Expired** or **Expiring Soon**.
- New `expired` / `expiring_soon` labels in `status_label()` and badge colors in
  `get_status_badge_class()`.
- Status-card footer copy for the `expired` and `expiring_soon` states.
- Status-card tile now changes tone and icon to match the state — red alert for
  expired/overdue, amber clock for expiring soon.
- `.ghca-ecd__pill--warning` and `.ghca-ecd__tile--danger` styles.

### Fixed

- **"In Progress" detection regression.** `get_required_courses()` was overwriting
  the LearnDash `status` field with `compliance_state` (which collapses to
  `incomplete` for all unfinished courses). This broke started-vs-not-started
  detection, the "next course" preference, and the "Resume Course" button label.
  The LearnDash status is now preserved; compliance state lives in its own
  `compliance_state` key.
- The **Expiring Soon** KPI pill rendered unstyled because `.ghca-ecd__pill--warning`
  was referenced in PHP but never defined in CSS.

### Notes

- Rolling-expiration behavior degrades gracefully: with `GHCA_Course_Lifespans`
  inactive, the dashboard falls back to plain complete / in-progress / not-started
  status and the traffic-light filter is a no-op.

## [1.0.0]

### Added

- Initial release: employee compliance home dashboard shortcodes for LearnDash +
  Elementor (welcome, KPI status cards, next required course, required actions,
  announcements, quick links, support), new-hire onboarding windows, and annual
  compliance cycle countdown.
