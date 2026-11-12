# Gridhouse Employee Compliance Dashboard

Employee-facing home dashboard for LearnDash compliance training, built for Elementor.

## URL

`/employee-dashboard/`

## Shortcodes

| Shortcode | Purpose |
|---|---|
| `[employee_compliance_dashboard]` | Full dashboard (gate + notification + welcome + cards + trio + links + support) |
| `[employee_compliance_login_gate]` | Access gate for logged-out visitors |
| `[employee_compliance_notification]` | Compliance / new-hire countdown banner |
| `[employee_compliance_welcome]` | Welcome header with progress ring |
| `[employee_compliance_status_cards]` | KPI cards (status, progress, dates, certificate) |
| `[employee_next_required_course]` | Featured "next up" course hero |
| `[employee_required_actions]` | Compliance checklist |
| `[employee_compliance_announcements]` | Announcements (from `ghca-announce` posts) |
| `[employee_compliance_trio]` | Next course + actions + announcements row |
| `[employee_compliance_quick_links]` | Quick link cards |
| `[employee_compliance_support]` | Support contact box |
| `[employee_certificate_packet]` | "Download my certificate packet" card (requires the admin plugin; hidden until the first certificate is earned) |
| `[employee_certificates]` | My certificates page: earned certificates and those still to earn (redesigned screen; the packet card when the redesign is off). Put it on `/cert-download/`. |

## Redesigned screens (opt-in, v1.5.0)

Settings → Employee Dashboard → **Redesigned screens** switches
`[employee_compliance_dashboard]` and `[employee_certificates]` to the
"Gridhouse Compliance — Phase 2 Dashboards" boards (Emp-Home,
Emp-Certificates and their phone versions). Off by default; uncheck to roll
back. `[employee_compliance_dashboard]` is the one standard shortcode. The
older section shortcodes stay registered and, with the redesign on, show the
full new dashboard once per page; admins see a note asking to replace them.

- **Header and navigation** come from the WordPress theme. The boards' app bar
  and phone tab bar are not rendered. Handbook and Policies stay WordPress
  pages (quick links point to them through the existing filters).
- **Width** matches the admin compliance dashboard: full-bleed, content up to
  1480px. `ghca_ecd_ui_full_bleed` returning false keeps it inside the theme column.
- **Theme**: Gridhouse, Akansoh or Purple, or "Same as the admin dashboard"
  (default), which follows the admin plugin's redesign theme. Agency colours
  from the admin Branding settings are applied through the admin plugin's own
  helper, so both dashboards match.
- **Fonts** change from Plus Jakarta Sans to Wix Madefor Display and Text,
  self-hosted (SIL OFL 1.1, `assets/fonts/wix-madefor/OFL.txt`).
- **ODP bulletin ticker** shows when the admin plugin provides
  `GHCA_ODP_Bulletins`; headlines open the side drawer. It always uses the
  employee audience, for every role. This plugin registers no bulletin routes.
- **What's blocking my packet** reads the admin plugin's packet calculation
  (`GHCA_Audit_Calculator`, read-only). Nothing is written from this card.
- **Required actions** are all completable. Choose the company policies course
  in Settings → Employee Dashboard (`ghca_ecd_policies_course_id`).
- **Profile photo** is the BuddyBoss avatar. Clicking it opens a pop-up (choose,
  crop to a circle, save or remove) that saves through BuddyBoss's REST API
  (`/buddyboss/v1/members/{id}/avatar`). Without that API the avatar links to
  BuddyBoss's change-avatar screen instead (`ghca_ecd_photo_popup` filter turns
  the pop-up off). Photos never go into certificates or packets.
- **CSS** is built, not hand-edited: `sh design-references/build-ecd-redesign.sh`
  scopes the canvas CSS under `.ghca-ecd-ui` and writes `assets/ecd-redesign.css`.
- **Preview without WordPress**: `php -S 127.0.0.1:8765 -t .` then open
  `/tests/render-redesign-preview.php` (switches for every test case are listed at the top of that file;
  `tests/frame.html?w=390&q=…` shows it at an exact width).

Filters: `ghca_ecd_ui_redesign`, `ghca_ecd_ui_theme`, `ghca_ecd_ui_full_bleed`,
`ghca_employee_role_label` (the role badge under the welcome; defaults to the employee's job role,
skipping generic and bbPress forum roles), `ghca_employee_certificates_url`, `ghca_ecd_title_acronyms`.

## Access

Any logged-in user can view the dashboard. Course data is scoped to the current user's LearnDash enrollments.

## Settings

WP Admin → Settings → Employee Dashboard

- **Compliance due date** — fallback annual due date (`Y-m-d`), used when no
  cycle can be computed from the employee's start date.
- **Cycle start groups** — LearnDash groups whose enrollment stamps
  (`group_{id}_access_from` / `learndash_group_{id}_enrolled_at`) seed the
  annual compliance cycle start. The earliest of these stamps and the account
  registration date wins. Defaults to the historical groups `#54` and `#1428`
  until first saved; clearing all checkboxes falls back to registration date
  only. Filterable via `ghca_ecd_start_group_ids`.
- **Dashboard cache lifetime** — how long each employee's dashboard snapshot
  is cached (seconds, default 600, 0 disables). The cache also self-refreshes
  at site-timezone midnight and busts automatically on course completion,
  enrollment changes, and relevant option saves.

## Tests

Plain-PHP test scripts (no WordPress needed):

```
php tests/test-cache-key.php
php tests/test-split-smoke.php
php tests/test-start-groups.php
php tests/test-cert-packet-guard.php
```

## Training status & rolling expirations

The KPI status card reflects a single rolled-up training state, using **worst-wins** priority:

| State | Pill / tile | Meaning |
|---|---|---|
| Expired | red | A required course's lifespan has lapsed (or new-hire window overdue) |
| Expiring Soon | amber | A completed course is inside its renewal warning window |
| In Progress | blue | Some required training started but not finished |
| Not Started | neutral | No required training started yet |
| Compliant | green | All required training complete and current |

Rolling-expiration logic (course lifespans + warning window) is owned by the
**Admin Compliance Dashboard** plugin's `GHCA_Course_Lifespans` class. When that
plugin is active, this dashboard reads each course's `compliance_state` via
`GHCA_Course_Lifespans::decorate()` and rolls the states up for the KPI card. If
the admin plugin is inactive, the dashboard falls back to plain
complete/in-progress/not-started status.

## Traffic light (LearnDash course table)

A colored dot is injected next to each course title in the LearnDash profile
table via the `learndash-course-row-class` filter. The filter appends a
`ghca-traffic-light--{state}` class to the course row; the self-contained
`assets/traffic-light.css` (enqueued on any page using the `[ld_profile]`
shortcode, the `learndash/ld-profile` block, or an Elementor layout containing
`ld_profile`) draws the dot on `.ld-course-title::before`:

- 🟢 green — `current` / `completed` / `compliant`
- 🟡 amber — `expiring_soon` (follows the branding accent color when set)
- 🔴 red — `expired` / `new_hire_overdue`
- ⚪ neutral grey — `incomplete` (not started / in progress)

## Integrations

- **LearnDash** — enrolled courses, progress, completion, certificates
- **Admin Compliance Dashboard** — `GHCA_Course_Lifespans` (rolling expirations), `GHCA_Compliance_Program` (new-hire windows), `GHCA_Dashboard_Branding` (theme colors / support email), `GHCA_Audit_PDF` + `GHCA_Audit_PDF_Jobs` (certificate packet builder)
- **Elementor** — page layout via shortcode widgets

## Filters

- `ghca_compliance_due_date` — annual compliance due date
- `ghca_ecd_start_group_ids` — LearnDash group IDs used for cycle-start detection
- `ghca_employee_quick_links` — quick link cards
- `ghca_employee_announcements_items` — announcement items
- `ghca_employee_handbook_url` — handbook quick-link target
- `ghca_employee_policies_url` — policies quick-link target
- `ghca_employee_support_email` — support email (when the branding class is absent)
