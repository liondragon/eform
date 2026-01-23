# Implementation Plan — electronic_forms

This document decomposes `docs/Canonical_Spec.md` into phased implementation work items. It is **non-normative**: it must not introduce behavior beyond the spec.

**Authoritative inputs:**
- Spec: `docs/Canonical_Spec.md` (STABLE)
- Digest (invariants checklist): `docs/Spec_Digest.md` (immutable)

**Task cards:** Each checkbox includes `Artifacts/Interfaces/Tests/Depends On/Done When` (optionally `Handoff Required`) so execution agents can implement without guessing.

**Repo state (observed):** `eforms/templates/` and `eforms/assets/` exist; the PHP implementation under `eforms/eforms.php` and `eforms/src/` is not present yet.

---

## Phase 0 — Bootstrap (minimal runnable skeleton)

**Goals**
- Establish the plugin entry points, core utilities, and config snapshot so later phases can build behavior without reshaping foundations.

**Non-Goals**
- End-to-end submission handling.

**Acceptance**
- Plugin can load without fatals and can render a deterministic “not yet implemented” configuration error for the public surfaces.

### Work items

- [x] Scaffold plugin entry points + autoload boundaries (Spec: Architecture and file layout (docs/Canonical_Spec.md#sec-architecture); Public surfaces index (docs/Canonical_Spec.md#sec-objective); Lazy-load lifecycle (components & triggers) (docs/Canonical_Spec.md#sec-lazy-load-matrix), Anchors: None)
  - `Artifacts:` `eforms/eforms.php` (new), `eforms/src/` (new dir), `eforms/src/bootstrap.php` (new, optional)
  - `Interfaces:` `[eform]`, `eform_render($slug, $opts)` (stubbed), `POST /eforms/mint` (stubbed), `wp eforms gc` (stubbed)
  - `Tests:` `eforms/tests/smoke/test_bootstrap.php` (new)
  - `Depends On:` None
  - `Done When:` plugin loads in the chosen WordPress smoke harness; all public surfaces fail closed with spec-defined deterministic error codes (no white screens)

- [x] Implement `Helpers` primitives used across subsystems (Spec: Central Registries (docs/Canonical_Spec.md#sec-central-registries); Shared lifecycle and storage contract (docs/Canonical_Spec.md#sec-shared-lifecycle); Security invariants (docs/Canonical_Spec.md#sec-security-invariants), Anchors: [TOKEN_TTL_MAX])
  - `Artifacts:` `eforms/src/Helpers.php` (new)
  - `Interfaces:` None
  - `Tests:` `eforms/tests/unit/test_helpers.php` (new)
  - `Depends On:` Phase 0 — Scaffold plugin entry points + autoload boundaries
  - `Done When:` helpers required by the spec exist (notably sharding/key derivation/canonicalization helpers) and `eforms/tests/unit/test_helpers.php` passes

- [x] Implement config snapshot bootstrap + override sources (Spec: Configuration (docs/Canonical_Spec.md#sec-configuration), Anchors: None)
  - `Artifacts:` `eforms/src/Config.php` (new)
  - `Interfaces:` `${WP_CONTENT_DIR}/eforms.config.php`, `eforms_config` filter
  - `Tests:` `eforms/tests/unit/test_config_bootstrap.php` (new)
  - `Depends On:` Phase 0 — Scaffold plugin entry points + autoload boundaries
  - `Done When:` `Config::get()` produces a frozen per-request snapshot; unknown keys are rejected; drop-in failure behavior matches the spec; `eforms/tests/unit/test_config_bootstrap.php` passes

- [x] Implement config schema validation (types/enums/bools + per-key fallback) (Spec: Configuration (docs/Canonical_Spec.md#sec-configuration), Anchors: None)
  - `Artifacts:` `eforms/src/Config.php` (modify)
  - `Interfaces:` None (internal enforcement of config constraints)
  - `Tests:` `eforms/tests/unit/test_config_validation.php` (new)
  - `Depends On:` Phase 0 — Implement config snapshot bootstrap + override sources
  - `Done When:` invalid enums/booleans/types in merged config are rejected deterministically by falling back to defaults before clamping; drop-in-derived validation errors emit `EFORMS_CONFIG_DROPIN_INVALID` with `{ path, reason }` when logging is enabled; `eforms/tests/unit/test_config_validation.php` passes

- [x] Implement config numeric clamping via named Anchors (Spec: Anchors (docs/Canonical_Spec.md#sec-anchors); Configuration (docs/Canonical_Spec.md#sec-configuration), Anchors: [MIN_FILL_SECONDS_MIN], [MIN_FILL_SECONDS_MAX], [TOKEN_TTL_MIN], [TOKEN_TTL_MAX], [MAX_FORM_AGE_MIN], [MAX_FORM_AGE_MAX], [CHALLENGE_TIMEOUT_MIN], [CHALLENGE_TIMEOUT_MAX], [THROTTLE_MAX_PER_MIN_MIN], [THROTTLE_MAX_PER_MIN_MAX], [THROTTLE_COOLDOWN_MIN], [THROTTLE_COOLDOWN_MAX], [LOGGING_LEVEL_MIN], [LOGGING_LEVEL_MAX], [RETENTION_DAYS_MIN], [RETENTION_DAYS_MAX], [MAX_FIELDS_MIN], [MAX_FIELDS_MAX], [MAX_OPTIONS_MIN], [MAX_OPTIONS_MAX], [MAX_MULTIVALUE_MIN], [MAX_MULTIVALUE_MAX])
  - `Artifacts:` `eforms/src/Config.php` (modify)
  - `Interfaces:` None
  - `Tests:` `eforms/tests/unit/test_config_clamps.php` (new)
  - `Depends On:` Phase 0 — Implement config snapshot bootstrap + override sources
  - `Done When:` numeric config values clamp according to the spec’s constraints table; `eforms/tests/unit/test_config_clamps.php` passes (table-driven coverage)

- [x] Implement compatibility guards (min versions + shared uploads semantics) (Spec: Compatibility and updates (docs/Canonical_Spec.md#sec-compatibility); Shared lifecycle and storage contract (docs/Canonical_Spec.md#sec-shared-lifecycle), Anchors: None)
  - `Artifacts:` `eforms/src/Compat.php` (new), `eforms/eforms.php` (modify)
  - `Interfaces:` Activation/load-time failure behavior (admin notice + deactivate when unmet)
  - `Tests:` `eforms/tests/smoke/test_compat_guards.php` (new), `eforms/tests/smoke/test_compat_guards.md` (new, manual checklist)
  - `Depends On:` Phase 0 — Scaffold plugin entry points + autoload boundaries
  - `Done When:` minimum PHP/WP version checks and shared-upload semantics guardrails match the spec; `eforms/tests/smoke/test_compat_guards.php` passes; manual checklist steps are documented in `eforms/tests/smoke/test_compat_guards.md`

- [x] Define stable error-code surface + structured error containers (Spec: Error handling (docs/Canonical_Spec.md#sec-error-handling); Configuration (docs/Canonical_Spec.md#sec-configuration), Anchors: None)
  - `Artifacts:` `eforms/src/ErrorCodes.php` (new), `eforms/src/Errors.php` (new)
  - `Interfaces:` Error codes listed in §Error handling (append-only contract)
  - `Tests:` `eforms/tests/unit/test_error_codes_append_only.php` (new)
  - `Depends On:` Phase 0 — Scaffold plugin entry points + autoload boundaries
  - `Done When:` errors can be represented as `_global` + per-field errors; emitted codes match the stable surface defined by the spec; `eforms/tests/unit/test_error_codes_append_only.php` passes

- [x] Set up a minimal test harness suitable for pure-PHP unit tests (Spec: DRY principles (docs/Canonical_Spec.md#sec-dry-principles), Anchors: None)
  - `Artifacts:` `eforms/tests/` (new dir), `eforms/tests/bootstrap.php` (new)
  - `Interfaces:` None
  - `Tests:` (this task creates the harness)
  - `Depends On:` Phase 0 — Scaffold plugin entry points + autoload boundaries
  - `Done When:` unit tests can run in CI/local without requiring a full WordPress runtime; WordPress-specific behavior is deferred to later integration/E2E tasks

---

## Phase 1 — Core path (hidden-mode render → POST → PRG)

**Goals**
- Implement the deterministic render + submit pipeline for non-cacheable forms (hidden-mode), including ledger dedupe, email delivery, error rerender, and cache-safety.

**Non-Goals**
- JS-minted mode and `/eforms/mint` beyond stubs.
- Optional throttling and adaptive challenge (unless required by baseline config defaults).

**Acceptance**
- The pipeline order and prohibitions in `docs/Spec_Digest.md` that apply to hidden-mode are satisfied.

### Contracts & data models

- [x] Implement template loader + JSON decoding + version gate (Spec: Template JSON (docs/Canonical_Spec.md#sec-template-json); Template versioning (docs/Canonical_Spec.md#sec-template-versioning), Anchors: None)
  - `Artifacts:` `eforms/src/Rendering/TemplateLoader.php` (new)
  - `Interfaces:` Shipped JSON templates under `eforms/templates/forms/`
  - `Tests:` `eforms/tests/unit/test_template_loader.php` (new)
  - `Depends On:` Phase 0 — Implement `Helpers` primitives used across subsystems; Phase 0 — Implement config snapshot bootstrap + override sources; Phase 0 — Define stable error-code surface + structured error containers
  - `Done When:` templates load deterministically from disk (including filename/slug allow-list); JSON parse failures produce deterministic configuration errors; `eforms/tests/unit/test_template_loader.php` passes

- [x] Implement template schema/envelope validation (unknown keys rejected) (Spec: Template model (docs/Canonical_Spec.md#sec-template-model); Template JSON (docs/Canonical_Spec.md#sec-template-json); Template validation (docs/Canonical_Spec.md#sec-template-validation), Anchors: [MAX_FIELDS_MAX], [MAX_OPTIONS_MAX], [MAX_MULTIVALUE_MAX])
  - `Artifacts:` `eforms/src/Validation/TemplateValidator.php` (new)
  - `Interfaces:` Template envelope/schema contract (additionalProperties/unknown keys rejected at every level)
  - `Tests:` `eforms/tests/unit/test_template_schema_validation.php` (new)
  - `Depends On:` Phase 1 — Implement template loader + JSON decoding + version gate
  - `Done When:` schema violations are rejected deterministically (unknown keys, required keys, enums, conditional requirements, row_group balance, and email block requirements such as `is_email()` and `email_template` registry); `eforms/tests/unit/test_template_schema_validation.php` passes

- [x] Implement internal registries (field types, validators, normalizers/coercers, renderers) with deterministic handler resolution (Spec: Central registries (docs/Canonical_Spec.md#sec-central-registries), Anchors: [MAX_FIELDS_MAX], [MAX_OPTIONS_MAX], [MAX_MULTIVALUE_MAX])
  - `Artifacts:` `eforms/src/Validation/FieldTypeRegistry.php` (new), `eforms/src/Validation/ValidatorRegistry.php` (new), `eforms/src/Validation/NormalizerRegistry.php` (new), `eforms/src/Rendering/RendererRegistry.php` (new)
  - `Interfaces:` None
  - `Tests:` `eforms/tests/unit/test_registry_resolution.php` (new)
  - `Depends On:` Phase 0 — Define stable error-code surface + structured error containers; Phase 0 — Implement `Helpers` primitives used across subsystems
  - `Done When:` unknown handler IDs fail fast with deterministic exceptions that are surfaced as configuration errors; `eforms/tests/unit/test_registry_resolution.php` passes

- [x] Implement built-in field types: text-like inputs + tel formatting (Spec: Field types (docs/Canonical_Spec.md#sec-field-types); Template model fields (docs/Canonical_Spec.md#sec-template-model-fields); display_format_tel tokens (docs/Canonical_Spec.md#sec-display-format-tel), Anchors: None)
  - `Artifacts:` `eforms/src/Validation/FieldTypes/` (new dir), `eforms/src/Rendering/FieldRenderers/` (new dir), `eforms/src/Validation/FieldTypeRegistry.php` (modify), `eforms/src/Rendering/RendererRegistry.php` (modify)
  - `Interfaces:` Text-like and input-variant field descriptors (including `zip_us`, `zip`, `number`, `range`, `date`) and their default behaviors
  - `Tests:` `eforms/tests/unit/test_field_types_text_like.php` (new)
  - `Depends On:` Phase 1 — Implement internal registries (field types, validators, normalizers/coercers, renderers) with deterministic handler resolution
  - `Done When:` text-like built-ins resolve deterministically via registries; per-type defaults and attribute/hint emission match the spec; `eforms/tests/unit/test_field_types_text_like.php` passes

- [x] Implement built-in field types: textarea + long text semantics (Spec: Field types (docs/Canonical_Spec.md#sec-field-types); Template model fields (docs/Canonical_Spec.md#sec-template-model-fields), Anchors: None)
  - `Artifacts:` `eforms/src/Validation/FieldTypes/` (modify), `eforms/src/Rendering/FieldRenderers/` (modify), `eforms/src/Validation/FieldTypeRegistry.php` (modify), `eforms/src/Rendering/RendererRegistry.php` (modify)
  - `Interfaces:` Built-in textarea type defaults and validation/rendering rules
  - `Tests:` `eforms/tests/unit/test_field_types_textarea.php` (new)
  - `Depends On:` Phase 1 — Implement built-in field types: text-like inputs + tel formatting
  - `Done When:` textarea built-in resolves deterministically, applies spec-defined defaults/constraints, and renders required attributes; `eforms/tests/unit/test_field_types_textarea.php` passes

- [x] Implement built-in field types: choice/select/radio/checkbox + option semantics (Spec: Field types (docs/Canonical_Spec.md#sec-field-types); Template options (docs/Canonical_Spec.md#sec-template-options); Template model fields (docs/Canonical_Spec.md#sec-template-model-fields), Anchors: [MAX_OPTIONS_MAX], [MAX_MULTIVALUE_MAX])
  - `Artifacts:` `eforms/src/Validation/FieldTypes/` (modify), `eforms/src/Rendering/FieldRenderers/` (modify), `eforms/src/Validation/FieldTypeRegistry.php` (modify), `eforms/src/Rendering/RendererRegistry.php` (modify)
  - `Interfaces:` Choice field option schema, multivalue semantics, and rendering defaults
  - `Tests:` `eforms/tests/unit/test_field_types_choice.php` (new)
  - `Depends On:` Phase 1 — Implement built-in field types: textarea + long text semantics
  - `Done When:` choice built-ins enforce option semantics deterministically and render stable attributes; `eforms/tests/unit/test_field_types_choice.php` passes

- [x] Implement template semantic preflight (key uniqueness, reserved keys, handler IDs resolved) (Spec: Template model (docs/Canonical_Spec.md#sec-template-model); Template validation (docs/Canonical_Spec.md#sec-template-validation); Template model fields (docs/Canonical_Spec.md#sec-template-model-fields), Anchors: [MAX_FIELDS_MAX], [MAX_OPTIONS_MAX], [MAX_MULTIVALUE_MAX])
  - `Artifacts:` `eforms/src/Validation/TemplateValidator.php` (modify)
  - `Interfaces:` Template-level invariants (unique `fields[].key`, reserved keys, `include_fields` references, deterministic handler resolution)
  - `Tests:` `eforms/tests/unit/test_template_semantic_preflight.php` (new)
  - `Depends On:` Phase 1 — Implement template schema/envelope validation (unknown keys rejected); Phase 1 — Implement built-in field types: choice/select/radio/checkbox + option semantics
  - `Done When:` semantic invariants fail deterministically (including `include_fields` referencing unknown keys); handler IDs resolve to registered built-ins and unknown IDs surface stable configuration errors; `eforms/tests/unit/test_template_semantic_preflight.php` passes

- [x] Implement per-request `TemplateContext` with resolved descriptors and `has_uploads` flag (Spec: Template model (docs/Canonical_Spec.md#sec-template-model); Request lifecycle GET (docs/Canonical_Spec.md#sec-request-lifecycle-get), Anchors: [MAX_FIELDS_MAX], [MAX_OPTIONS_MAX], [MAX_MULTIVALUE_MAX])
  - `Artifacts:` `eforms/src/Rendering/TemplateContext.php` (new)
  - `Interfaces:` None
  - `Tests:` `eforms/tests/unit/test_template_context.php` (new)
  - `Depends On:` Phase 1 — Implement template semantic preflight (key uniqueness, reserved keys, handler IDs resolved)
  - `Done When:` renderer and validator can share the same resolved descriptor objects without re-merging on POST; `eforms/tests/unit/test_template_context.php` passes

- [x] Add unit tests: shipped fixtures preflight + registry completeness (Spec: Template validation (docs/Canonical_Spec.md#sec-template-validation); Templates to include (docs/Canonical_Spec.md#sec-templates-to-include); Central registries (docs/Canonical_Spec.md#sec-central-registries), Anchors: None)
  - `Artifacts:` `eforms/tests/unit/test_shipped_templates_preflight.php` (new)
  - `Interfaces:` Shipped JSON templates under `eforms/templates/forms/`
  - `Tests:` `eforms/tests/unit/test_shipped_templates_preflight.php` (new)
  - `Depends On:` Phase 1 — Implement template loader + JSON decoding + version gate; Phase 1 — Implement template schema/envelope validation (unknown keys rejected); Phase 1 — Implement internal registries (field types, validators, normalizers/coercers, renderers) with deterministic handler resolution
  - `Done When:` every shipped template passes TemplateValidator preflight; all declared types/handler IDs resolve deterministically; `eforms/tests/unit/test_shipped_templates_preflight.php` passes
  - `Verified via:` `eforms/tests/unit/test_shipped_templates_preflight.php`

- [x] Implement minimal logging mode with request correlation id (Spec: Logging (docs/Canonical_Spec.md#sec-logging), Anchors: [LOGGING_LEVEL_MIN], [LOGGING_LEVEL_MAX])
  - `Artifacts:` `eforms/src/Logging.php` (new)
  - `Interfaces:` `eforms_request_id` filter; request-id header resolution; stable log fields
  - `Tests:` `eforms/tests/unit/test_request_id_resolution.php` (new)
  - `Depends On:` Phase 0 — Implement config snapshot bootstrap + override sources
  - `Done When:` every emitted log event includes `request_id`; minimal logging redacts per spec; log emission is lazy and respects `logging.mode`; `eforms/tests/unit/test_request_id_resolution.php` passes

### Security (hidden-mode)

- [x] Implement storage health check and private-dir hardening for `${uploads.dir}/eforms-private/` (Spec: Shared lifecycle and storage contract (docs/Canonical_Spec.md#sec-shared-lifecycle); Cache-safety (docs/Canonical_Spec.md#sec-cache-safety); Security invariants (docs/Canonical_Spec.md#sec-security-invariants), Anchors: None)
  - `Artifacts:` `eforms/src/Security/StorageHealth.php` (new), `eforms/src/Uploads/PrivateDir.php` (new)
  - `Interfaces:` None
  - `Tests:` `eforms/tests/integration/test_storage_health_check.php` (new)
  - `Depends On:` Phase 0 — Implement `Helpers` primitives used across subsystems; Phase 0 — Implement config snapshot bootstrap + override sources
  - `Done When:` health check is memoized per request; failures prevent token minting and surface `EFORMS_ERR_STORAGE_UNAVAILABLE` per spec; `eforms/tests/integration/test_storage_health_check.php` passes
  - `Verified via:` `eforms/tests/integration/test_storage_health_check.php`

- [x] Implement hidden-mode token minting + record persistence (Spec: Hidden-mode contract (docs/Canonical_Spec.md#sec-hidden-mode); Security invariants (docs/Canonical_Spec.md#sec-security-invariants), Anchors: [TOKEN_TTL_MIN], [TOKEN_TTL_MAX])
  - `Artifacts:` `eforms/src/Security/Security.php` (new)
  - `Interfaces:` Hidden-mode token record storage and the hidden security inputs rendered into forms
  - `Tests:` `eforms/tests/integration/test_security_mint_hidden_record.php` (new)
  - `Depends On:` Phase 1 — Implement storage health check and private-dir hardening for `${uploads.dir}/eforms-private/`
  - `Done When:` minting persists token records atomically per storage contract; renderer embeds returned metadata without generating/altering it; `eforms/tests/integration/test_security_mint_hidden_record.php` passes
  - `Verified via:` `eforms/tests/integration/test_security_mint_hidden_record.php`

- [x] Implement `Security::token_validate` for hidden-mode, including tamper guards and `soft_reasons` production (Spec: Lifecycle quickstart (docs/Canonical_Spec.md#sec-lifecycle-quickstart); Security (docs/Canonical_Spec.md#sec-security); Origin policy (docs/Canonical_Spec.md#sec-origin-policy); Timing checks (docs/Canonical_Spec.md#sec-timing-checks); Spam decision (docs/Canonical_Spec.md#sec-spam-decision), Anchors: [TOKEN_TTL_MIN], [TOKEN_TTL_MAX], [MIN_FILL_SECONDS_MIN], [MIN_FILL_SECONDS_MAX], [MAX_FORM_AGE_MIN], [MAX_FORM_AGE_MAX])
  - `Artifacts:` `eforms/src/Security/Security.php` (modify), `eforms/src/Security/OriginPolicy.php` (new), `eforms/src/Security/TimingSignals.php` (new)
  - `Interfaces:` `soft_reasons` closed set; token failure codes
  - `Tests:` `eforms/tests/integration/test_token_validate_hidden_mode.php` (new)
  - `Depends On:` Phase 1 — Implement hidden-mode token minting + record persistence; Phase 1 — Implement minimal logging mode with request correlation id
  - `Done When:` security gate runs before normalize/validate; hard failures abort before side effects; `soft_reasons` is deduplicated and limited to the spec-defined closed set; `eforms/tests/integration/test_token_validate_hidden_mode.php` passes
  - `Verified via:` `eforms/tests/integration/test_token_validate_hidden_mode.php`

- [x] Implement POST size cap helper (effective cap calculation) (Spec: POST size cap (docs/Canonical_Spec.md#sec-post-size-cap); Configuration (docs/Canonical_Spec.md#sec-configuration), Anchors: None)
  - `Artifacts:` `eforms/src/Security/PostSize.php` (new)
  - `Interfaces:` None
  - `Tests:` `eforms/tests/unit/test_post_size_cap_calc.php` (new)
  - `Depends On:` Phase 0 — Implement config snapshot bootstrap + override sources
  - `Done When:` effective cap logic matches spec rules (includes INI cap handling) and `eforms/tests/unit/test_post_size_cap_calc.php` passes
  - `Verified via:` `eforms/tests/unit/test_post_size_cap_calc.php`

### Rendering (GET and rerender)

- [x] Implement `FormRenderer` GET render (hidden-mode) with duplicate form-id detection and `novalidate` behavior (Spec: Request lifecycle GET (docs/Canonical_Spec.md#sec-request-lifecycle-get); Success behavior (docs/Canonical_Spec.md#sec-success); Cache-safety (docs/Canonical_Spec.md#sec-cache-safety), Anchors: [TOKEN_TTL_MIN], [TOKEN_TTL_MAX])
  - `Reasoning:` **High** — Complex orchestration: template loading, security tokens, cache-safety headers, duplicate detection across request lifecycle
  - `Artifacts:` `eforms/src/Rendering/FormRenderer.php` (new), `eforms/src/bootstrap.php` (modify)
  - `Interfaces:` `[eform]`, `eform_render($slug, $opts)`
  - `Tests:` `eforms/tests/integration/test_renderer_get_hidden_mode.php` (new), `eforms/tests/integration/test_cache_safety_hidden_mode_headers_sent.php` (new), `eforms/tests/smoke/test_bootstrap.php` (modify)
  - `Depends On:` Phase 1 — Implement per-request `TemplateContext` with resolved descriptors and `has_uploads` flag; Phase 1 — Implement hidden-mode token minting + record persistence
  - `Done When:` GET render loads templates, preflights them, enqueues assets only when a form is present, rejects duplicate form IDs deterministically, and emits hidden-mode security inputs by delegating minting to `Security`; cache-safety headers are applied on responses that embed hidden-mode security inputs; when headers are already sent, GET render fails closed and surfaces `EFORMS_ERR_STORAGE_UNAVAILABLE` without minting; both integration tests pass
  - `Verified via:` `eforms/tests/integration/test_renderer_get_hidden_mode.php`, `eforms/tests/integration/test_cache_safety_hidden_mode_headers_sent.php`, `eforms/tests/smoke/test_bootstrap.php`

- [x] Honor `assets.css_disable` (Spec: Assets (docs/Canonical_Spec.md#sec-assets); Configuration (docs/Canonical_Spec.md#sec-configuration), Anchors: None)
  - `Reasoning:` **Low** — Simple config flag check; single conditional for CSS enqueue
  - `Artifacts:` `eforms/src/Rendering/FormRenderer.php` (modify)
  - `Interfaces:` `assets.css_disable`
  - `Tests:` `eforms/tests/integration/test_assets_css_disable.php` (new)
  - `Depends On:` Phase 1 — Implement `FormRenderer` GET render (hidden-mode) with duplicate form-id detection and `novalidate` behavior; Phase 0 — Implement config snapshot bootstrap + override sources
  - `Done When:` when `assets.css_disable=true`, plugin CSS is not enqueued; JS enqueue behavior remains spec-compliant (JS-minted mode still works); `eforms/tests/integration/test_assets_css_disable.php` passes
  - `Verified via:` `eforms/tests/integration/test_assets_css_disable.php`

- [x] Implement row-group wrapper emission and HTML fragment enforcement rules (Spec: Row groups (docs/Canonical_Spec.md#sec-template-row-groups); HTML-bearing fields (docs/Canonical_Spec.md#sec-html-fields); Template model (docs/Canonical_Spec.md#sec-template-model), Anchors: [MAX_FIELDS_MAX], [MAX_OPTIONS_MAX])
  - `Reasoning:` **Medium** — Balanced wrapper emission requires careful tree tracking; HTML sanitization has security implications
  - `Artifacts:` `eforms/src/Rendering/FormRenderer.php` (modify), `eforms/src/Validation/TemplateValidator.php` (modify)
  - `Interfaces:` Template JSON `row_group` pseudo-fields; `before_html` / `after_html`
  - `Tests:` `eforms/tests/unit/test_row_groups_balance.php` (new), `eforms/tests/unit/test_html_fragment_sanitization.php` (new)
  - `Depends On:` Phase 1 — Implement template schema/envelope validation (unknown keys rejected); Phase 1 — Implement `FormRenderer` GET render (hidden-mode) with duplicate form-id detection and `novalidate` behavior
  - `Done When:` renderer emits balanced wrappers without auto-closing; TemplateValidator enforces HTML fragment constraints (sanitization and any rejection rules defined by the spec); both unit tests pass
  - `Verified via:` `eforms/tests/unit/test_row_groups_balance.php`, `eforms/tests/unit/test_html_fragment_sanitization.php`

- [x] Implement accessibility + error rendering contract (Spec: Accessibility (docs/Canonical_Spec.md#sec-accessibility); Assets (docs/Canonical_Spec.md#sec-assets), Anchors: None)
  - `Reasoning:` **Medium** — Coordinated changes across PHP renderer, JS, and CSS with ARIA semantics
  - `Artifacts:` `eforms/src/Rendering/FormRenderer.php` (modify), `eforms/assets/forms.js` (modify), `eforms/assets/forms.css` (modify)
  - `Interfaces:` Error summary markup + focus behavior; ARIA attributes; per-field error rendering
  - `Tests:` `eforms/tests/integration/test_accessibility_error_summary.php` (new)
  - `Depends On:` Phase 1 — Implement built-in field types: choice/select/radio/checkbox + option semantics; Phase 1 — Implement `FormRenderer` GET render (hidden-mode) with duplicate form-id detection and `novalidate` behavior
  - `Done When:` error summary and per-field errors render per spec; JS focus/UX behavior follows spec; `eforms/tests/integration/test_accessibility_error_summary.php` passes
  - `Verified via:` `eforms/tests/integration/test_accessibility_error_summary.php`

### Submission pipeline (POST)

- [x] Implement Normalize stage (pure + deterministic) (Spec: Validation pipeline (docs/Canonical_Spec.md#sec-validation-pipeline), Anchors: [MAX_FIELDS_MAX])
  - `Reasoning:` **Medium** — Pure/deterministic function; careful attention to option semantics and edge cases
  - `Artifacts:` `eforms/src/Validation/Normalizer.php` (new)
  - `Interfaces:` Field-type normalization rules and option semantics (no rejection in Normalize stage)
  - `Tests:` `eforms/tests/unit/test_normalize_stage.php` (new)
  - `Depends On:` Phase 1 — Implement built-in field types: choice/select/radio/checkbox + option semantics
  - `Done When:` given identical inputs, Normalize emits identical canonical values and emits no hard failures; `eforms/tests/unit/test_normalize_stage.php` passes
  - `Verified via:` `eforms/tests/unit/test_normalize_stage.php`

- [x] Implement Validate stage (errors + deterministic ordering) (Spec: Validation pipeline (docs/Canonical_Spec.md#sec-validation-pipeline); Template validation (docs/Canonical_Spec.md#sec-template-validation); Cross-field rules (docs/Canonical_Spec.md#sec-cross-field-rules), Anchors: [MAX_FIELDS_MAX], [MAX_OPTIONS_MAX], [MAX_MULTIVALUE_MAX])
  - `Reasoning:` **High** — Cross-field rules, deterministic error ordering, stable error surface; high spec compliance pressure
  - `Artifacts:` `eforms/src/Validation/Validator.php` (new)
  - `Interfaces:` Field validation rules, cross-field rules, and stable error ordering contract
  - `Tests:` `eforms/tests/unit/test_validation_determinism.php` (new), `eforms/tests/unit/test_cross_field_rules.php` (new)
  - `Depends On:` Phase 1 — Implement Normalize stage (pure + deterministic)
  - `Done When:` errors are stable/deterministic for identical inputs; cross-field rules match the spec; both unit tests pass
  - `Verified via:` `eforms/tests/unit/test_validation_determinism.php`, `eforms/tests/unit/test_cross_field_rules.php`

- [x] Implement Coerce stage (post-validate canonicalization) (Spec: Validation pipeline (docs/Canonical_Spec.md#sec-validation-pipeline), Anchors: [MAX_FIELDS_MAX])
  - `Reasoning:` **Low** — Straightforward post-validate type coercion
  - `Artifacts:` `eforms/src/Validation/Coercer.php` (new)
  - `Interfaces:` Type coercion rules defined by the spec (post-validate)
  - `Tests:` `eforms/tests/unit/test_coerce_stage.php` (new)
  - `Depends On:` Phase 1 — Implement Validate stage (errors + deterministic ordering)
  - `Done When:` Coerce produces canonical typed output for downstream side effects; `eforms/tests/unit/test_coerce_stage.php` passes
  - `Verified via:` `eforms/tests/unit/test_coerce_stage.php`

- [x] Implement SubmitHandler POST orchestration (security gate → normalize/validate/coerce → ledger → side effects → success) (Spec: Request lifecycle POST (docs/Canonical_Spec.md#sec-request-lifecycle-post); Validation pipeline (docs/Canonical_Spec.md#sec-validation-pipeline); Security (docs/Canonical_Spec.md#sec-security), Anchors: [TOKEN_TTL_MAX])
  - `Reasoning:` **High** — Core pipeline ordering per Spec_Digest invariants; security gate → ledger → side effects sequencing is critical
  - `Artifacts:` `eforms/src/Submission/SubmitHandler.php` (new), `eforms/src/Submission/RequestContext.php` (new, optional)
  - `Interfaces:` POST handler for submissions; stable error rerender behavior
  - `Tests:` `eforms/tests/integration/test_post_pipeline_ordering.php` (new), `eforms/tests/integration/test_post_size_cap.php` (new)
  - `Depends On:` Phase 1 — Implement POST size cap helper (effective cap calculation); Phase 1 — Implement `Security::token_validate` for hidden-mode, including tamper guards and `soft_reasons` production; Phase 1 — Implement Coerce stage (post-validate canonicalization)
  - `Done When:` orchestration order matches `docs/Spec_Digest.md`; post-size cap is enforced before side effects; both integration tests pass
  - `Verified via:` `eforms/tests/integration/test_post_pipeline_ordering.php`, `eforms/tests/integration/test_post_size_cap.php`

- [x] Implement honeypot behavior (stealth vs hard-fail) including ledger burn rule (Spec: Honeypot (docs/Canonical_Spec.md#sec-honeypot); Security (docs/Canonical_Spec.md#sec-security), Anchors: [TOKEN_TTL_MAX])
  - `Reasoning:` **Medium** — Stealth vs hard-fail logic; ledger burn integration
  - `Artifacts:` `eforms/src/Security/Honeypot.php` (new), `eforms/src/Submission/SubmitHandler.php` (modify)
  - `Interfaces:` Fixed POST name `eforms_hp` and spec-defined response behavior
  - `Tests:` `eforms/tests/integration/test_honeypot_paths.php` (new)
  - `Depends On:` Phase 1 — Implement SubmitHandler POST orchestration (security gate → normalize/validate/coerce → ledger → side effects → success)
  - `Done When:` honeypot short-circuits before validation/email; ledger burn is attempted only when `token_ok=true`; `eforms/tests/integration/test_honeypot_paths.php` passes
  - `Verified via:` `eforms/tests/integration/test_honeypot_paths.php`

- [x] Implement ledger reservation contract and duplicate-submission behavior (Spec: Ledger reservation contract (docs/Canonical_Spec.md#sec-ledger-contract); Security invariants (docs/Canonical_Spec.md#sec-security-invariants), Anchors: [TOKEN_TTL_MAX], [LEDGER_GC_GRACE_SECONDS])
  - `Reasoning:` **High** — Concurrency semantics (EEXIST=duplicate), atomic file ops, GC coordination
  - `Handoff Required:` yes — include EEXIST duplicate semantics, atomic exclusive-create rationale, and how to reproduce concurrency-test failures
  - `Artifacts:` `eforms/src/Submission/Ledger.php` (new)
  - `Interfaces:` Ledger marker pathing and duplicate semantics (EEXIST treated as duplicate)
  - `Tests:` `eforms/tests/integration/test_ledger_reserve_semantics.php` (new)
  - `Depends On:` Phase 1 — Implement SubmitHandler POST orchestration (security gate → normalize/validate/coerce → ledger → side effects → success)
  - `Done When:` reservation is performed immediately before side effects; non-EEXIST IO failures surface `EFORMS_ERR_LEDGER_IO` and are logged per spec; `eforms/tests/integration/test_ledger_reserve_semantics.php` passes
  - `Verified via:` `eforms/tests/integration/test_ledger_reserve_semantics.php`

- [x] Implement email delivery core (no uploads yet) and email-failure rerender contract (Spec: Email delivery (docs/Canonical_Spec.md#sec-email); Email templates (docs/Canonical_Spec.md#sec-email-templates); Templates to include (docs/Canonical_Spec.md#sec-templates-to-include); Email-failure recovery (docs/Canonical_Spec.md#sec-email-failure-recovery); Hidden-mode email-failure recovery (docs/Canonical_Spec.md#sec-hidden-email-failure); Error handling (docs/Canonical_Spec.md#sec-error-handling); Request lifecycle POST (docs/Canonical_Spec.md#sec-request-lifecycle-post), Anchors: [TOKEN_TTL_MAX])
  - `Reasoning:` **High** — Header sanitization, Reply-To precedence, template token expansion; failure recovery with token reminting
  - `Handoff Required:` yes — include email header sanitization rules, token reminting behavior (hidden vs JS-minted), and how to reproduce email-failure rerender tests
  - `Artifacts:` `eforms/src/Email/Emailer.php` (new), `eforms/src/Email/Templates.php` (new)
  - `Interfaces:` Template `email` block contract; `wp_mail()` usage; stable email-failure behavior
  - `Tests:` `eforms/tests/integration/test_email_headers_sanitization.php` (new), `eforms/tests/integration/test_email_failure_rerender.php` (new)
  - `Depends On:` Phase 1 — Implement ledger reservation contract and duplicate-submission behavior; Phase 1 — Implement minimal logging mode with request correlation id
  - `Done When:` email assembly follows spec rules (header sanitization, Reply-To precedence, template token expansion); on send failure the pipeline rerenders with a fresh token per mode contract and logs required fields; both integration tests pass
  - `Verified via:` `eforms/tests/integration/test_email_headers_sanitization.php`, `eforms/tests/integration/test_email_failure_rerender.php`

- [x] Implement suspect handling signaling (headers + subject tagging) (Spec: Suspect handling (docs/Canonical_Spec.md#sec-suspect-handling); Spam decision (docs/Canonical_Spec.md#sec-spam-decision), Anchors: None)
  - `Reasoning:` **Low** — Header/subject modification; minimal logic
  - `Artifacts:` `eforms/src/Submission/SubmitHandler.php` (modify), `eforms/src/Email/Emailer.php` (modify)
  - `Interfaces:` Response headers (`X-EForms-Soft-Fails`, `X-EForms-Suspect`) and any spec-defined subject tagging behavior
  - `Tests:` `eforms/tests/integration/test_suspect_signaling.php` (new)
  - `Depends On:` Phase 1 — Implement `Security::token_validate` for hidden-mode, including tamper guards and `soft_reasons` production; Phase 1 — Implement email delivery core (no uploads yet) and email-failure rerender contract
  - `Done When:` suspect/soft-fail signaling matches the spec across success + rerender responses; `eforms/tests/integration/test_suspect_signaling.php` passes
  - `Verified via:` `eforms/tests/integration/test_suspect_signaling.php`

- [x] Implement PRG success behavior (inline vs redirect) and cache-safety headers for success URLs (Spec: Success behavior (docs/Canonical_Spec.md#sec-success); Success modes (docs/Canonical_Spec.md#sec-success-modes); Inline success flow (docs/Canonical_Spec.md#sec-success-flow); Redirect safety (docs/Canonical_Spec.md#sec-redirect-safety); Cache-safety (docs/Canonical_Spec.md#sec-cache-safety), Anchors: None)
  - `Reasoning:` **Medium** — Inline vs redirect modes; cache-safety enforcement on success paths
  - `Artifacts:` `eforms/src/Submission/Success.php` (new), `eforms/src/Rendering/FormRenderer.php` (modify)
  - `Interfaces:` `success.mode`, `?eforms_success={form_id}` query arg behavior, redirects via `wp_safe_redirect`
  - `Tests:` `eforms/tests/integration/test_success_inline_flow.php` (new), `eforms/tests/integration/test_success_redirect_flow.php` (new)
  - `Depends On:` Phase 1 — Implement email delivery core (no uploads yet) and email-failure rerender contract
  - `Done When:` success responses satisfy cache-safety rules; inline success is rejected when combined with cacheable render per spec; both integration tests pass
  - `Verified via:` `eforms/tests/integration/test_success_inline_flow.php`, `eforms/tests/integration/test_success_redirect_flow.php`

---

## Phase 2 — Cacheable pages (JS-minted mode + `/eforms/mint`)

**Goals**
- Implement JS-minted mode end-to-end, including the REST mint endpoint and client-side token injection.

**Non-Goals**
- Upload storage and email attachments.

**Acceptance**
- `/eforms/mint` matches the contract (method, content type, cache headers, cookie/CORS prohibitions, status/error surface).

### Work items

- [x] Implement `/eforms/mint` endpoint contract (Spec: JS-minted mode contract (docs/Canonical_Spec.md#sec-js-mint-mode); Throttling (docs/Canonical_Spec.md#sec-throttling); Security (docs/Canonical_Spec.md#sec-security), Anchors: [TOKEN_TTL_MIN], [TOKEN_TTL_MAX], [THROTTLE_MAX_PER_MIN_MIN], [THROTTLE_MAX_PER_MIN_MAX], [THROTTLE_COOLDOWN_MIN], [THROTTLE_COOLDOWN_MAX])
  - `Reasoning:` **High** — REST contract: method, content-type, cache headers, cookie/CORS prohibitions, throttle integration
  - `Artifacts:` `eforms/src/Security/MintEndpoint.php` (new), `eforms/src/Security/Security.php` (modify), `eforms/src/bootstrap.php` (modify)
  - `Interfaces:` `POST /eforms/mint` REST endpoint (JSON-only responses)
  - `Tests:` `eforms/tests/integration/test_mint_endpoint_contract.php` (new)
  - `Depends On:` Phase 1 — Implement storage health check and private-dir hardening for `${uploads.dir}/eforms-private/`; Phase 1 — Implement POST size cap helper (effective cap calculation); Phase 0 — Implement config snapshot bootstrap + override sources; Phase 0 — Implement `Helpers` primitives used across subsystems
  - `Done When:` endpoint matches the spec contract (method, content type, cache headers, cookie/CORS prohibitions, status/error surface); post-size cap is enforced; `eforms/tests/integration/test_mint_endpoint_contract.php` passes
  - `Verified via:` `eforms/tests/integration/test_mint_endpoint_contract.php`

- [x] Implement JS-minted token injection + remint behavior in `forms.js` (Spec: Assets (docs/Canonical_Spec.md#sec-assets); JS-minted email-failure recovery (docs/Canonical_Spec.md#sec-js-email-failure); JS-minted mode contract (docs/Canonical_Spec.md#sec-js-mint-mode), Anchors: [TOKEN_TTL_MAX])
  - `Reasoning:` **Medium** — Client-side injection logic; remint marker handling; moderate complexity but mostly isolated
  - `Artifacts:` `eforms/assets/forms.js` (new), `eforms/src/Rendering/FormRenderer.php` (modify)
  - `Interfaces:` JS-minted mode form markup; `data-eforms-remint` marker
  - `Tests:` `eforms/tests/e2e/test_js_minted_injection.md` (new, manual script)
  - `Depends On:` Phase 2 — Implement `/eforms/mint` endpoint contract; Phase 1 — Implement `FormRenderer` GET render (hidden-mode) with duplicate form-id detection and `novalidate` behavior
  - `Done When:` JS injects token metadata only into empty hidden fields, blocks submission until mint succeeds, and performs remint flow when marker is present; manual script steps are complete and produce expected results

- [ ] Enforce mixed-mode page behavior in client + server (Spec: Assets (docs/Canonical_Spec.md#sec-assets); Submission protection (docs/Canonical_Spec.md#sec-submission-protection); Security invariants (docs/Canonical_Spec.md#sec-security-invariants), Anchors: [TOKEN_TTL_MAX])
  - `Reasoning:` **Medium** — Coexistence rules for hidden + JS-minted forms on same page
  - `Artifacts:` `eforms/assets/forms.js` (modify), `eforms/src/Rendering/FormRenderer.php` (modify)
  - `Interfaces:` Coexistence of hidden-mode and JS-minted forms on one page
  - `Tests:` `eforms/tests/e2e/test_mixed_mode_page.md` (new, manual script)
  - `Depends On:` Phase 2 — Implement JS-minted token injection + remint behavior in `forms.js`
  - `Done When:` JS calls `/eforms/mint` only for cacheable forms; renderer does not allow client-driven mode selection; duplicate form IDs remain a hard configuration error; manual script steps are complete and produce expected results

---

## Phase 3 — Uploads + GC

**Goals**
- Implement upload validation/storage, email attachments, and operator-driven garbage collection.

**Non-Goals**
- Expanding accept-token policy beyond what the spec defines.

**Acceptance**
- Upload lifecycle matches the digest invariants: uploads remain in temp until ledger reserve succeeds; GC removes expired artifacts per the spec.

### Work items

- [ ] Implement upload accept-token policy and upload validation (Spec: Uploads accept-token policy (docs/Canonical_Spec.md#sec-uploads-accept-tokens); Default accept tokens callout (docs/Canonical_Spec.md#sec-uploads-accept-defaults); Validation pipeline (docs/Canonical_Spec.md#sec-validation-pipeline); Uploads (docs/Canonical_Spec.md#sec-uploads), Anchors: [MAX_FIELDS_MAX], [MAX_OPTIONS_MAX])
  - `Reasoning:` **High** — MIME validation, intersection rules, security-sensitive file handling
  - `Artifacts:` `eforms/src/Uploads/UploadPolicy.php` (new), `eforms/src/Validation/Validator.php` (modify)
  - `Interfaces:` Template upload descriptors (`file` / `files`) and their per-field overrides
  - `Tests:` `eforms/tests/integration/test_upload_accept_tokens.php` (new)
  - `Depends On:` Phase 1 — Implement Validate stage (errors + deterministic ordering); Phase 1 — Implement storage health check and private-dir hardening for `${uploads.dir}/eforms-private/`
  - `Done When:` accept-token intersection rules are enforced; unsupported types fail with the stable upload error codes; upload attempts fail deterministically when required server capabilities are unavailable; `eforms/tests/integration/test_upload_accept_tokens.php` passes

- [ ] Implement upload storage, move-after-ledger, and retention hooks (Spec: Uploads filename policy (docs/Canonical_Spec.md#sec-uploads-filenames); Ledger reservation contract (docs/Canonical_Spec.md#sec-ledger-contract); Uploads (docs/Canonical_Spec.md#sec-uploads), Anchors: [TOKEN_TTL_MAX], [LEDGER_GC_GRACE_SECONDS])
  - `Reasoning:` **High** — Atomic file ops, collision handling, temp→private path timing
  - `Artifacts:` `eforms/src/Uploads/UploadStore.php` (new), `eforms/src/Submission/SubmitHandler.php` (modify)
  - `Interfaces:` Private uploads directory layout under `${uploads.dir}/eforms-private/`
  - `Tests:` `eforms/tests/integration/test_upload_move_after_ledger.php` (new)
  - `Depends On:` Phase 3 — Implement upload accept-token policy and upload validation; Phase 1 — Implement ledger reservation contract and duplicate-submission behavior
  - `Done When:` files are never moved into private storage before ledger reservation; collisions are treated as internal errors (no overwrites); `eforms/tests/integration/test_upload_move_after_ledger.php` passes

- [ ] Implement email attachments policy (Spec: Email delivery (docs/Canonical_Spec.md#sec-email); Uploads (docs/Canonical_Spec.md#sec-uploads), Anchors: None)
  - `Reasoning:` **Medium** — Bounded attachments, overflow handling
  - `Artifacts:` `eforms/src/Email/Emailer.php` (modify)
  - `Interfaces:` `email_attach` per upload descriptor; include_fields behavior for upload keys
  - `Tests:` `eforms/tests/integration/test_email_attachments_policy.php` (new)
  - `Depends On:` Phase 3 — Implement upload storage, move-after-ledger, and retention hooks; Phase 1 — Implement email delivery core (no uploads yet) and email-failure rerender contract
  - `Done When:` attachments are bounded per spec; overflow is summarized in the email body; temp upload paths are never persisted or logged; `eforms/tests/integration/test_email_attachments_policy.php` passes

- [ ] Implement `wp eforms gc` for tokens, ledger markers, uploads, and throttle state (Spec: Uploads (docs/Canonical_Spec.md#sec-uploads); Throttling (docs/Canonical_Spec.md#sec-throttling); Anchors (docs/Canonical_Spec.md#sec-anchors), Anchors: [TOKEN_TTL_MAX], [LEDGER_GC_GRACE_SECONDS])
  - `Reasoning:` **High** — Idempotency, single-run locking, cross-subsystem cleanup (tokens, ledger, uploads, throttle)
  - `Artifacts:` `eforms/src/Cli/GcCommand.php` (new), `eforms/src/Gc/GcRunner.php` (new)
  - `Interfaces:` `wp eforms gc`
  - `Tests:` `eforms/tests/integration/test_gc_dry_run.php` (new)
  - `Depends On:` Phase 3 — Implement upload storage, move-after-ledger, and retention hooks; Phase 1 — Implement ledger reservation contract and duplicate-submission behavior
  - `Done When:` GC is idempotent, locked to a single run, supports dry-run reporting, and deletes only artifacts eligible under the spec’s timing/eligibility rules; `eforms/tests/integration/test_gc_dry_run.php` passes

---

## Phase 4 — Optional defenses + full observability

**Goals**
- Implement optional throttling, adaptive challenge, and structured logging/Fail2ban emission.

**Non-Goals**
- Adding new providers or new configuration surfaces not already in the spec.

**Acceptance**
- Optional features are capability-gated and default OFF, and do not change baseline behavior unless enabled by config.

### Work items

- [ ] Implement file-based throttling including `Retry-After` calculation and entrypoint semantics (Spec: Throttling (docs/Canonical_Spec.md#sec-throttling); Security (docs/Canonical_Spec.md#sec-security), Anchors: [THROTTLE_MAX_PER_MIN_MIN], [THROTTLE_MAX_PER_MIN_MAX], [THROTTLE_COOLDOWN_MIN], [THROTTLE_COOLDOWN_MAX])
  - `Reasoning:` **High** — Rate limiting with Retry-After, file-lock semantics, entrypoint-specific behavior
  - `Artifacts:` `eforms/src/Security/Throttle.php` (new), `eforms/src/Security/Security.php` (modify)
  - `Interfaces:` POST submit throttling behavior; `/eforms/mint` throttling behavior
  - `Tests:` `eforms/tests/integration/test_throttle_retry_after.php` (new)
  - `Depends On:` Phase 2 — Implement `/eforms/mint` endpoint contract; Phase 1 — Implement SubmitHandler POST orchestration (security gate → normalize/validate/coerce → ledger → side effects → success)
  - `Done When:` throttle runs before minting and before normalize/validate on POST; lock-failure behavior matches the spec; only throttle impl + GC touch throttle files; `eforms/tests/integration/test_throttle_retry_after.php` passes

- [ ] Implement adaptive challenge render + verify flow (Turnstile provider) (Spec: Adaptive challenge (docs/Canonical_Spec.md#sec-adaptive-challenge); Validation pipeline (docs/Canonical_Spec.md#sec-validation-pipeline), Anchors: [CHALLENGE_TIMEOUT_MIN], [CHALLENGE_TIMEOUT_MAX])
  - `Reasoning:` **High** — External provider integration, render-on-rerender-only constraint, verification timing
  - `Artifacts:` `eforms/src/Security/Challenge.php` (new), `eforms/src/Security/Security.php` (modify), `eforms/src/Rendering/FormRenderer.php` (modify)
  - `Interfaces:` challenge mode/provider config; challenge render-on-rerender contract
  - `Tests:` `eforms/tests/integration/test_challenge_rerender_only.php` (new)
  - `Depends On:` Phase 4 — Implement file-based throttling including `Retry-After` calculation and entrypoint semantics; Phase 1 — Implement SubmitHandler POST orchestration (security gate → normalize/validate/coerce → ledger → side effects → success)
  - `Done When:` challenge is never rendered on initial GET; verification happens before ledger reserve; unconfigured required challenge fails with the spec-defined deterministic error; `eforms/tests/integration/test_challenge_rerender_only.php` passes

- [ ] Implement JSONL logging mode + retention/rotation + fail2ban emission channel (Spec: Logging (docs/Canonical_Spec.md#sec-logging); Configuration (docs/Canonical_Spec.md#sec-configuration), Anchors: [LOGGING_LEVEL_MIN], [LOGGING_LEVEL_MAX], [RETENTION_DAYS_MIN], [RETENTION_DAYS_MAX])
  - `Reasoning:` **Medium** — Schema evolution (append-only), privacy rules, desc_sha1 fingerprinting
  - `Artifacts:` `eforms/src/Logging/JsonlLogger.php` (new), `eforms/src/Logging/Fail2banLogger.php` (new), `eforms/src/Logging.php` (modify)
  - `Interfaces:` `logging.mode=jsonl`, `logging.fail2ban.*` outputs
  - `Tests:` `eforms/tests/integration/test_logging_jsonl_schema.php` (new), `eforms/tests/integration/test_fail2ban_line_format.php` (new), `eforms/tests/integration/test_logging_desc_sha1.php` (new)
  - `Depends On:` Phase 1 — Implement minimal logging mode with request correlation id; Phase 0 — Implement config numeric clamping via named Anchors
  - `Done When:` JSONL/minimal/fail2ban sinks honor privacy rules; retention pruning obeys config clamps; when `logging.level=2`, emitted events include the per-request `desc_sha1` descriptor fingerprint per spec; machine-readable schemas evolve append-only; all integration tests pass

- [ ] Implement privacy client-IP resolution and presentation rules (Spec: Privacy and IP handling (docs/Canonical_Spec.md#sec-privacy); Throttling (docs/Canonical_Spec.md#sec-throttling); Logging (docs/Canonical_Spec.md#sec-logging), Anchors: None)
  - `Reasoning:` **Medium** — Trusted proxy rules, split between resolution and presentation
  - `Artifacts:` `eforms/src/Privacy/ClientIp.php` (new)
  - `Interfaces:` `privacy.*` config affecting log/email IP presentation
  - `Tests:` `eforms/tests/unit/test_client_ip_resolution.php` (new)
  - `Depends On:` Phase 4 — Implement file-based throttling including `Retry-After` calculation and entrypoint semantics; Phase 4 — Implement JSONL logging mode + retention/rotation + fail2ban emission channel
  - `Done When:` resolved client IP is derived per trusted-proxy rules; throttle and fail2ban use resolved IP regardless of presentation mode; emails/logs honor presentation mode; `eforms/tests/unit/test_client_ip_resolution.php` passes

- [ ] Implement uninstall purge behavior (Spec: Architecture and file layout (docs/Canonical_Spec.md#sec-architecture); Configuration (docs/Canonical_Spec.md#sec-configuration), Anchors: None)
  - `Reasoning:` **Low** — Config-driven purge flags; straightforward conditional cleanup
  - `Artifacts:` `eforms/uninstall.php` (new)
  - `Interfaces:` uninstall behavior (purge flags)
  - `Tests:` `eforms/tests/integration/test_uninstall_purge_flags.php` (new)
  - `Depends On:` Phase 0 — Implement config snapshot bootstrap + override sources
  - `Done When:` uninstall reads purge flags via Config bootstrap as specified; deletes only what the spec allows when flags are enabled; `eforms/tests/integration/test_uninstall_purge_flags.php` passes

---

## Test plan (mapping invariants → tests)

- `docs/Spec_Digest.md` prohibitions and ordering → `eforms/tests/integration/` suites for security gate ordering, ledger reservation placement, and side-effects gating.
- Anchored clamp invariants in `docs/Canonical_Spec.md#sec-anchors` → `eforms/tests/unit/test_config_bootstrap.php`.
- Hidden-mode lifecycle (render/mint/POST/rerender/success) → `eforms/tests/integration/test_renderer_get_hidden_mode.php`, `eforms/tests/integration/test_token_validate_hidden_mode.php`, `eforms/tests/integration/test_success_*`.
- Cache-safety edge: hidden-mode refuses to embed tokens when headers are already sent → `eforms/tests/integration/test_cache_safety_hidden_mode_headers_sent.php`.
- JS-minted lifecycle (`/eforms/mint`, client injection, remint marker) → `eforms/tests/integration/test_mint_endpoint_contract.php` + manual E2E scripts under `eforms/tests/e2e/`.
- Upload lifecycle + GC eligibility → `eforms/tests/integration/test_upload_*` + `eforms/tests/integration/test_gc_dry_run.php`.
- Assets opt-out: `assets.css_disable` honored → `eforms/tests/integration/test_assets_css_disable.php`.
- Logging level 2: `desc_sha1` emitted → `eforms/tests/integration/test_logging_desc_sha1.php`.

---

## Minimal integration test matrix (high-signal flows)

- Hidden-mode end-to-end: GET render → token mint/persist → POST → PRG success (Spec: Request lifecycle GET (docs/Canonical_Spec.md#sec-request-lifecycle-get); Request lifecycle POST (docs/Canonical_Spec.md#sec-request-lifecycle-post); Success behavior (docs/Canonical_Spec.md#sec-success), Anchors: [TOKEN_TTL_MIN], [TOKEN_TTL_MAX])
  - Verified via: `eforms/tests/integration/test_renderer_get_hidden_mode.php`, `eforms/tests/integration/test_security_mint_hidden_record.php`, `eforms/tests/integration/test_post_pipeline_ordering.php`, `eforms/tests/integration/test_success_inline_flow.php`
- Duplicate prevention: repeat POST with same submission identity is handled deterministically (Spec: Ledger reservation contract (docs/Canonical_Spec.md#sec-ledger-contract); Security invariants (docs/Canonical_Spec.md#sec-security-invariants), Anchors: [TOKEN_TTL_MAX], [LEDGER_GC_GRACE_SECONDS])
  - Verified via: `eforms/tests/integration/test_ledger_reserve_semantics.php`
- Rerender invariants: validation/challenge rerender preserves required state; email-failure recovery follows mode-specific behavior (Spec: Validation pipeline (docs/Canonical_Spec.md#sec-validation-pipeline); Adaptive challenge (docs/Canonical_Spec.md#sec-adaptive-challenge); Email-failure recovery (docs/Canonical_Spec.md#sec-email-failure-recovery), Anchors: [TOKEN_TTL_MAX])
  - Verified via: `eforms/tests/integration/test_email_failure_rerender.php`, `eforms/tests/integration/test_challenge_rerender_only.php`
- Cacheable pages + JS-minted mode: GET renders without embedded secrets; client mints; server enforces `/eforms/mint` contract (Spec: JS-minted mode contract (docs/Canonical_Spec.md#sec-js-mint-mode); Cache-safety (docs/Canonical_Spec.md#sec-cache-safety); Origin policy (docs/Canonical_Spec.md#sec-origin-policy), Anchors: None)
  - Verified via: `eforms/tests/integration/test_mint_endpoint_contract.php` + manual E2E scripts under `eforms/tests/e2e/`
- Uploads lifecycle: accept-token policy → validate → move-after-ledger → email attachments → GC eligibility (Spec: Uploads accept-token policy (docs/Canonical_Spec.md#sec-uploads-accept-tokens); Uploads (docs/Canonical_Spec.md#sec-uploads), Anchors: [TOKEN_TTL_MAX], [LEDGER_GC_GRACE_SECONDS])
  - Verified via: `eforms/tests/integration/test_upload_accept_tokens.php`, `eforms/tests/integration/test_upload_move_after_ledger.php`, `eforms/tests/integration/test_email_attachments_policy.php`, `eforms/tests/integration/test_gc_dry_run.php`
- Optional defenses: throttle and challenge enforce entrypoint-specific behavior when enabled (Spec: Throttling (docs/Canonical_Spec.md#sec-throttling); Adaptive challenge (docs/Canonical_Spec.md#sec-adaptive-challenge), Anchors: [THROTTLE_MAX_PER_MIN_MIN], [THROTTLE_MAX_PER_MIN_MAX], [THROTTLE_COOLDOWN_MIN], [THROTTLE_COOLDOWN_MAX])
  - Verified via: `eforms/tests/integration/test_throttle_retry_after.php`, `eforms/tests/integration/test_challenge_rerender_only.php`
- Observability/privacy: request correlation and privacy rules hold across log sinks (Spec: Logging (docs/Canonical_Spec.md#sec-logging); Privacy and IP handling (docs/Canonical_Spec.md#sec-privacy), Anchors: [LOGGING_LEVEL_MIN], [LOGGING_LEVEL_MAX], [RETENTION_DAYS_MIN], [RETENTION_DAYS_MAX])
  - Verified via: `eforms/tests/unit/test_request_id_resolution.php`, `eforms/tests/integration/test_logging_jsonl_schema.php`, `eforms/tests/integration/test_fail2ban_line_format.php`, `eforms/tests/unit/test_client_ip_resolution.php`

---

## Delivery checklist (done means)

- Every public surface in `docs/Canonical_Spec.md#sec-objective` is implemented and smoke-exercised (shortcode/template tag, REST surface, CLI surface, uninstall behavior).
- Deterministic error behavior holds: stable error-code surface is append-only, error ordering is deterministic, and fail-closed paths do not white-screen (Spec: Error handling (docs/Canonical_Spec.md#sec-error-handling), Anchors: None).
- Spec digest invariants are upheld end-to-end (pipeline ordering, side-effect gating, cache-safety constraints) (Spec: `docs/Spec_Digest.md`, Anchors: None).
- “Minimal integration test matrix” flows pass (or are explicitly deferred under “Known debt & open questions” with rationale).
- Optional defenses remain default OFF and capability-gated; enabling them changes behavior only as specified (Spec: Security (docs/Canonical_Spec.md#sec-security); Throttling (docs/Canonical_Spec.md#sec-throttling); Adaptive challenge (docs/Canonical_Spec.md#sec-adaptive-challenge), Anchors: None).
- No numeric constants are duplicated outside the spec; implementation and tests reference named Anchors where constraints apply (Spec: Anchors (docs/Canonical_Spec.md#sec-anchors), Anchors: None).

---

## Known debt & open questions

- [ ] Decide test execution strategy for WordPress-specific paths (pure-PHP harness vs WP integration harness) (Spec: Test/QA checklist (docs/Canonical_Spec.md#sec-test-qa), Anchors: None)
  - `Artifacts:` `eforms/tests/README.md` (new), `eforms/tests/integration/` (confirm conventions), `eforms/tests/bootstrap.php` (modify as needed)
  - `Interfaces:` None
  - `Tests:` N/A (decision task; impacts how other tests run)
  - `Depends On:` None
  - `Done When:` the chosen strategy is documented in `eforms/tests/README.md` with a single canonical command to run unit + integration checks
- [ ] Decide how to exercise the REST endpoint and WP-CLI surfaces in CI (Spec: Public surfaces index (docs/Canonical_Spec.md#sec-objective), Anchors: None)
  - `Artifacts:` `.github/workflows/ci.yml` (new), `eforms/bin/wp-cli/` (new, if using WP-CLI smoke scripts)
  - `Interfaces:` `POST /eforms/mint`, `wp eforms gc`
  - `Tests:` `eforms/bin/wp-cli/post-no-origin.php` (new), `eforms/bin/wp-cli/post-oversized.php` (new)
  - `Depends On:` None
  - `Done When:` CI runs at least one smoke exercise for each surface and fails when observed behavior deviates from the spec-defined contract
- [ ] Concretize the manual E2E scripts into automated browser checks (if desired) without changing runtime behavior (Spec: Assets (docs/Canonical_Spec.md#sec-assets), Anchors: None)
  - `Artifacts:` `eforms/tests/e2e/` (extend), `eforms/tests/e2e/README.md` (new)
  - `Interfaces:` JS-minted injection behavior and mixed-mode page handling
  - `Tests:` `eforms/tests/e2e/` (automated checks replacing the manual scripts)
  - `Depends On:` Phase 2 — Implement JS-minted token injection + remint behavior in `forms.js`; Phase 2 — Enforce mixed-mode page behavior in client + server
  - `Done When:` JS behaviors are exercised automatically (no new runtime surfaces or config), and failures produce actionable diagnostics

---

## Plan maintenance

- Checkboxes are the canonical execution tracker for this spec. Completed items should be marked `[x]` and preserved.
- If `docs/Canonical_Spec.md` changes behavior/contracts, add `[ ] Rebase plan to current spec` at the top of Phase 0 before adding new work.
