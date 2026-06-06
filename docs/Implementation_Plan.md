# Admin Spam Smoke Diagnostic

Source of Truth: `docs/Canonical_Spec.md#sec-spam-smoke-command`; conversation-approved admin diagnostic plan.
Host Contracts: WordPress admin `manage_options` capability and nonce-protected POST forms.
Verification Baseline: existing pure-PHP integration lane plus WordPress runtime smoke.

- [x] SMOKE-ADMIN-001 Extract shared diagnostic owner.
  - Type: seam-refactor
  - Artifacts: `eforms/src/Diagnostics/SpamSmokeDiagnostic.php`, `eforms/src/Cli/SpamSmokeCommand.php`
  - Interfaces: `SpamSmokeDiagnostic::run()`, `SpamSmokeCommand::run()`
  - Owner: `SpamSmokeDiagnostic`
  - Depends On: existing `wp eforms spam-smoke` behavior
  - Done When: CLI delegates to the shared diagnostic and keeps the existing result/exit contract.
  - Verified via: `php eforms/tests/integration/test_spam_smoke_command.php`
  - Reasoning: medium

- [x] SMOKE-ADMIN-002 Add Settings -> eForms diagnostic action.
  - Type: ui-ownership
  - Artifacts: `eforms/src/Admin/SettingsAdmin.php`
  - Interfaces: nonce-protected `eforms_run_spam_smoke` POST action
  - Owner: `SettingsAdmin`
  - Depends On: `SMOKE-ADMIN-001`
  - Done When: admins can run the diagnostic from Settings -> eForms without saving settings or storing diagnostic history.
  - Verified via: `php eforms/tests/integration/test_admin_settings_page.php`
  - Reasoning: medium

- [x] SMOKE-ADMIN-003 Sync public docs and owner routing.
  - Type: standard
  - Artifacts: `docs/Canonical_Spec.md`, `docs/Owner_Index.md`, `docs/Architecture_Router.md`, `README.md`, `docs/overview.md`
  - Interfaces: documented CLI/admin diagnostic surfaces
  - Owner: docs/spec owner
  - Depends On: `SMOKE-ADMIN-001`, `SMOKE-ADMIN-002`
  - Done When: docs name the shared diagnostic owner and admin surface without adding presets/history/AJAX.
  - Verified via: `rg -n "SpamSmokeDiagnostic|Run Spam Smoke Test|Spam Smoke Diagnostic|Settings -> eForms.*spam smoke" docs README.md eforms/src`
  - Reasoning: low

- [x] SMOKE-ADMIN-004 Make diagnostic results self-explaining.
  - Type: seam-refactor
  - Artifacts: `eforms/src/Diagnostics/SpamSmokeDiagnostic.php`, `eforms/src/Cli/SpamSmokeCommand.php`, `eforms/src/Admin/SettingsAdmin.php`, `eforms/tests/integration/test_spam_smoke_command.php`, `eforms/tests/integration/test_admin_settings_page.php`
  - Interfaces: `SpamSmokeDiagnostic::run()`, `SpamSmokeDiagnostic::rows()`
  - Owner: `SpamSmokeDiagnostic`
  - Depends On: `SMOKE-ADMIN-001`, `SMOKE-ADMIN-002`
  - Done When: scenario definitions use named input/expect/config-scope data, include a combined-soft-signal check, and both CLI/admin render expected outcome plus config scope from the diagnostic owner.
  - Verified via: `php eforms/tests/integration/test_spam_smoke_command.php`; `php eforms/tests/integration/test_admin_settings_page.php`
  - Reasoning: medium

# Runtime Health Doctor

Source of Truth: `docs/Canonical_Spec.md#sec-runtime-health-diagnostic`; conversation-approved runtime doctor plan.
Host Contracts: WordPress admin `manage_options` capability, nonce-protected POST forms, WP-CLI command registration.
Verification Baseline: existing pure-PHP integration lane plus shipped-template slug guard.

- [x] DOCTOR-SPEC-001 Define runtime doctor contract.
  - Type: standard
  - Artifacts: `docs/Canonical_Spec.md`, `docs/overview.md`, `README.md`
  - Interfaces: `wp eforms doctor`, Settings -> eForms diagnostic action
  - Owner: docs/spec owner
  - Depends On: conversation-approved runtime doctor plan
  - Done When: public behavior, active-probe limits, result semantics, no-persistence rule, and GC observable-only limitation are explicit.
  - Verified via: `rg -n "Runtime Health|wp eforms doctor|Run Runtime Health Check" docs README.md`
  - Reasoning: medium

- [x] DOCTOR-CORE-002 Add shared runtime health diagnostic owner.
  - Type: seam-refactor
  - Artifacts: `eforms/src/Diagnostics/RuntimeHealthDiagnostic.php`
  - Interfaces: `RuntimeHealthDiagnostic::run()`, `RuntimeHealthDiagnostic::rows()`, `RuntimeHealthDiagnostic::summary_line()`
  - Owner: `RuntimeHealthDiagnostic`
  - Reuse Target: `PrivateDir`, `TemplateLoader`, `GcRunner`, `Config`
  - No-Fallback Rule: no duplicate directory hardening, template parsing, GC scanning, or provenance logic inside adapters
  - Complexity Budget: direct check list, no plugin-style check registry
  - Removal Proof: no runtime-health checks in CLI/admin adapters
  - Depends On: `DOCTOR-SPEC-001`
  - Done When: all checks run through one owner and clean up temporary probe files.
  - Verified via: `php eforms/tests/integration/test_runtime_health_diagnostic.php`
  - Reasoning: medium

- [x] DOCTOR-CLI-003 Add `wp eforms doctor`.
  - Type: standard
  - Artifacts: `eforms/src/Cli/RuntimeHealthCommand.php`, `eforms/src/bootstrap.php`
  - Interfaces: `wp eforms doctor`
  - Owner: CLI adapter
  - Depends On: `DOCTOR-CORE-002`
  - Done When: CLI prints the shared rows and exits `0` for pass/warn-only, nonzero for fail.
  - Verified via: `php eforms/tests/integration/test_runtime_health_diagnostic.php`
  - Reasoning: low

- [x] DOCTOR-ADMIN-004 Add admin runtime health action.
  - Type: ui-ownership
  - Artifacts: `eforms/src/Admin/SettingsAdmin.php`
  - Interfaces: nonce-protected `eforms_run_runtime_doctor` POST action
  - Owner: `SettingsAdmin`
  - Depends On: `DOCTOR-CORE-002`
  - Done When: admins can run the doctor without saving settings, results are not persisted, unauthorized/bad nonce requests expose no result, and the settings table remains unchanged.
  - Verified via: `php eforms/tests/integration/test_admin_settings_page.php`
  - Reasoning: medium

- [x] DOCTOR-DOCS-005 Sync owner routing and plan status.
  - Type: standard
  - Artifacts: `docs/Owner_Index.md`, `docs/Architecture_Router.md`, `docs/Implementation_Plan.md`
  - Interfaces: documented shared diagnostic owner and adapters
  - Owner: docs/spec owner
  - Depends On: `DOCTOR-CLI-003`, `DOCTOR-ADMIN-004`
  - Done When: routing docs name `RuntimeHealthDiagnostic`; implementation tasks are marked verified with commands.
  - Verified via: `rg -n "RuntimeHealthDiagnostic|wp eforms doctor|Run Runtime Health Check" docs eforms/src eforms/tests`
  - Reasoning: low
