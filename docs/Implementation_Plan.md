# WP Admin Settings + Effective Config

## Summary
Build a narrow WordPress admin settings surface for high-value operational eForms configuration, plus a read-only effective-configuration/status view. This is a Full Plan per `agent_docs/Implementation_Plan_Guide.md`: it changes a user-facing admin surface, adds persistent WordPress option state, changes config precedence, and touches config/bootstrap/admin ownership.

Scope: add curated admin-editable settings and diagnostics only. Do not add a form builder, template editor, full raw config editor, submission database, moderation workflow, or per-submission actions.

Current spec conflict: `docs/Canonical_Spec.md` currently says there is no settings admin UI and no database writes. Phase 1 must update the canonical spec and overview before any runtime code is implemented.

Source of Truth: this AI conversation until P1.T1 is complete; after P1.T1, `docs/Canonical_Spec.md` and `docs/overview.md` are authoritative.

Host Contracts:
- WordPress admin primitives: `admin_menu`, `add_options_page`, `manage_options`, nonce verification, `nav-tab-wrapper`/`nav-tab`, `form-table`, `submit_button()`, `.notice` markup, escaping helpers, and form submission conventions. Do not add custom admin JS/CSS unless a named control cannot be built with core wp-admin primitives.
- WordPress option persistence for sparse admin overrides only; public submissions remain file-backed and must not write submission data to the database. The option must be stored with autoload disabled where supported by the WordPress API path used.
- Existing config model: `Config::DEFAULTS`, optional `${WP_CONTENT_DIR}/eforms.config.php`, and `eforms_config` filter.
- Existing owner map: `Config` owns schema/sanitization/clamping; new admin option persistence must be routed through one owner, not raw option calls across admin renderers.
- Refactor hygiene: before adding admin/config logic, search for the same fact, predicate, branch shape, or interaction shape across `Config`, admin classes, tests, and owner docs. Move shared facts to the existing owner, migrate all consumers, delete superseded local paths, and prove absence with a targeted search. Do not create a shared abstraction unless it has a natural owner and removes more cognitive load than it adds.

Verification Baseline:
- Automated harness: existing PHP unit/integration/smoke tests plus WP-runtime harness.
- Manual residue: visual review in a real WordPress admin is recommended for final layout, but automated tests must verify capability, nonce, precedence, save behavior, masking, and escaped output.
- Verification Command:
  - `find eforms/src eforms/templates eforms/tests -name '*.php' -print0 | xargs -0 -n1 php -l`
  - `find eforms/tests/unit eforms/tests/integration eforms/tests/smoke -type f -name 'test_*.php' -print0 | sort -z | xargs -0 -n1 php`
  - `php eforms/tests/wp-runtime/run.php`
  - `php eforms/tests/tools/assert-template-slugs.php`
  - `git diff --check`

Design Preflight:
- User job: site operator needs to see and adjust common operational eForms settings from wp-admin so they can enable review/logging/spam defenses without editing PHP files.
- Existing patterns checked:
  - `DeclinedReviewAdmin`: fits for capability checks, Tools navigation, escaped WP-admin output; does not fit for settings persistence or save forms.
  - WordPress Settings pages: fits because configuration belongs under Settings and uses familiar submit/notices patterns; does not fit as a raw dump of every config key.
  - Current drop-in config: fits for advanced/developer control and deployment overrides; does not fit for non-developer operators.
- Options considered:
  - Status-only screen: safest and spec-light, but does not remove the operator pain of editing PHP.
  - Curated settings + effective config: best balance; exposes common operational choices while keeping low-level/internal caps out of the main UI.
  - Full raw config editor: maximum control but high misuse risk, large validation surface, and poor fit for the plugin's simplicity goal.
- Decision: curated Settings -> eForms page with editable operational groups and a read-only Effective Config/Status tab.
- Reuse contract: reuse `Config` schema/clamp logic, WordPress admin primitives, existing admin escaping/test stubs, `StorageHealth`/private-dir checks where applicable, and owner docs for routing.
- Reuse anti-drift contract:
  - One config vocabulary source: the editable allowlist, labels, control metadata, validation, source labels, and rendered field list must derive from one `Config`/admin-settings owner artifact, not parallel arrays in `SettingsAdmin` and tests.
  - One save mapper: checkbox/select/number/secret request mapping must run through one form-to-override mapper; individual field groups must not hand-roll blank filtering or type coercion.
  - One provenance owner: source-label and externally-controlled decisions belong to `Config` provenance helpers, not admin render branches.
  - Complete centralization: if a predicate such as blank-value filtering, allowlisted-key detection, or secret masking is promoted, migrate all live call sites that perform the surrounding operation unless a wrapper adds real domain meaning.
- Surface contract:
  - Entry point: Settings -> eForms.
  - Tabs: Settings, Effective Config, Status.
  - Settings tab groups: Declined Review, Logging, Challenge, Throttle, Privacy. Uploads may appear only as a small disabled/status group unless the spec explicitly makes upload controls editable.
  - Effective Config tab: read-only final values with source labels: default, admin option, config file, filter, or clamped.
  - Status tab: storage writability, declined-review storage state, logging target state, challenge key presence, and whether a drop-in file is present.
  - Save behavior: `manage_options` + nonce required; invalid values are rejected with a normal admin notice and do not partially save unknown keys.
  - Secret behavior: challenge secret is masked in display; blank submit keeps existing secret; explicit clear control removes the stored admin override.
  - Override behavior: config file and filter values win over admin settings; fields overridden by a higher source show as controlled externally rather than pretending the saved admin value is active. Externally controlled fields are excluded from mutation on save; a disabled/read-only control missing from POST must not clear or overwrite the stored admin override.
  - Status side effects: P1.T1 must decide whether opening Status may create/probe private storage via existing `StorageHealth`/`PrivateDir` helpers. Until the spec says otherwise, prefer a read-only status path for display and reserve mutating probes for explicit save/diagnostic actions.
  - Narrow-width behavior: groups stack in one column; no horizontal dependency on dense tables for editing.
- Delete / do not build:
  - no raw JSON/PHP config editor
  - no "all keys" generated settings page
  - no form-template builder
  - no direct `get_option()` / `update_option()` calls outside the admin settings persistence owner
  - no duplicate per-group submit branches that differ only by config path, label, or control type

Operational Change Overlay:
- Rollback: remove/ignore the sparse WP option and keep using `eforms.config.php`; drop-in and filter precedence must allow immediate operational override.
- Blast Radius: wp-admin configuration only plus `Config::bootstrap()` merge order; public form submission behavior changes only through the effective config values.
- Observability: admin notices on save/reject; existing config warning logging for invalid/unknown persisted overrides where warning logging is enabled.
- Failure Mode: invalid persisted admin overrides are rejected as a whole and defaults/drop-in/filter continue to produce a valid config snapshot.

Discovery Snapshot:
- Existing admin owner: `eforms/src/Admin/DeclinedReviewAdmin.php` owns the declined-review Tools viewer only.
- Existing config owner: `eforms/src/Config.php` owns defaults, override schema validation, merge, and clamps.
- Existing WP runtime wrapper: `eforms/src/WordPressRuntime.php` is minimal and currently only wraps safe redirects.
- Existing owner docs: `docs/Owner_Index.md` has rows for config array-path reads and declined-review admin, but no row for admin settings persistence.
- Existing raw option reads: `SubmitHandler` reads `admin_email` for fallback email behavior; tests provide WP stubs. This plan must not treat that as the new settings owner.
- Existing admin registration gate: `eforms_register_admin()` currently returns early when `declined_review.enable` is false; the new Settings -> eForms page must register independently so an operator can enable declined review from wp-admin.

Seam Guard:
- Admin option access guard: `rg -n "eforms_admin_config|update_option|delete_option|get_option" eforms/src eforms/uninstall.php | rg -v "AdminSettingsStore|Config|SubmitHandler|uninstall.php"` must show no raw eForms admin-option reads/writes outside the settings persistence owner, config bootstrap, and uninstall cleanup. Existing `SubmitHandler` `admin_email` lookup remains allowed.
- Raw editor guard: `rg -n "textarea.*eforms|raw config|json editor|php editor|all keys" eforms/src/Admin eforms/tests` must show no raw full-config editor path unless a future spec change explicitly adds it.
- Duplicate vocabulary guard: `rg -n "declined_review\\.enable|logging\\.mode|challenge\\.mode|throttle\\.per_ip\\.max_per_minute|privacy\\.ip_mode" eforms/src/Admin eforms/tests | rg -v "SettingsField|SettingsFields|Config|AdminSettingsStore|test_"` must not show parallel production metadata arrays outside the chosen settings-field owner.
- Duplicate mapper guard: `rg -n "blank.*secret|secret.*blank|externally controlled|controlled externally|source label|source_label" eforms/src/Admin eforms/tests | rg -v "SettingsAdmin|SettingsField|Config|test_"` must show no second owner for secret keep/clear or source-label behavior.
- Registration guard: admin hook tests must show Settings -> eForms registers even when `declined_review.enable=false`, while Tools -> eForms Declined registers only when declined review is enabled.

Shared-owner adoption gate:
- `AdminSettingsStore` must be introduced and consumed by `Config::bootstrap()` in the same implementation patch. Do not land an unused option wrapper as a standalone abstraction. Later `SettingsAdmin` save/render flows consume the already-live store in P3.T2/P3.T3.

Contract Carriers to Re-evaluate:
- `docs/Canonical_Spec.md`
- `docs/overview.md`
- `docs/Architecture_Router.md`
- `docs/Owner_Index.md`
- `eforms/src/Config.php`
- `eforms/src/bootstrap.php`
- `eforms/src/Admin/DeclinedReviewAdmin.php`
- `eforms/uninstall.php`
- WP-runtime/admin test stubs in `eforms/tests/bootstrap.php` and `eforms/tests/wp-runtime/run.php`

## Public Interfaces
- Add Settings -> eForms admin page for users with `manage_options`; it registers independently of `declined_review.enable`.
- Add sparse WordPress option: `eforms_admin_config`.
- Config precedence after the spec update: defaults < admin option overrides < drop-in file < `eforms_config` filter.
- Editable MVP allowlist:
  - `declined_review.enable`
  - `declined_review.retention_days`
  - `logging.mode`
  - `logging.level`
  - `logging.retention_days`
  - `challenge.mode`
  - `challenge.site_key`
  - `challenge.secret_key`
  - `throttle.enable`
  - `throttle.per_ip.max_per_minute`
  - `throttle.per_ip.cooldown_seconds`
  - `privacy.ip_mode`
- Read-only effective/status values may display any config domain, but secrets must be masked and internal-only values must not become editable by implication.

## Config Admin API Contract

P2 must expose a narrow API from `Config` (method names may vary, but responsibilities must not):

- `admin_schema()` or equivalent: returns editable config paths, type/enum/range data, secret/nullable flags, and admin editability. `AdminSettingsStore`, admin field metadata, and tests consume this instead of duplicating path allowlists or schema rules.
- `validate_admin_overrides($overrides)` or equivalent: validates sparse admin overrides as a whole payload, rejects unknown/non-allowlisted keys, applies schema/type/enum rules, and returns either sanitized overrides or structured errors.
- `effective_report()` or equivalent: returns a read-only report keyed by config path with final value, masked display value when secret, source label (`default`, `admin option`, `config file`, `filter`, `clamped`), whether a higher source controls the field, and whether the source was present even when the final value equals a lower source.
- `mask_secret_value($path, $value)` or equivalent: centralizes secret masking so admin renderers and tests do not invent masking rules.

`Config` owns config-path vocabulary, validation, clamp/provenance, and secret masking. `AdminSettingsStore` owns only WordPress option I/O. `SettingsFields` or an equivalent admin owner owns human labels, grouping, and wp-admin control metadata while deriving allowed paths and constraints from `Config`. `SettingsAdmin` owns only request orchestration and presentation.

## Admin Field Matrix

The implementation must encode this matrix once in a settings-field owner and derive rendering, save mapping, tests, and allowlist checks from it. The settings-field owner may hold labels/groups/control choices, but it must consume `Config` admin schema for allowed paths, types, ranges, enums, secret flags, nullable flags, and editability.

Externally controlled fields are display-only for that save request. The form mapper must distinguish "missing because disabled/read-only" from "missing because unchecked checkbox" using field metadata and/or submitted-field sentinels, so saving one editable field cannot clear hidden or disabled admin overrides.

| Config path | Control | Allowed values / range | Save behavior | Clear behavior | Externally controlled behavior |
|-------------|---------|------------------------|---------------|----------------|--------------------------------|
| `declined_review.enable` | checkbox | bool | missing checkbox maps to `false`; checked maps to `true` | none | disabled/read-only with source label |
| `declined_review.retention_days` | number | `null` or `[RETENTION_DAYS_MIN]`-`[RETENTION_DAYS_MAX]` | blank maps to no admin override; numeric values are validated through `Config` | blank removes admin override | disabled/read-only with source label |
| `logging.mode` | select | `off`, `minimal`, `jsonl` | selected value saved through allowlist mapper | none | disabled/read-only with source label |
| `logging.level` | number/select | `[LOGGING_LEVEL_MIN]`-`[LOGGING_LEVEL_MAX]` | selected/numeric value validated through `Config` | none | disabled/read-only with source label |
| `logging.retention_days` | number | `[RETENTION_DAYS_MIN]`-`[RETENTION_DAYS_MAX]` | numeric value validated through `Config` | none | disabled/read-only with source label |
| `challenge.mode` | select | `off`, `auto`, `always_post` | selected value saved through allowlist mapper | none | disabled/read-only with source label |
| `challenge.site_key` | text | string | trimmed string saved; blank removes admin override | blank removes admin override | disabled/read-only with source label |
| `challenge.secret_key` | password/secret controls | string | nonblank replacement saves new admin override; blank keeps existing stored admin override | explicit clear removes admin override only | disabled/read-only, masked, with source label |
| `throttle.enable` | checkbox | bool | missing checkbox maps to `false`; checked maps to `true` | none | disabled/read-only with source label |
| `throttle.per_ip.max_per_minute` | number | `[THROTTLE_MAX_PER_MIN_MIN]`-`[THROTTLE_MAX_PER_MIN_MAX]` | numeric value validated through `Config` | none | disabled/read-only with source label |
| `throttle.per_ip.cooldown_seconds` | number | `[THROTTLE_COOLDOWN_MIN]`-`[THROTTLE_COOLDOWN_MAX]` | numeric value validated through `Config` | none | disabled/read-only with source label |
| `privacy.ip_mode` | select | `none`, `masked`, `hash`, `full` | selected value saved through allowlist mapper | none | disabled/read-only with source label |

## Admin Save Flow Contract

- Route: same-page POST to Settings -> eForms unless P1.T1 explicitly chooses WordPress Settings API; do not introduce `admin-post.php` without a spec/plan update.
- Nonce: one action and field owned by `SettingsAdmin`; tests must cover missing, invalid, and valid nonce.
- Capability: `manage_options` is checked before render and before save; unauthorized save does not read or write `eforms_admin_config`.
- Save atomicity: unknown keys, non-allowlisted keys, invalid enum/type values, invalid submitted-field sentinels, or invalid secret clear/replace combinations reject the whole submitted admin override payload. No partial writes.
- Externally controlled fields: disabled/read-only fields are excluded from mutation and preserve existing stored admin overrides. Missing checkbox values map to `false` only for fields that were editable in the submitted form.
- Redirect/notices: after successful save or rejected save, use normal WordPress admin notice semantics and PRG-style redirect where available; tests may assert the rendered notice payload through the pure render/save harness.
- Secret handling: stored admin secrets are never rendered raw; blank secret input means keep existing stored admin override; explicit clear removes only the admin override and never alters drop-in/filter values.
- Autoload: first option creation should use an API path that stores `eforms_admin_config` with autoload disabled where supported by the WordPress version target.

## Test Harness Contract

Before admin UI tests are considered complete, extend the pure-PHP and WP-runtime stubs only as far as the implementation uses them:

- Admin menu: `add_options_page` in addition to existing `add_management_page`.
- Capability and nonce: `current_user_can`, `wp_nonce_field`, `wp_verify_nonce` or `check_admin_referer`, and `wp_die` behavior sufficient to assert unauthorized and bad-nonce paths.
- Option persistence: `get_option`, `add_option`, `update_option`, and `delete_option` with a test-owned in-memory option store that can assert autoload intent for first creation.
- Admin flow helpers: redirect and admin-notice capture only when the implementation emits them.

Do not add broad WordPress simulation beyond the functions required by these task tests.

## Phase 1 - Canonical Contract

Goals: update the stable spec/narrative so code can be implemented without contradicting current invariants.

- [ ] P1.T1 Update spec and overview for admin settings (Source: AI Conversation)
  - Type: standard
  - Artifacts: `docs/Canonical_Spec.md`, `docs/overview.md`, `docs/Implementation_Plan.md`
  - Interfaces: Settings -> eForms, `eforms_admin_config`, config precedence, curated editable allowlist, effective config/status display, permission/save behavior
  - Owner: docs/spec; runtime owners are assigned in later tasks
  - Depends On: none
  - Done When: the spec replaces the current "no settings admin UI" and "no database writes" absolutes with a narrow carve-out for sparse admin configuration only; it defines precedence as defaults < admin option < drop-in < filter; it states drop-in/filter override admin values; it defines the editable allowlist; it forbids raw full-config editing; it defines secret masking/keep/clear semantics; it defines whether Status may mutate storage or must be read-only; and `overview.md` explains the operator-facing model without implementation leakage.
  - Verified via: `rg -n "settings admin|eforms_admin_config|drop-in|editable allowlist|Effective Config|no form-builder" docs/Canonical_Spec.md docs/overview.md docs/Implementation_Plan.md`; `git diff --check`
  - Reasoning: high

- [ ] P1.T2 Sync ownership docs for the new settings seam (Source: AI Conversation -> P1.T1 spec)
  - Type: standard
  - Artifacts: `docs/Architecture_Router.md`, `docs/Owner_Index.md`
  - Interfaces: admin settings persistence owner, admin settings surface owner, config bootstrap merge owner
  - Owner: docs/owner registry
  - Depends On: P1.T1
  - Done When: owner docs identify the settings page owner, the sparse admin-option persistence owner, the allowed extension path, forbidden local seams, and verification hooks.
  - Verified via: `rg -n "Admin settings|eforms_admin_config|AdminSettings|SettingsAdmin|Config" docs/Architecture_Router.md docs/Owner_Index.md`
  - Reasoning: medium

- [ ] P1.T3 Add implementation anti-drift gates to owner docs (Source: AI Conversation -> P1.T1 spec)
  - Type: standard
  - Artifacts: `docs/Architecture_Router.md`, `docs/Owner_Index.md`, `docs/Implementation_Plan.md`
  - Interfaces: settings-field owner, config provenance owner, form-to-override mapper, seam guards
  - Owner: docs/owner registry
  - Depends On: P1.T2
  - Done When: owner docs state where the settings field matrix lives, where request-to-override mapping lives, where provenance/source-label logic lives, and which local copies are forbidden.
  - Verified via: `rg -n "settings field matrix|form-to-override|provenance|source label|seam guard" docs/Architecture_Router.md docs/Owner_Index.md docs/Implementation_Plan.md`
  - Reasoning: medium

## Phase 2 - Admin Option Persistence and Config Merge

Goals: create the narrow persisted override path and prove precedence before building the visible editor.

Phase default Type: seam-refactor

- [ ] P2.T0 Expose narrow config admin API (Spec: Configuration)
  - Artifacts: `eforms/src/Config.php`, config API tests
  - Interfaces: editable settings schema, override validation result, merge/provenance result, masked-secret helper, effective config report
  - Owner: `Config` owns config vocabulary, schema validation, clamping, final snapshot, and provenance decisions
  - Depends On: P1.T3
  - Existing Owner Evidence: `Config::bootstrap()` already owns defaults/drop-in/filter merge; schema, merge, and clamp helpers are currently private implementation details.
  - Docs Consulted: `docs/Architecture_Router.md`, `docs/Owner_Index.md`
  - Reuse Target: existing `Config::DEFAULTS`, schema rules, merge behavior, clamp paths, and `Anchors` lookups
  - No-Fallback Rule: admin code must not duplicate `Config::sanitize_override_schema()`, `Config::merge_overrides_or_default()`, `Config::schema_rule()`, enum lists, clamp ranges, or source-label rules.
  - No-Reuse Rationale: none
  - Complexity Budget: expose at most the narrow API in the Config Admin API Contract; do not move wp-admin labels, group headings, table markup, notices, nonce handling, or option I/O into `Config`.
  - Removal Proof: duplicate vocabulary guard shows no production admin path allowlist/range/enum copies outside the approved admin settings-field owner and `Config`.
  - Selector Reuse: none
  - Selector Delta: none
  - Style Delta: none
  - Consumer Status: staged - live consumers are P2.T1 `AdminSettingsStore` + `Config::bootstrap()` in the same patch, then P3 settings UI.
  - Behavior Harness: pure config tests prove validation, whole-payload rejection, provenance, clamp source labels, and secret masking without rendering admin HTML.
  - UI Completion Gate: not complete until P3 derives rendering/save controls from the admin settings-field owner, and that owner derives allowed paths/constraints from `Config` admin schema.
  - Boundary Decision: extend existing owner. Keep local is worse because settings metadata, validation, and provenance would drift from `Config`; a new shared layer is worse because `Config` already owns the snapshot, schema, clamps, and source precedence.
  - Done When: `Config` exposes a narrow public API matching the Config Admin API Contract for admin-editable schema, validation/provenance, effective reports, and secret masking while keeping raw private helpers private or renamed; the API returns structured errors without requiring admin code to know internal schema implementation; tests prove the admin schema has one production source.
  - Verified via: config admin API tests; duplicate vocabulary guard; `rg -n "sanitize_override_schema|merge_overrides_or_default|schema_rule" eforms/src/Admin eforms/tests | rg -v "test_"` returns no production admin usage of private config internals
  - Reasoning: high

- [ ] P2.T1 Add admin settings persistence owner and merge into config bootstrap (Spec: Configuration)
  - Artifacts: new `eforms/src/Admin/AdminSettingsStore.php` or equivalent owner, `eforms/src/Config.php`, config bootstrap tests, persistence tests, WP stubs as needed
  - Interfaces: `eforms_admin_config` option; read sparse overrides; write allowlisted overrides; delete/clear individual admin overrides; precedence defaults < admin option < drop-in < filter; schema sanitization; clamp behavior; provenance snapshot for effective config display
  - Owner: `AdminSettingsStore` owns WP option read/write for eForms admin config; `Config` owns merge, validation, clamping, final snapshot, and provenance
  - Depends On: P2.T0
  - Existing Owner Evidence: no current eForms owner for plugin settings options; `Config` owns schema but not WP option persistence.
  - Docs Consulted: `docs/Architecture_Router.md`, `docs/Owner_Index.md`
  - Reuse Target: WordPress option APIs behind one owner; `Config` admin validation/provenance API from P2.T0; existing drop-in/filter merge and clamp paths
  - No-Fallback Rule: admin renderers and public runtime code must not call `get_option( 'eforms_admin_config' )` or `update_option( 'eforms_admin_config' )` directly; do not add a parallel settings schema in admin code.
  - No-Reuse Rationale: a new persistence owner prevents option access from spreading across admin UI, config bootstrap, tests, and future settings groups.
  - Complexity Budget: `AdminSettingsStore` may know the option name and call WordPress option APIs only; it must not render admin HTML, own field labels/groups, decide source labels, or implement config validation outside `Config`.
  - Removal Proof: seam guard for raw `eforms_admin_config` access shows no production reads/writes outside `AdminSettingsStore`, `Config::bootstrap()` integration, and uninstall cleanup.
  - Selector Reuse: WordPress admin form primitives only; no custom JS settings runtime.
  - Selector Delta: none
  - Style Delta: none
  - Consumer Status: live - `Config::bootstrap()` consumes `AdminSettingsStore` in the same patch; staged UI consumers follow in P3.T2/P3.T3.
  - Behavior Harness: direct store tests plus config precedence tests covering admin option and higher-source overrides.
  - UI Completion Gate: not complete until a later UI task proves the store through a visible save path.
  - Boundary Decision: introduce new shared layer for option I/O only. Keep local is worse because raw option access would spread across config, UI, uninstall, and tests; extending `Config` with direct WP option calls is worse because it would mix schema/snapshot ownership with persistence mechanics.
  - Done When: the store reads absent/invalid options as empty overrides, writes only the editable allowlist, rejects unknown keys as a whole, supports clear semantics for nullable/secret fields, stores the option with autoload disabled where supported, exposes a test reset path without affecting production runtime, and `Config::bootstrap()` consumes the store so admin overrides affect `Config::get()` only when not superseded by drop-in/filter. Invalid admin option payloads fail closed to empty admin overrides; existing drop-in and filter tests still pass; effective-config provenance can distinguish default/admin/drop-in/filter/clamped sources without exposing secrets, including equal-value cases where the higher source is present but produces the same final value.
  - Verified via: new admin settings store tests; config precedence tests covering absent admin option, admin-only override, drop-in overriding admin, filter overriding both, invalid admin option rejection, and clamp provenance; seam guard for raw `eforms_admin_config` access
  - Reasoning: high

## Phase 3 - Settings Admin UI

Goals: add the smallest useful wp-admin surface while avoiding a raw all-config editor.

Phase default Type: ui-ownership

- [ ] P3.T1 Register Settings -> eForms admin shell (Spec: Admin Settings)
  - Artifacts: new `eforms/src/Admin/SettingsAdmin.php`, `eforms/src/bootstrap.php`, admin tests/stubs
  - Interfaces: Settings -> eForms page, `manage_options`, tabs, nonce-protected POST route
  - Owner: `SettingsAdmin` owns settings page rendering and save orchestration
  - Depends On: P2.T1
  - Existing Owner Evidence: `DeclinedReviewAdmin` owns only Tools -> eForms Declined and should not grow into general settings.
  - Docs Consulted: `docs/Architecture_Router.md`, `docs/Owner_Index.md`, `agent_docs/design_preflight.md`
  - Reuse Target: WordPress admin hooks, `add_options_page`, core tab/form/table/notice primitives, escaping helpers, existing admin test stubs
  - No-Fallback Rule: do not put settings rendering into `DeclinedReviewAdmin`, `SubmitHandler`, or `Config`.
  - Complexity Budget: shell only: menu registration, tab routing, capability/nonce gate, and save dispatch; no field-specific save logic beyond delegating to the mapper introduced in P3.T2.
  - Old Visible Owner: none
  - New Visible Owner: `SettingsAdmin`
  - Boundary Decision: introduce new admin surface owner. Extending `DeclinedReviewAdmin` is worse because it owns the Tools declined-review viewer only; keeping this in bootstrap is worse because bootstrap may only register hooks and should not render/admin-save.
  - Removal Proof: no old settings page exists; prove no duplicate eForms settings page is registered.
  - Negative Check: admin hook tests assert one Settings -> eForms page and one separate Tools -> eForms Declined page when declined review is enabled.
  - Done When: `eforms_register_admin()` or its replacement always registers Settings -> eForms independently of `declined_review.enable`; Tools -> eForms Declined remains gated by `declined_review.enable`; the page registers only for admins, renders tabs with escaped output using core wp-admin primitives, rejects unauthorized access, and routes POST saves through the Admin Save Flow Contract with nonce/capability checks.
  - Verified via: new `test_admin_settings_page.php` covering registration, capability, nonce failure, duplicate-page absence, escaped tab output, and chosen save route behavior
  - Reasoning: high

- [ ] P3.T2 Implement curated editable settings groups (Spec: Admin Settings)
  - Artifacts: `SettingsAdmin`, `SettingsFields` or equivalent admin settings-field owner, `AdminSettingsStore`, admin tests
  - Interfaces: editable allowlist groups for declined review, logging, challenge, throttle, and privacy
  - Owner: admin settings-field owner owns labels/groups/control metadata and form-to-override mapping; `SettingsAdmin` owns orchestration/presentation; `AdminSettingsStore` owns persistence; `Config` owns validation/clamping/provenance
  - Depends On: P3.T1
  - Existing Owner Evidence: `Config::DEFAULTS` and constraints table define supported config domains; no existing form mapper exists.
  - Docs Consulted: `docs/Owner_Index.md`, `agent_docs/design_preflight.md`
  - Reuse Target: `Config` admin schema plus one admin settings-field matrix; one form-to-override mapper; WordPress admin form primitives
  - No-Fallback Rule: no generated all-keys editor and no setting outside the spec allowlist.
  - Complexity Budget: no custom JavaScript, no bespoke CSS framework, no per-group save branches, and no local copies of enum/range/secret/nullability facts already exposed by `Config`.
  - Old Visible Owner: none
  - New Visible Owner: `SettingsAdmin` settings tab
  - Boundary Decision: introduce a narrow admin settings-field owner under `eforms/src/Admin/`. Keeping labels/control metadata in `Config` is worse because it couples runtime config to admin presentation; keeping mapper branches inside `SettingsAdmin` is worse because it invites per-group drift; a broader shared UI layer is worse because this plugin has no reusable admin component system yet.
  - Removal Proof: raw editor guard returns no matches.
  - Negative Check: tests submit unknown/non-allowlisted keys and prove nothing is saved; tests submit a form with externally controlled disabled fields and prove existing stored admin overrides are preserved.
  - Done When: each allowlisted setting can be saved, cleared where applicable, rejected when invalid, or shown as externally controlled when drop-in/filter wins; success/error notices are clear; challenge secret is never echoed raw; blank secret submit keeps the existing stored secret unless clear is explicit; missing checkbox values map to false only for editable submitted fields; all rendered controls and save behavior are derived from the single field matrix and `Config` admin schema.
  - Verified via: admin settings save tests for every group, secret masking/keep/clear tests, unknown-key rejection test, externally-controlled field preservation test, duplicate vocabulary guard, duplicate mapper guard
  - Reasoning: high

- [ ] P3.T3 Implement Effective Config and Status tabs (Spec: Admin Settings)
  - Artifacts: `SettingsAdmin`, `Config` provenance accessors, storage/status helper usage, admin tests
  - Interfaces: read-only effective values, source labels, masked secrets, storage and feature status
  - Owner: `SettingsAdmin` owns presentation; `Config` owns effective/provenance data; existing storage helpers own storage checks
  - Depends On: P2.T1, P3.T1
  - Existing Owner Evidence: `Config` produces the frozen snapshot; `DeclinedReviewLog` and storage helpers know declined-review paths.
  - Docs Consulted: `docs/Architecture_Router.md`, `docs/Owner_Index.md`, `agent_docs/design_preflight.md`
  - Reuse Target: `Config::get()` plus provenance accessor/effective report, `Config::bool($config, array( 'declined_review', 'enable' ), false)` or a deliberately added `DeclinedReviewLog` status helper, storage/private-dir helpers
  - No-Fallback Rule: status tab must not recompute config independently or read raw option/drop-in files for behavior decisions.
  - Complexity Budget: display-only tabs; no inline editing, no export/import, no raw full-config dump, and no direct reads of `eforms_admin_config` or drop-in file contents.
  - Old Visible Owner: none
  - New Visible Owner: `SettingsAdmin` effective config/status tabs
  - Boundary Decision: keep presentation local to `SettingsAdmin` while extending existing status owners only for missing status predicates. Moving status policy into `SettingsAdmin` is worse because it would duplicate storage/logging/config decisions; introducing a broad diagnostics subsystem is worse until more admin diagnostics exist.
  - Removal Proof: raw editor guard returns no matches.
  - Negative Check: tests assert secrets are masked and raw filesystem paths are not exposed unless already allowed by an existing admin diagnostic contract.
  - Done When: operators can tell which settings are effective, which source controls them, whether declined review/logging/challenge/throttle are active, and whether required storage/key prerequisites are missing; source labels are derived from `Config` provenance and status checks reuse existing storage/logging owners. If P1.T1 chooses read-only Status, rendering the tab must not create private directories, deny-rule files, or probe files; if P1.T1 explicitly allows a mutating diagnostic, the UI must label that action and tests must prove the mutation is not triggered by passive render.
  - Verified via: effective-config rendering tests, source-label tests, secret masking tests, status empty/error-state tests
  - Reasoning: high

## Phase 4 - Verification Closure and Documentation

Goals: close cross-cutting risk, update docs/tests, and leave future expansion explicit.

- [ ] P4.T1 Add focused regression and guard coverage (Spec: Admin Settings, Configuration)
  - Type: standard
  - Artifacts: `eforms/tests/unit/`, `eforms/tests/integration/`, `eforms/tests/wp-runtime/run.php`, `eforms/tests/bootstrap.php`
  - Interfaces: option persistence, config precedence, admin page registration, save flow, effective-config rendering
  - Owner: test harness
  - Depends On: P3.T2, P3.T3
  - Done When: the automated harness covers enabled/disabled settings, permissions, nonce failures, invalid admin option payloads, drop-in/filter precedence, secret masking, externally controlled disabled-field preservation, unknown-key rejection, raw-editor absence, duplicate vocabulary absence, duplicate mapper absence, and the minimal admin/option stubs named in the Test Harness Contract.
  - Verified via: targeted tests plus full Verification Command
  - Reasoning: medium

- [ ] P4.T2 Add uninstall cleanup for admin option (Spec: Admin Settings, Configuration)
  - Type: standard
  - Artifacts: `eforms/uninstall.php`, `eforms/tests/integration/test_uninstall_purge_flags.php` or focused uninstall option test
  - Interfaces: `eforms_admin_config` option lifecycle
  - Owner: uninstall cleanup; `AdminSettingsStore` remains the option-name owner
  - Depends On: P2.T1
  - Done When: uninstall deletes `eforms_admin_config` before any `wp_upload_dir()` availability guard, upload-dir guard, or purge-flag early return; it deletes the option regardless of file purge flags, without deleting public submission artifacts unless existing purge flags request them.
  - Verified via: uninstall test proving option deletion and existing purge flag behavior still passes
  - Reasoning: medium

- [ ] P4.T3 Final owner/docs sync and release notes (Spec: Admin Settings)
  - Type: standard
  - Artifacts: `README.md`, `docs/Architecture_Router.md`, `docs/Owner_Index.md`, `docs/Implementation_Plan.md`
  - Interfaces: operator setup docs, ownership docs, plan verification status
  - Owner: docs
  - Depends On: P4.T1, P4.T2
  - Done When: README explains drop-in vs admin settings precedence and points operators to Settings -> eForms; owner docs match implemented owners; completed plan tasks include `Verified via` notes; no stale statements claim there is no settings admin UI or no DB writes without the admin-config carve-out.
  - Verified via: `rg -n "no settings admin UI|no database writes|eforms_admin_config|Settings -> eForms|drop-in" README.md docs eforms/src eforms/tests`; `git diff --check`
  - Reasoning: medium

## Invariant Matrix

| Invariant | Positive Proof | Negative Proof |
|-----------|----------------|----------------|
| Admin settings are sparse overrides only, not a full config editor. | P3.T2 saves every allowlisted key through `AdminSettingsStore`. | P3.T2 unknown/non-allowlisted submission saves nothing; raw editor guard has no matches. |
| Config precedence is defaults < admin option < drop-in < filter. | P2.T1 precedence tests prove each higher source wins. | P2.T1 tests prove lower-source admin values do not override drop-in/filter values. |
| Public submission data still does not use the database. | P2/P3 tasks introduce only `eforms_admin_config` option writes. | Seam guard proves no submission/declined-review payload writes through options. |
| Secrets are not exposed in admin output. | P3.T2/P3.T3 tests show configured secret state and masked effective value. | Tests assert raw `challenge.secret_key` value is absent from rendered HTML. |
| Admin page requires administrator capability and nonce on save. | P3.T1 tests prove authorized render/save works. | P3.T1 tests prove unauthorized users and bad nonce cannot render/save. |
| Drop-in and filter remain emergency override paths. | P2.T1 tests show drop-in/filter source labels and effective values. | P3.T2 tests show externally controlled fields do not pretend admin values are active. |
| Settings facts have one owner. | P2.T0 owns config paths/constraints/provenance in `Config`; P3.T2 owns labels/groups/control mapping in one admin settings-field matrix derived from that schema. | Duplicate vocabulary and mapper guards prove no second production metadata/mapper owner exists. |
| Refactors remove superseded seams instead of partially centralizing. | P2/P3 tasks migrate live call sites to the promoted owner in the same patch. | Seam guards prove old copied facts, branch shapes, and raw option paths are absent. |

## Known Debt & Open Questions

- [ ] Debt: Full advanced config editor deferred.
  - Type: debt
  - Owner: admin settings
  - Why Deferred: a raw/full editor would increase misuse risk and contradict the selected curated interaction model.
  - Trigger: add only if operators repeatedly need unsupported config keys and the spec explicitly approves an advanced editor.
  - Verification Hook: raw editor guard remains green until that future spec change.

- [ ] Debt: Upload settings remain read-only/status-only in MVP.
  - Type: debt
  - Owner: admin settings/uploads
  - Why Deferred: upload behavior carries storage and email-size risk; the first settings release should prove the admin persistence seam before exposing upload controls.
  - Trigger: add editable upload controls when a concrete operator workflow needs them.
  - Verification Hook: `rg -n "uploads\\." eforms/src/Admin eforms/tests` shows no editable upload controls before the spec adds them.

- [ ] Open Question: Should admin settings be exported/imported?
  - Type: open-question
  - Owner: admin settings
  - Why Deferred: export/import is useful for multi-site operations but adds serialization and support surface not needed for first release.
  - Decision Trigger: more than one site needs repeated manual setup or migration.
  - Decision Options: keep drop-in as the deployment export path; add copyable PHP snippet generator; add JSON import/export.
  - Default Until Decided: use `eforms.config.php` for portable/deployable configuration.
  - Verification Hook: README documents drop-in as the deployment-friendly path.

## Assumptions
- The admin settings page stores only plugin configuration overrides, not form submissions or review records.
- `eforms.config.php` remains the preferred deployment/operator override for advanced users and wins over admin settings.
- The `eforms_config` filter remains the highest runtime override because site code may need final policy control.
- The first implementation should optimize for safe operator workflows, not complete exposure of every key in `Config::DEFAULTS`.
