# Implementation Plan — electronic_forms Greenfield-Style Refactor

This plan replaces the prior completed-task ledger with a refactor plan for the existing implementation. It uses a greenfield-style standard for ownership and verification: reshape the current code toward the target architecture directly, without preserving broken intermediate seams. It is non-normative: it decomposes the current spec and the latest architecture audit findings into execution tasks, but it does not introduce behavior beyond `docs/Canonical_Spec.md`.

## Scope

- Refactor the existing WordPress plugin from the public surfaces inward: shortcode/template tag render, public POST handling, `/eforms/mint`, `wp eforms gc`, uninstall behavior, and operator documentation.
- Treat the audit findings as architecture constraints for the refactor:
  - one public request lifecycle owner must call `SubmitHandler` and handle rerender/PRG;
  - one canonical form identity must map shortcode slug, template filename stem, rendered `form_id`, token record `form_id`, POST namespace, ledger path, and logs;
  - no shipped template may rely on stubbed renderer/validator/normalizer paths;
  - JS-minted mode must use a runtime-resolved endpoint and be install-context safe;
  - verification must include WordPress-facing smoke/E2E coverage, not only direct class tests.
- Greenfield-style refactor rule: use existing working code where it belongs, but do not add compatibility adapters, dual-write paths, fallback readers, or legacy bridges unless the user explicitly asks for compatibility.

## Source of Truth

- Spec: `docs/Canonical_Spec.md` (`<!-- SPEC_STATUS: STABLE -->`)
- Digest: `docs/Spec_Digest.md`
- Narrative: `docs/overview.md`
- Audit input: architecture audit findings from this session, treated as implementation-risk evidence only.

## Host Contracts

- WordPress shortcode API: `[eform id="..." cacheable="..."]`
- WordPress template tag: `eform_render($slug, $opts)`
- WordPress REST API: `POST /eforms/mint`
- WordPress request lifecycle hooks used by the public submit controller
- WordPress `wp_mail()`, `wp_safe_redirect()`, `nocache_headers()`, `wp_upload_dir()`, script enqueue/localization APIs, and WP-CLI registration
- Filesystem semantics under `${uploads.dir}`: atomic temp-write/rename, exclusive-create, `0700` dirs, `0600` files

## Verification Baseline

Verification Command:

```bash
find eforms/tests/unit eforms/tests/integration eforms/tests/smoke -type f -name 'test_*.php' -print0 | sort -z | xargs -0 -n1 php
```

Browser Verification Command:

```bash
cd eforms/tests/e2e && npm test
```

Current baseline observed before this plan rewrite:
- Pure PHP unit/integration lane passed.
- Smoke lane passed.
- WordPress-runtime end-to-end public POST coverage is not yet sufficient; P0.T2 makes this an executable gate before public lifecycle tasks can be marked done.

## Discovery Snapshot

- `docs/Architecture_Router.md` and `docs/Owner_Index.md` are absent. Because this refactor is cross-module and ownership-sensitive, P0.T1 creates the owner map before implementation tasks move ownership or introduce new public lifecycle seams.
- Existing likely owners from repo evidence:
  - Bootstrap/public hook registration: `eforms/src/bootstrap.php`
  - GET/rerender markup: `eforms/src/Rendering/FormRenderer.php`
  - Submission orchestration: `eforms/src/Submission/SubmitHandler.php`
  - Token mint/validate/origin/throttle/challenge: `eforms/src/Security/*`
  - Template load/context/schema: `eforms/src/Rendering/TemplateLoader.php`, `eforms/src/Rendering/TemplateContext.php`, `eforms/src/Validation/TemplateValidator.php`
  - Upload policy/storage: `eforms/src/Uploads/*`
  - Browser runtime: `eforms/assets/forms.js`
- Missing or weak owner paths from audit:
  - no public WordPress POST controller calls `SubmitHandler`;
  - template filename stem and template `id` disagree in shipped fixtures;
  - upload field rendering/registry paths still include not-implemented stubs;
  - `forms.js` hardcodes `/eforms/mint`;
  - README install requirements drift from the spec and repo tooling.

## Digest Core

The following fixed excerpt from `docs/Spec_Digest.md` must be reused during execution:

- [ ] Pipeline order: Security gate → Normalize → Validate → Coerce → Challenge verify → Ledger reserve → side effects (email/uploads/logging) → PRG/redirect. Hard failures abort before side effects. → [§Security](../docs/Canonical_Spec.md#sec-security)
- [ ] Challenge verification before ledger reserve; never on initial GET, only on POST rerender. → [§Challenge](../docs/Canonical_Spec.md#sec-challenge)
- [ ] Throttle enforcement before token mint. → [§Security](../docs/Canonical_Spec.md#sec-security)
- [ ] Ledger reserve immediately precedes email, upload moves, logging; treats EEXIST as duplicate. → [§Ledger](../docs/Canonical_Spec.md#sec-ledger-contract)
- [ ] All writes: temp file → atomic rename in same directory. Ledger via exclusive-create (fopen xb). Fail hard if unsupported. → [§Filesystem](../docs/Canonical_Spec.md#sec-filesystem-semantics)
- [ ] Uploads stay in temp until ledger reserve succeeds; then move to private storage. → [§Uploads](../docs/Canonical_Spec.md#sec-uploads)
- [ ] Email fail after ledger reserve → mint fresh token, burn original in ledger, allow immediate retry. → [§Security](../docs/Canonical_Spec.md#sec-security)
- [ ] Tokens reuse on error rerender; no rotation until success (except email failure case). → [§Security](../docs/Canonical_Spec.md#sec-security)
- [ ] Same form_id on page twice is config error; fail fast. → [§Public Surfaces](../docs/Canonical_Spec.md#sec-public-surfaces)
- [ ] /eforms/mint never sets cookies; must emit Cache-Control: no-store. → [§Mint](../docs/Canonical_Spec.md#sec-mint-endpoint)
- [ ] /eforms/mint never emits CORS headers. → [§Mint](../docs/Canonical_Spec.md#sec-mint-endpoint)
- [ ] forms.js calls /eforms/mint only for cacheable=true forms; never for hidden-mode. → [§JavaScript](../docs/Canonical_Spec.md#sec-javascript)
- [ ] forms.js fills empty token fields only; never overwrites populated fields. → [§JavaScript](../docs/Canonical_Spec.md#sec-javascript)
- [ ] All submissions route through single Security gate; no side-channel paths. → [§Security](../docs/Canonical_Spec.md#sec-security)
- [ ] Token hard-fail always aborts before ledger reserve; no ledger write on token fail. → [§Ledger](../docs/Canonical_Spec.md#sec-ledger-contract)
- [ ] Validation deterministic; error order: global → field (struct/type/required/constraint/cross). Collect all errors. → [§Validation](../docs/Canonical_Spec.md#sec-template-validation)
- [ ] Redirects via wp_safe_redirect; same-origin only. → [§Success](../docs/Canonical_Spec.md#sec-success)
- [ ] PRG success status: 303 See Other; must satisfy cache-safety rules. → [§Success](../docs/Canonical_Spec.md#sec-success)

## Seam Guards

- Public POST owner guard: `rg -n 'SubmitHandler::handle|do_success_redirect' eforms/src eforms/eforms.php`
  - Expected after P1.T2: only the public request controller and direct tests call these entry points.
  - Run gate: P1 phase checkpoint.
- Form-id guard: `php eforms/tests/tools/assert-template-slugs.php`
  - Expected after P1.T1: every `templates/forms/{form_id}.json` has matching `"id":"{form_id}"`.
  - Run gate: task completion gate.
- Upload-stub guard: `rg -n 'not implemented|Upload rendering not implemented|Upload normalization not implemented|Upload validation not implemented|scaffold|stubbed' eforms/src eforms/eforms.php`
  - Expected after P2.T1: no matches in runtime code.
  - Run gate: task completion gate.
- Mint endpoint guard: `rg -n "MINT_ENDPOINT = '/eforms/mint'|['\\\"]/eforms/mint" eforms/assets eforms/src`
  - Expected after P3.T1: no hardcoded browser endpoint; server-side route registration references are allowed.
  - Run gate: task completion gate.

## Phase 0 — Refactor Rails and Ownership Maps

Goals:
- Make ownership and verification explicit before changing runtime behavior.
- Establish a WordPress-runtime harness that can prove public surfaces, not just class-level behavior.

Acceptance:
- Owner docs exist.
- The primary verification command is runnable.
- The WordPress-runtime smoke lane can prove shortcode render, public POST, `/eforms/mint`, PRG, and asset localization.

### Work Items

- [ ] P0.T1 Create owner registry and architecture router (Spec: Architecture and file layout; Request lifecycle; Logging; Uploads, Anchors: None)
  - `Type:` `seam-refactor`
  - `Artifacts:` `docs/Architecture_Router.md` (new), `docs/Owner_Index.md` (new), `docs/Implementation_Plan.md` (update task state only after verification)
  - `Interfaces:` none
  - `Owner:` `docs/Owner_Index.md` owns canonical owner entries; `docs/Architecture_Router.md` owns capability routing.
  - `Depends On:` none
  - `Boundary Decision:` introduce new shared layer; keeping ownership implicit already hid the public POST gap, and extending only README/spec would not give executors a concrete owner map.
  - `Existing Owner Evidence:` current repo has subsystem owners but no owner registry; see Discovery Snapshot.
  - `Docs Consulted:` `docs/Canonical_Spec.md`, `docs/Spec_Digest.md`, `docs/overview.md`
  - `Reuse Target:` existing subsystem boundaries named in Discovery Snapshot
  - `No-Fallback Rule:` do not use ad hoc comments or README prose as the owner registry.
  - `Contract Carriers to Re-evaluate:` README architecture section, module doc comments, existing tests that assume direct class-only submission.
  - `Guard Strategy:` owner docs must contain the runtime owners named by the public lifecycle and upload/browser tasks before those tasks start.
  - `Replacement:` implicit ownership assumptions -> explicit owner docs
  - `Superseded Seams:` none in runtime; this is planning/ownership infrastructure.
  - `Removal Proof:` `test -f docs/Architecture_Router.md && test -f docs/Owner_Index.md`
  - `Complexity Budget:` add 2 docs, no runtime code
  - `Done When:` public request lifecycle, renderer, security, validation, upload, browser runtime, logging, GC, and uninstall owners are named with allowed entry points and forbidden duplicate paths.
  - `Verified via:` `rg -n 'PublicRequestController|FormRenderer|SubmitHandler|MintEndpoint|UploadStore|forms.js|GcRunner' docs/Owner_Index.md docs/Architecture_Router.md`
  - `Reasoning:` `high`

- [ ] P0.T2 Add WordPress-runtime public surface harness (Spec: Public surfaces index; Request lifecycle GET/POST; JS-minted mode contract; Success behavior, Anchors: None)
  - `Type:` `standard`
  - `Artifacts:` `eforms/tests/wp-runtime/` (new or equivalent), `eforms/tests/README.md`, optional harness scripts under `eforms/tests/tools/`
  - `Interfaces:` `[eform]`, `eform_render()`, public POST, `/eforms/mint`, PRG redirect
  - `Owner:` `eforms/tests/wp-runtime/` owns real WordPress public-surface verification.
  - `Depends On:` P0.T1
  - `Done When:` there is a runnable local or CI-friendly command that boots WordPress or a faithful WP runtime fixture, renders a form through shortcode, submits it through the public request path, verifies PRG, and verifies `/eforms/mint` headers/body.
  - `Verified via:` documented command in `eforms/tests/README.md` plus one passing wp-runtime smoke test
  - `Reasoning:` `high`

- [ ] P0.T3 Define refactor cleanup guards (Source: architecture audit findings; Spec: Request lifecycle; Uploads; Assets, Anchors: None)
  - `Type:` `seam-refactor`
  - `Artifacts:` `eforms/tests/tools/assert-template-slugs.php` (new), `eforms/tests/README.md`, optional guard scripts
  - `Interfaces:` none
  - `Owner:` test tools own static absence checks for greenfield-style refactor invariants.
  - `Depends On:` P0.T1
  - `Boundary Decision:` introduce guard tooling; keeping these as manual review items allowed completed tests to miss public lifecycle and stub issues.
  - `Existing Owner Evidence:` no current command proves template slug equality, no runtime-code stub absence command, no public POST single-owner guard.
  - `Docs Consulted:` `docs/Spec_Digest.md`, `docs/Canonical_Spec.md`
  - `Reuse Target:` documented `Verification Command`, `Seam Guards`
  - `No-Fallback Rule:` do not mark affected tasks complete with only direct class tests.
  - `Contract Carriers to Re-evaluate:` test README, existing smoke tests, audit-derived grep guards.
  - `Guard Strategy:` each cleanup invariant gets a named command and run gate in the Seam Guards section.
  - `Replacement:` manual audit-only checks -> runnable guard scripts/commands
  - `Superseded Seams:` none
  - `Removal Proof:` Seam Guards section commands exist and are referenced by relevant tasks.
  - `Complexity Budget:` add guard scripts only; no runtime code
  - `Done When:` each audit finding has a guard or test path that fails before the fix and passes after the fix.
  - `Verified via:` `php eforms/tests/tools/assert-template-slugs.php` and the listed `rg` guards
  - `Reasoning:` `medium`

## Phase 1 — First Public End-to-End Slice

Goals:
- Ship the smallest useful path first: hidden-mode GET render -> real public POST -> security/validation/ledger/email -> PRG -> success banner.
- Collapse identity and request lifecycle ownership before adding optional paths.

Acceptance:
- A real WordPress request can submit the shipped `contact` form through one public controller and reach PRG.
- No direct, duplicate, or side-channel POST path bypasses `SubmitHandler`.
- Slug, template id, token record form_id, field namespace, and ledger form_id are identical.

Phase Ownership Charter:
- `Canonical Owner:` `eforms/src/Submission/PublicRequestController.php` owns public POST detection, request extraction, rerender orchestration, response headers, and PRG handoff.
- `Allowed Seams:` shortcode/template tag render through `FormRenderer`; POST processing through `SubmitHandler`; redirect through `Success`.
- `Kill List:` unhandled same-page raw POSTs, direct hook-level submission logic outside `PublicRequestController`, template id/filename mismatches.

### Work Items

- [ ] P1.T1 Enforce canonical form identity (Spec: Template JSON; Request lifecycle GET/POST; Submission Protection for Public Forms, Anchors: None)
  - `Type:` `seam-refactor`
  - `Artifacts:` `eforms/src/Rendering/TemplateLoader.php`, `eforms/src/Validation/TemplateValidator.php`, `eforms/src/Rendering/TemplateContext.php`, `eforms/templates/forms/*.json`, `eforms/tests/unit/test_shipped_templates_preflight.php`, `eforms/tests/tools/assert-template-slugs.php`
  - `Interfaces:` template filenames, `[eform id="slug"]`, rendered `form_id`, POST namespace, token records, ledger paths, log metadata
  - `Owner:` `TemplateLoader` owns filename-stem loading; `TemplateValidator` owns `template.id === filename stem` validation; `TemplateContext` exposes the canonical id to all consumers.
  - `Depends On:` P0.T3
  - `Boundary Decision:` extend existing owner; keeping this local in renderer would miss POST/security/logging, and a new identity layer is unnecessary because template loading already owns filename stem.
  - `Existing Owner Evidence:` `TemplateLoader::load($form_id)` maps slug to `templates/forms/{form_id}.json`; `FormRenderer` and `SubmitHandler` consume `TemplateContext` id.
  - `Docs Consulted:` `docs/Canonical_Spec.md#sec-template-json`, `docs/Canonical_Spec.md#sec-request-lifecycle-get`, `docs/Owner_Index.md`
  - `Reuse Target:` `TemplateLoader` + `TemplateValidator` + `TemplateContext`
  - `No-Fallback Rule:` no slug transforms, underscore/dash aliases, or alternate POST namespace mapping.
  - `Contract Carriers to Re-evaluate:` shipped templates, token record tests, ledger path tests, log metadata tests, README shortcode examples.
  - `Guard Strategy:` template slug guard plus public lifecycle tests prove the same id crosses render, token, POST, ledger, and logs.
  - `Replacement:` mismatched fixture ids -> exact filename-stem ids
  - `Superseded Seams:` implicit template `id` trust without filename check
  - `Removal Proof:` `php eforms/tests/tools/assert-template-slugs.php`
  - `Complexity Budget:` one validation rule, fixture id updates, no compatibility aliases
  - `Done When:` every shipped template id equals its filename stem; invalid mismatch prevents render/mint/submit with deterministic configuration error; no runtime path accepts a different id for the same template.
  - `Verified via:` `php eforms/tests/tools/assert-template-slugs.php`; `eforms/tests/unit/test_template_loader.php`; `eforms/tests/unit/test_shipped_templates_preflight.php`
  - `Reasoning:` `high`

- [ ] P1.T2 Implement public POST controller (Spec: Request lifecycle POST; Success behavior; Cache-safety; Error handling, Anchors: [TOKEN_TTL_MIN], [TOKEN_TTL_MAX])
  - `Type:` `seam-refactor`
  - `Artifacts:` `eforms/src/Submission/PublicRequestController.php` (new), `eforms/src/bootstrap.php`, `eforms/src/Submission/SubmitHandler.php`, `eforms/src/Submission/Success.php`, `eforms/tests/wp-runtime/*`
  - `Interfaces:` public same-page form POST, HTTP status, cache headers, PRG 303, validation rerender
  - `Owner:` `PublicRequestController` owns WordPress POST detection and response orchestration; `SubmitHandler` remains the pipeline owner.
  - `Depends On:` P1.T1
  - `Boundary Decision:` introduce new shared layer; keeping POST handling in bootstrap makes lifecycle logic procedural and hard to test, while extending `SubmitHandler` would mix WordPress response ownership into the pure pipeline owner.
  - `Existing Owner Evidence:` `SubmitHandler::handle()` orchestrates pipeline but no WordPress hook currently calls it.
  - `Docs Consulted:` `docs/Canonical_Spec.md#sec-request-lifecycle-post`, `docs/Canonical_Spec.md#sec-success`, `docs/Owner_Index.md`
  - `Reuse Target:` `SubmitHandler::handle()`, `SubmitHandler::do_success_redirect()`, `FormRenderer::render()`, `Success`
  - `No-Fallback Rule:` no second POST handler in shortcode, REST mint endpoint, bootstrap closure, or template tag.
  - `Contract Carriers to Re-evaluate:` bootstrap hook tests, success redirect tests, validation rerender tests, cache-header tests, README architecture section.
  - `Guard Strategy:` public POST owner guard plus wp-runtime POST tests prove only the controller owns WordPress-facing POST orchestration.
  - `Candidate Scope:` `eforms/src/bootstrap.php`, `eforms/src/Submission/*`, `eforms/src/Rendering/FormRenderer.php`, public-surface tests.
  - `Leftover Checks:` Public POST owner guard; `rg -n '$_POST|REQUEST_METHOD|wp_safe_redirect|header\\(' eforms/src eforms/eforms.php` reviewed for expected controller-owned usage.
  - `Exception Revalidation:` keep `SubmitHandler` direct calls in unit/integration tests only; revalidate via Public POST owner guard at phase close.
  - `Closure Buckets:` public POST detection, rerender response, success redirect, cache headers.
  - `Replacement:` unhandled raw same-page POST -> single public request controller
  - `Superseded Seams:` direct class-only submission path as the only executable path
  - `Removal Proof:` Public POST owner guard shows only `PublicRequestController` and tests call `SubmitHandler`.
  - `Complexity Budget:` add one controller and hook wiring; no duplicate pipeline logic
  - `Done When:` a real POST is detected before template output, routed through `SubmitHandler`, validation errors rerender via `FormRenderer`, success invokes PRG 303, and headers/status are set according to the spec without `exit`/`die`.
  - `Verified via:` wp-runtime hidden-mode submit test; `eforms/tests/integration/test_post_pipeline_ordering.php`; Public POST owner guard
  - `Reasoning:` `high`

- [ ] P1.T3 Prove hidden-mode public lifecycle end to end (Spec: Request lifecycle GET/POST; Security invariants; Ledger reservation contract; Success behavior, Anchors: [TOKEN_TTL_MIN], [TOKEN_TTL_MAX], [LEDGER_GC_GRACE_SECONDS])
  - `Type:` `standard`
  - `Artifacts:` `eforms/tests/wp-runtime/test_hidden_mode_public_lifecycle.php` (new or equivalent), `eforms/tests/integration/test_success_inline_flow.php`, `eforms/tests/integration/test_ledger_reserve_semantics.php`
  - `Interfaces:` `[eform id="contact" cacheable="false"]`, POST body, PRG, inline success query
  - `Owner:` `PublicRequestController` owns public lifecycle; `SubmitHandler` owns pipeline.
  - `Depends On:` P1.T2
  - `Done When:` one real WordPress-facing test renders hidden-mode form, extracts hidden token metadata, posts valid data, reserves ledger exactly once, sends mail via stubbed `wp_mail`, receives 303, and follow-up GET shows the success banner.
  - `Verified via:` wp-runtime hidden-mode public lifecycle test; `find eforms/tests/unit eforms/tests/integration eforms/tests/smoke -type f -name 'test_*.php' -print0 | sort -z | xargs -0 -n1 php`
  - `Reasoning:` `high`

- [ ] P1.T4 Prove failure rerenders and duplicate suppression through public path (Spec: Error handling; Ledger reservation contract; Email-failure recovery; Challenge, Anchors: [TOKEN_TTL_MIN], [TOKEN_TTL_MAX])
  - `Type:` `standard`
  - `Artifacts:` `eforms/tests/wp-runtime/test_public_failure_paths.php` (new or equivalent), existing integration tests for challenge/email/ledger
  - `Interfaces:` validation error rerender, duplicate token error, email failure rerender, challenge rerender
  - `Owner:` `PublicRequestController` owns response orchestration; `SubmitHandler` owns failure classification.
  - `Depends On:` P1.T3
  - `Done When:` public tests cover invalid field rerender, duplicate replay rejection, email-send failure fresh-token rerender, and challenge-required rerender without ledger reservation.
  - `Verified via:` wp-runtime failure-path test; `eforms/tests/integration/test_email_failure_rerender.php`; `eforms/tests/integration/test_challenge_rerender_only.php`; `eforms/tests/integration/test_ledger_reserve_semantics.php`
  - `Reasoning:` `high`

## Phase 2 — Complete Field and Upload Pipeline

Goals:
- Ensure every shipped field type renders, normalizes, validates, coerces, emails, and logs without stubs.
- Make upload support complete or remove upload templates from shipped public fixtures before release. For this refactor, prefer completing the spec-defined upload path instead of carrying stubs or test-only shipped fixtures.

Acceptance:
- No runtime `not implemented` upload paths remain.
- `upload-test` can render and submit through the public lifecycle when uploads are enabled.
- Uploads remain in temp until ledger reservation and never leak `tmp_name` to email/log output.

### Work Items

- [ ] P2.T1 Implement upload field render/normalize/validate path (Spec: Uploads; Template model fields; Validation pipeline; Request lifecycle POST, Anchors: None)
  - `Type:` `shared-ui-runtime`
  - `Artifacts:` `eforms/src/Rendering/FieldRenderers/Upload.php`, `eforms/src/Validation/Normalizer.php`, `eforms/src/Validation/Validator.php`, `eforms/src/Uploads/UploadPolicy.php`, `eforms/src/Uploads/UploadStore.php`, `eforms/src/Rendering/RendererRegistry.php`, `eforms/src/Validation/*Registry.php`, upload tests
  - `Interfaces:` file/files field markup, `multipart/form-data`, `accept`, upload validation errors, email attachments
  - `Owner:` Upload field behavior is shared by field registries plus `UploadPolicy`/`UploadStore`; no local template-specific upload owner.
  - `Depends On:` P1.T3
  - `Existing Owner Evidence:` upload policy/storage classes exist; upload renderer/registry stubs are present.
  - `Docs Consulted:` `docs/Canonical_Spec.md#sec-uploads`, `docs/Canonical_Spec.md#sec-template-model-fields`, `docs/Owner_Index.md`
  - `Reuse Target:` `UploadPolicy`, `UploadStore`, existing field registry pattern
  - `Boundary Decision:` extend existing owner; keeping upload behavior local to renderer would bypass validation/storage policy, and introducing a new upload facade is unnecessary unless existing `UploadPolicy`/`UploadStore` cannot carry the contract.
  - `Selector Reuse:` existing eForms field/control naming and error-summary conventions
  - `Selector Delta:` none
  - `Style Delta:` none
  - `UI Completion Gate:` `upload-test` renders a file input with `accept`, required/error state, and field-summary links.
  - `Consumer Status:` live consumer `upload-test`; staged second consumer `files` fixture/test if not shipped.
  - `No-Fallback Rule:` no template-specific upload renderer, no direct `$_FILES` handling outside normalization/upload owners.
  - `Contract Carriers to Re-evaluate:` upload templates, upload policy tests, email attachment tests, normalization/validation tests, public lifecycle tests.
  - `Guard Strategy:` Upload-stub guard plus upload move/email tests prove no stub or temp-path leak remains.
  - `Done When:` file/files controls render; uploaded files validate by accept/extension/MIME/size; valid files move only after ledger reserve; invalid files rerender with deterministic errors; no stub exceptions remain.
  - `Verified via:` `eforms/tests/integration/test_upload_accept_tokens.php`; `eforms/tests/integration/test_upload_move_after_ledger.php`; `eforms/tests/integration/test_email_attachments_policy.php`; Upload-stub guard
  - `Reasoning:` `high`

- [ ] P2.T2 Prove shipped templates are production-usable fixtures (Spec: Template JSON; Templates to include; Request lifecycle GET, Anchors: [MAX_FIELDS_MAX], [MAX_OPTIONS_MAX], [MAX_MULTIVALUE_MAX])
  - `Type:` `standard`
  - `Artifacts:` `eforms/templates/forms/*.json`, `eforms/tests/unit/test_shipped_templates_preflight.php`, wp-runtime render tests
  - `Interfaces:` shipped form templates
  - `Owner:` `TemplateValidator` owns fixture preflight; `FormRenderer` owns renderability.
  - `Depends On:` P2.T1
  - `Done When:` every shipped template passes preflight, renders successfully, and either submits in wp-runtime tests or is explicitly marked test-only outside shipped public examples.
  - `Verified via:` `eforms/tests/unit/test_shipped_templates_preflight.php`; wp-runtime render-all-shipped-templates test; Form-id guard
  - `Reasoning:` `medium`

## Phase 3 — Cacheable Mode and Browser Runtime

Goals:
- Make JS-minted cacheable pages reliable across WordPress install contexts.
- Preserve cacheability while keeping token minting fail-closed and same-origin.

Acceptance:
- Browser code receives endpoint/config from PHP; it does not hardcode root-relative `/eforms/mint`.
- Cacheable forms block submit until mint succeeds, remint after email failure, and never mint for hidden-mode forms.
- Activation/install behavior makes the pretty endpoint usable or provides a reliable REST fallback per spec-compatible routing.

### Work Items

- [ ] P3.T1 Resolve mint endpoint from WordPress runtime (Spec: JS-minted mode contract; Assets; Compatibility and updates, Anchors: None)
  - `Type:` `shared-ui-runtime`
  - `Artifacts:` `eforms/assets/forms.js`, `eforms/src/Rendering/FormRenderer.php`, `eforms/src/bootstrap.php`, activation hook wiring if needed, e2e specs
  - `Interfaces:` browser POST to `/eforms/mint`, script-localized runtime config, cacheable form token injection
  - `Owner:` `FormRenderer`/asset enqueue owns browser runtime configuration; `MintEndpoint` owns server response.
  - `Depends On:` P1.T2
  - `Existing Owner Evidence:` browser currently owns endpoint as a constant; bootstrap owns route registration.
  - `Docs Consulted:` `docs/Canonical_Spec.md#sec-js-mint-mode`, `docs/Canonical_Spec.md#sec-assets`, `docs/Owner_Index.md`
  - `Reuse Target:` WordPress script enqueue/localization and existing `MintEndpoint`
  - `Boundary Decision:` extend existing owner; keeping the endpoint hardcoded in `forms.js` breaks install-context safety, while a new browser endpoint abstraction would duplicate WordPress enqueue/localization ownership.
  - `Selector Reuse:` existing `data-eforms-mode`, token hidden inputs
  - `Selector Delta:` only add data/config required to carry endpoint if localization is unavailable
  - `Style Delta:` none
  - `UI Completion Gate:` JS-minted form can mint on a subdirectory install and after activation without manual rewrite repair.
  - `Consumer Status:` live cacheable form; mixed-mode page e2e
  - `No-Fallback Rule:` no hardcoded browser root path and no CORS workaround.
  - `Contract Carriers to Re-evaluate:` browser e2e specs, mint endpoint integration tests, bootstrap route tests, README cacheable-mode docs.
  - `Guard Strategy:` Mint endpoint guard plus root/subdirectory browser tests prove runtime-resolved endpoint usage.
  - `Done When:` endpoint URL is server-provided; pretty rewrite is flushed on activation if used; browser tests pass for root and subdirectory/base-url contexts.
  - `Verified via:` Browser Verification Command; Mint endpoint guard; `eforms/tests/integration/test_mint_endpoint_contract.php`
  - `Reasoning:` `high`

- [ ] P3.T2 Complete JS-minted behavior and failure UX (Spec: JS-minted mode contract; Assets; Email-failure recovery, Anchors: [TOKEN_TTL_MIN], [TOKEN_TTL_MAX])
  - `Type:` `standard`
  - `Artifacts:` `eforms/assets/forms.js`, `eforms/tests/e2e/specs/js_minted_injection.spec.js`, `eforms/tests/e2e/specs/mixed_mode_page.spec.js`, integration tests for mint
  - `Interfaces:` JS-minted hidden fields, sessionStorage token cache, `data-eforms-remint`, generic mint error summary
  - `Owner:` `forms.js` owns browser mint/injection state; `MintEndpoint` owns token response.
  - `Depends On:` P3.T1
  - `Done When:` JS-minted forms mint only when empty, never overwrite populated token fields, cache per form id until expiry, clear/remint on email failure, block submit on failure, and show deterministic generic error.
  - `Verified via:` Browser Verification Command; `eforms/tests/integration/test_mint_endpoint_contract.php`; `eforms/tests/integration/test_throttle_retry_after.php`
  - `Reasoning:` `medium`

## Phase 4 — Security, Ops, and Observability Closure

Goals:
- Close cross-cutting invariants after public lifecycle, uploads, and browser runtime are live.
- Prove operational tooling is release-usable for small real sites.

Acceptance:
- Origin, throttle, challenge, ledger, logging, fail2ban, GC, uninstall, and compatibility guards work through the public lifecycle.
- Docs and tests prove real operator paths and failure modes.

### Work Items

- [ ] P4.T1 Prove security ordering through public paths (Spec: Security; Origin policy; Throttling; Challenge; Ledger reservation contract, Anchors: [MIN_FILL_SECONDS_MIN], [MIN_FILL_SECONDS_MAX], [THROTTLE_MAX_PER_MIN_MIN], [THROTTLE_MAX_PER_MIN_MAX], [CHALLENGE_TIMEOUT_MIN], [CHALLENGE_TIMEOUT_MAX])
  - `Type:` `standard`
  - `Artifacts:` public lifecycle security tests, `eforms/src/Security/*`, `eforms/src/Submission/SubmitHandler.php`
  - `Interfaces:` token validation, origin hard/soft fail, throttle 429/Retry-After, challenge rerender, ledger duplicate handling
  - `Owner:` `Security` owns security gate; `SubmitHandler` owns ordering; `PublicRequestController` owns HTTP response orchestration.
  - `Depends On:` P3.T2
  - `Done When:` public tests prove hard failures abort before ledger/side effects, challenge verifies before ledger, throttle applies to mint and POST, and duplicate reservation returns the specified token error.
  - `Verified via:` wp-runtime security-path tests; `eforms/tests/integration/test_post_pipeline_ordering.php`; `eforms/tests/integration/test_honeypot_paths.php`; `eforms/tests/integration/test_challenge_rerender_only.php`; `eforms/tests/integration/test_throttle_retry_after.php`
  - `Reasoning:` `high`

- [ ] P4.T2 Prove logging, fail2ban, GC, and uninstall operational paths (Spec: Logging; Uploads; Compatibility and updates; Public surfaces index, Anchors: [RETENTION_DAYS_MIN], [RETENTION_DAYS_MAX], [LEDGER_GC_GRACE_SECONDS])
  - `Type:` `standard`
  - `Artifacts:` `eforms/src/Logging.php`, `eforms/src/Logging/*`, `eforms/src/Gc/GcRunner.php`, `eforms/src/Cli/GcCommand.php`, `eforms/uninstall.php`, ops tests
  - `Interfaces:` `eforms_request_id`, logging modes, fail2ban file emission, `wp eforms gc`, uninstall purge flags
  - `Owner:` `Logging` owns emitted events; `GcRunner` owns artifact pruning; `uninstall.php` owns uninstall purge behavior.
  - `Depends On:` P4.T1
  - `Done When:` public submit/mint failures include request id in logs, fail2ban emits only configured `EFORMS_ERR_*` lines with raw IP, GC prunes expired artifacts without fresh marker deletion, and uninstall respects purge flags.
  - `Verified via:` `eforms/tests/integration/test_logging_jsonl_schema.php`; `eforms/tests/integration/test_fail2ban_line_format.php`; `eforms/tests/integration/test_gc_dry_run.php`; `eforms/tests/integration/test_uninstall_purge_flags.php`
  - `Reasoning:` `medium`

- [ ] P4.T3 Prove compatibility and cache-safety boundaries (Spec: Compatibility and updates; Cache-safety; Request lifecycle GET/POST, Anchors: None)
  - `Type:` `standard`
  - `Artifacts:` `eforms/src/Compat.php`, `eforms/src/Rendering/FormRenderer.php`, `eforms/src/Submission/PublicRequestController.php`, smoke tests
  - `Interfaces:` activation/load guard, hidden-mode no-store headers, success no-store headers, mint no-store headers
  - `Owner:` `Compat` owns platform guard; render/controller/mint owners each own their response cache headers.
  - `Depends On:` P4.T2
  - `Done When:` PHP/WP minimums match spec, uploads semantics fail closed, hidden-mode token pages never mint after headers are sent, and success/mint responses emit required cache headers.
  - `Verified via:` `eforms/tests/smoke/test_compat_guards.php`; `eforms/tests/integration/test_cache_safety_hidden_mode_headers_sent.php`; wp-runtime cache-header assertions
  - `Reasoning:` `medium`

## Phase 5 — Operator Readiness and Documentation Sync

Goals:
- Make install, configuration, and contributor instructions match the implemented plugin.
- Remove scaffold/stub language from release-facing docs and runtime metadata.

Acceptance:
- README, docs, plugin header, and test docs agree with the spec and actual commands.
- A small-site operator can install, configure, render, submit, enable cacheable mode, schedule GC, and troubleshoot failures from canonical docs.

### Work Items

- [ ] P5.T1 Sync release-facing docs and plugin metadata (Spec: Compatibility and updates; Configuration; Public surfaces index, Anchors: None)
  - `Type:` `standard`
  - `Artifacts:` `README.md`, `docs/overview.md`, `docs/README.md`, `eforms/eforms.php`, `eforms/tests/README.md`
  - `Interfaces:` install requirements, contributor test commands, plugin description, runtime public surfaces
  - `Owner:` README owns operator quickstart; docs overview owns narrative; plugin header owns WordPress admin metadata.
  - `Depends On:` P4.T3
  - `Done When:` PHP minimum is consistent with spec/runtime, no nonexistent Composer workflow is documented unless `composer.json` exists, no scaffold/stub wording remains in runtime metadata, and test commands match actual harnesses.
  - `Verified via:` `rg -n 'PHP 8\\.0|composer install|scaffold|stubbed|not implemented' README.md docs eforms/eforms.php eforms/src eforms/tests/README.md`
  - `Reasoning:` `low`

- [ ] P5.T2 Run release broad gates and close plan verification (Spec: all implemented public surfaces, Anchors: all referenced Anchors)
  - `Type:` `standard`
  - `Artifacts:` `docs/Implementation_Plan.md`, all test reports/output, optional release checklist
  - `Interfaces:` all public surfaces
  - `Owner:` implementation plan owns completion state; test harness owners own proof.
  - `Depends On:` P5.T1
  - `Done When:` all checked tasks include `Verified via` evidence, broad gates pass, seam guards pass, and any residual issue is recorded under Known Debt & Open Questions with trigger and verification hook.
  - `Verified via:` Verification Command; Browser Verification Command; all Seam Guards
  - `Reasoning:` `high`

## Invariant Matrix

| Invariant | Positive Proof | Negative Proof |
|---|---|---|
| Public POST uses one controller and routes through `SubmitHandler` | P1.T3 wp-runtime lifecycle test | P1.T2 Public POST owner guard |
| Canonical form identity is one value across filename/id/render/POST/token/ledger/logs | P1.T1 form-id unit and public lifecycle tests | P1.T1 Form-id guard rejects mismatches |
| Security gate runs before normalize/validate/ledger/side effects | P4.T1 pipeline ordering tests | P4.T1 hard-failure tests assert no ledger/email/upload writes |
| Challenge verifies before ledger and never renders on initial GET | P4.T1 challenge success/failure tests | P4.T1 initial GET challenge absence assertion |
| Ledger reservation immediately precedes side effects and duplicate EEXIST suppresses replay | P1.T3/P4.T1 ledger reservation tests | P1.T4 duplicate replay public test |
| Email failure after ledger burns original token and rerenders with fresh retry token | P1.T4 email-failure public rerender test | P1.T4 asserts original token cannot be reused |
| Uploads stay temporary until ledger reserve and never expose `tmp_name` in email/logs | P2.T1 upload move and email attachment tests | P2.T1 assertions that body/logs lack tmp paths |
| JS-minted forms mint only through `/eforms/mint` and never for hidden-mode forms | P3.T2 mixed-mode E2E | P3.T2 route-intercept assertion: hidden-mode emits zero mint requests |
| `/eforms/mint` is same-origin, no-store, no CORS, JSON-only | P3.T1/P3.T2 mint endpoint tests | P4.T1 cross-origin/missing-origin rejection tests |
| No runtime stubs or scaffold paths remain | P2.T1 upload-stub guard; P5.T1 docs metadata scan | P2.T1/P5.T1 zero-match guards |

## Verification Summary

- Task-level verification is authoritative. Use this section only as a map.
- Broad gate after each phase: Verification Command.
- Browser/cacheable gate after Phase 3 and release: Browser Verification Command.
- Seam guards run at their stated task or phase gates.
- Before closing each phase, run a failure-branch sweep against the cited spec sections using: `if|when|unless|except|already|missing|expired|duplicate|retry|limit|invalid|cannot|fails|denied|conflict`.

## Known Debt & Open Questions

- None currently accepted for this greenfield-style refactor.
- Compatibility bridges for legacy template ids, old POST paths, or hardcoded browser endpoints are intentionally out of scope. If compatibility is later required, add an Open Question before implementation with explicit options and a removal trigger.

## Plan Maintenance

- Execute one unchecked task at a time.
- Mark a task `[x]` only after its `Done When`, `Verified via`, and applicable seam guards pass.
- If `docs/Canonical_Spec.md` changes in a behavior-affecting way, add `[ ] Rebase plan to current spec` before continuing.
- Preserve completed task text; append new tasks rather than rewriting checked history.
