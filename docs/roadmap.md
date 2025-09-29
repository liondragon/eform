# Product Roadmap

## Phase 1: `Config::get()` snapshot + lazy bootstrap.

**Goal:** Every public entry point primes the frozen configuration via `Config::get()`; helpers defensively re-check it. Establishing lazy bootstrap up front prevents downstream components from solving bootstrapping piecemeal and guarantees a consistent snapshot for all security and lifecycle decisions.

**Delivers**

- `Config::get()` (idempotent, per-request snapshot) + `bootstrap()` called exactly-once per request.
- `Config::bootstrap()` runs the `eforms_config` filter once per request and enforces the clamp/enumeration/unknown-key rules mandated by [Configuration → Domains, Constraints, and Defaults (§17)](#sec-configuration).
- Snapshot covers `security.*`, `spam.*`, `challenge.*`, `email.*`, `logging.*`, `privacy.*`, `throttle.*`, `validation.*`, `uploads.*`, `assets.*`, `install.*`, defaults (authoritative table in [Configuration: Domains, Constraints, and Defaults (§17)](#sec-configuration)).
- Shared storage rules: `{h2}` sharding via `Helpers::h2()`, dirs `0700`, files `0600`, with a one-time fallback to `0750/0640` and a warning log when strict creation fails per [Implementation Notes → Helpers](electronic_forms_SPEC.md#sec-implementation-notes).
- Defensive `Config::get()` calls inside helpers (normative lazy backstop).
- Helper library from [Implementation Notes → Helpers](electronic_forms_SPEC.md#sec-implementation-notes) shipped alongside bootstrap (`Helpers::nfc`, `Helpers::cap_id`, `Helpers::bytes_from_ini`, etc.) with fixtures covering their contracts.
- CI/lint hooks to assert all entry points (`Renderer`, `SubmitHandler`, `/eforms/prime`, `/eforms/success-verify`, challenge verifiers, `Emailer`) call `Config::get()` up front.
- `uninstall.php` implements and exercises the guard/Config bootstrap/purge-flag contract defined in [Architecture → /electronic_forms/ layout (§3)](electronic_forms_SPEC.md#sec-architecture), including operational toggles for uploads/log retention.
- Ship `/templates/` hardening files (`index.html`, `.htaccess`, `web.config`) and enforce the filename allow-list per [Architecture → /electronic_forms/ layout (§3)](electronic_forms_SPEC.md#sec-architecture).

**Acceptance**

- Multiple `Config::get()` calls in a request are safe; first triggers bootstrap only.
- Unit tests: snapshot immutability across components; default resolution; missing keys handled per spec.
- Fixtures/tests prove the `eforms_config` filter executes exactly once per request, reject invalid keys, and clamp configuration values to the §17 ranges/enumerations defined in [Configuration → Domains, Constraints, and Defaults (§17)](#sec-configuration).
- Uninstall integration tests assert the `defined('WP_UNINSTALL_PLUGIN')` guard, require the Config bootstrap, and respect purge-flag decisions per [Architecture → /electronic_forms/ layout (§3)](electronic_forms_SPEC.md#sec-architecture).
- Packaging checks confirm `/templates/` ships the protective files and filename allow-list required by [Architecture → /electronic_forms/ layout (§3)](electronic_forms_SPEC.md#sec-architecture).
- Storage acceptance includes a warning-path assertion so the `0750/0640` fallback continues to be exercised in tests.

---

## Phase 2: Security helpers (`mint_hidden_record`, `mint_cookie_record`, `token_validate`) {#phase-2}

**Goal:** Make helpers the single source of truth for identifiers, TTL, persistence, and policy evaluation. Keep them header-agnostic (except explicit inputs), pure (no side effects except documented writes), and aligned to generated matrices.

**Delivers**

- `Security::mint_hidden_record(form_id)`
  - Persist `tokens/{h2}/{sha256(token)}.json` `{mode:"hidden", form_id, instance_id, issued_at, expires}`.
  - Persistence honors the atomic write contract for `tokens/…` (write-temp+rename or `flock()`+fsync) per [Security → Shared lifecycle and storage contract](electronic_forms_SPEC.md#sec-shared-lifecycle).
  - Never rewrite on rerender; base64url `instance_id` (16–24 bytes).
- `Security::mint_cookie_record(form_id, slot?)` (header-agnostic)
  - Miss/expired → mint `eid_minted/{form_id}/{h2}/{eid}.json` with `{mode:"cookie", form_id, eid, issued_at, expires, slots_allowed:[], slot:null}`.
  - `eid_minted/…` persistence uses the same atomic write guarantees as hidden tokens so partial or concurrent writes cannot surface truncated JSON per [Security → Shared lifecycle and storage contract](electronic_forms_SPEC.md#sec-shared-lifecycle).
  - Hit → never rewrite `issued_at`/`expires`.
  - Status (`hit|miss|expired`) computed from storage (not headers).
- **Definitions enforced**  
  - **Unexpired match**: request presents syntactically valid `eforms_eid_{form_id}` AND matching record exists with `now < expires`.
  - Malformed/absent cookie **≠ tamper**; follow policy rows. True tamper (mode/form mismatch, cross-mode payloads, slot violations) → hard fail.
- `Security::token_validate()` (pure)
	- Inputs: POST payload, cookies, rendered metadata; reads snapshot & storage only.
	- Returns struct: `{mode, submission_id, slot?, token_ok, hard_fail, require_challenge, cookie_present?, is_ncid?, soft_reasons[]}` consistent with:
		- Cookie policy outcomes (matrix, §7.1.3.2)
		- Cookie-mode lifecycle (matrix, §7.1.3.3)
		- NCID rules (§7.1.4)
	- Evaluates throttle/origin policy state machine (soft labels, hard-fail paths, missing-origin carve-outs) per [Security (§7) → Helper contracts](electronic_forms_SPEC.md#sec-security-helpers) covering the `Security::token_validate()` origin policy.
- Error semantics: IO bubbles as hard failures; no header emission here.

**Acceptance**

- Golden tests mirror the cookie-policy and lifecycle matrices from [Security → Cookie policy matrix](electronic_forms_SPEC.md#sec-cookie-policy-matrix) and [Security → Throttling matrix](electronic_forms_SPEC.md#sec-throttling) (hard/soft/off/challenge).
- Acceptance tests mirror `security.origin_mode` outcomes (soft vs. hard, missing-origin carve-outs) to validate origin policy contracts.
- Fixtures `throttle_soft_label` and `throttle_hard_fail_retry_after` exercise the throttle soft-label, hard-fail, and `Retry-After` handling paths defined by `Security::token_validate()` and [Security → Throttling](electronic_forms_SPEC.md#sec-throttling).
- Hidden-mode NCID fallback when allowed; no rotation before success.
- Regex guards for tokens/EIDs run before disk.
- Changing YAML regenerates matrices and breaks tests until helper behavior matches.
- Storage integration tests simulate partial writes and concurrent mint attempts to prove non-atomic persistence fails (e.g., leave truncated JSON) and therefore enforce the atomic write contracts above, including throttle file persistence/GC behavior from [Security → Shared lifecycle and storage contract](electronic_forms_SPEC.md#sec-shared-lifecycle).

---

## Phase 3: Template preflight & schema tooling

**Goal:** Make template validation deterministic before runtime so deployments catch schema mismatches, missing row groups, and envelope violations during CI instead of in production flows.

**Delivers**

- `TemplateValidator` preflight covering field definitions, row-group constraints, and envelope rules.
- Manifest/schema source of truth for template metadata referenced by Renderer, SubmitHandler, and challenge flows; runtime uses the preflighted manifest only.
- TemplateContext outputs enumerated and persisted per [Template Model → Row groups (§5.2)](#sec-template-row-groups) and [Template Model → Template JSON (§5.3)](#sec-template-json), covering descriptors, `max_input_vars_estimate`, sanitized `before_html`/`after_html` fragments, `display_format_tel` tokens, and other canonical fragments consumed by runtime components. TemplateContext also computes the `has_uploads` flag defined in [Template Model → TemplateContext (§5.8)](#sec-template-context) so upload gating is deterministic downstream.
- TemplateValidator and TemplateContext ingest the `rules[]` envelope defined in [Template Model → Template JSON (§5.3)](#sec-template-json) and expose the bounded cross-field rules required by [Validation → Cross-field rules (§10)](#sec-cross-field-rules).
- TemplateContext normalizes and persists the canonical template version per [Template Model → Versioning & cache keys (§5.6)](#sec-template-versioning), storing `version` (falling back to `filemtime()` when omitted) for cache keys and Renderer/SubmitHandler success/log metadata.
- CLI/CI wiring that fails builds when templates drift from the canonical schema or omit required rows/fields.
- Ship default template assets in `/templates/forms/` and `/templates/email/` so deployments have ready-to-use form and email examples, including `templates/forms/quote-request.json`, `templates/forms/contact.json`, and `templates/forms/eforms.css`.
- Developer ergonomics: actionable diagnostics, anchor links back to spec sections, fixtures for regression tests.

**Acceptance**

- Golden fixtures for representative templates (hidden, cookie, NCID, uploads) pass preflight.
- Schema drift or missing sections produce stable error codes/messages.
- Renderer/SubmitHandler rely exclusively on the validated manifest (no ad-hoc template parsing at runtime).
- TemplateValidator sanitizes `before_html`/`after_html` via `wp_kses_post`, persists the canonical markup, and exposes TemplateContext fields (descriptors, `max_input_vars_estimate`, sanitized fragments, telephone formatting tokens) required by [Template Model → Template JSON (§5.3)](#sec-template-json) and [Template Model → display_format_tel tokens (§5.4)](#sec-display-format-tel).
- Acceptance coverage ensures fixtures assert the normalized version surfaces to Renderer, SubmitHandler, and logging consumers per `TemplateContext::normalize_version`.

---

## Phase 4: Uploads subsystem (policy, finfo, retention)

**Goal:** Implement the uploads pipeline end-to-end so token minting, MIME sniffing, retention, and garbage collection (GC) enforcement match the normative uploads spec before POST orchestration depends on it.

**Delivers**

- Accept-token generation/verification consistent with uploads matrices and `uploads.*` config (size caps, ttl, allowed forms).
- Upload bootstrap honors the TemplateContext `has_uploads` gate before initializing `finfo` handlers or touching storage, following [Uploads (Implementation Details) (§18)](#sec-uploads).
- Enforce [Uploads → Filename policy (§18.3)](#sec-uploads-filenames):
  - Strip paths, NFC-normalize, and sanitize names (control-character removal, whitespace/dot collapse) before persistence.
  - Block reserved Windows names and deterministically truncate to `uploads.original_maxlen`.
  - Transliterate to ASCII when `uploads.transliterate=true`; otherwise retain UTF-8 and emit RFC 5987 `filename*`.
  - Persist stored filenames as `{Ymd}/{original_slug}-{sha16}-{seq}.{ext}` so hashed paths remain stable across retries.
- Storage layout honoring `{h2}` sharding, `0700/0600` permissions, retention windows, and opportunistic GC on GET and shutdown.
  - Plugin never schedules WP-Cron; ship an idempotent `wp eforms gc` WP-CLI command so operators can wire real cron if desired.
  - Single-run lock (e.g., `${uploads.dir}/eforms-private/gc.lock`) prevents overlapping runs.
  - Bounded deletes cap files/time per pass and resume on subsequent GC opportunities.
  - Liveness checks skip unexpired EIDs/hidden tokens and ledger `.used` files; success tickets only purge after TTL expiry.
  - Dry-run mode lists candidate counts and bytes without deleting.
  - Observability: log GC summaries (scanned/deleted/bytes) at `info`.
  - `finfo`, extension, and accept-token metadata must agree before persistence per [Uploads → Filename policy (§18.3)](#sec-uploads-filenames) (uploads tri-agreement).
- Bootstrap defines `EFORMS_FINFO_UNAVAILABLE` when PHP `finfo`/extension metadata are unavailable and deterministically rejects uploads per [Uploads → Filename policy (§18.3)](#sec-uploads-filenames).
- Upload-specific logging and throttling hooks surfaced to the validation pipeline.

**Acceptance**

- Fixtures covering allowed/blocked MIME types, oversize payloads, expired tokens, and retention expiry.
- Garbage-collection tooling deletes expired assets without touching active submissions.
- Upload POST paths integrate with `Security::token_validate()` outputs without bypassing snapshot/config rules.
- Reject when any of finfo/extension/accept-token disagree; log `EFORMS_ERR_UPLOAD_TYPE`.
- Acceptance tests cover the `has_uploads` gate so finfo/storage initialization occurs only when templates flag uploads per [Uploads (Implementation Details) (§18)](#sec-uploads).
- Filename normalization fixtures cover sanitization, reserved-name blocking, transliteration toggles, and hashed path persistence per [Uploads → Filename policy (§18.3)](#sec-uploads-filenames).
- Bootstrap guard tests assert `EFORMS_FINFO_UNAVAILABLE` is defined and upload attempts fail when finfo metadata is unavailable per [Uploads → Filename policy (§18.3)](#sec-uploads-filenames).
- Packaging checks confirm the plugin bundle ships `templates/forms/quote-request.json`, `templates/forms/contact.json`, and `templates/forms/eforms.css` so CI can assert their presence.

---

## Phase 5: Ledger reservation (exclusive-create) + error semantics

**Goal:** Canonical duplicate suppression across all flows with strict sequencing before side effects.

**Delivers**

- Exclusive-create `…/ledger/{form_id}/{h2}/{submission_id}.used` via `fopen('xb')`.
- On `EEXIST` or any filesystem failure → treat as duplicate; log `EFORMS_LEDGER_IO`; abort side effects.
- Reservation happens **immediately before side effects** (email, moves), after normalization/validation.
- Email failure carve-out (see Phase 8): rollback (`unlink` the `.used`) so retry with same identifier is allowed.

**Acceptance**

- Race tests for duplicate POSTs.
- Honeypot burns reserve identical ledger entry.
- Submission IDs are colon-free.

---

## Phase 6: `/eforms/prime` endpoint (single source of positive `Set-Cookie`) {#phase-6}

**Goal:** Own mint/refresh and the positive `Set-Cookie` decision using the **unexpired match** rule; keep helpers header-agnostic.

**Dependencies:** Relies on the helper contracts finalized in [Phase 2](#phase-2) for `mint_cookie_record()` semantics and must precede [Phase 10](#phase-10) so slot unioning builds on a stable `/eforms/prime` flow.

**Delivers**

- Calls `Config::get()` then `mint_cookie_record(form_id, slot?)`.
- Load/update record; **Set-Cookie decision**:
  - **Send** positive `Set-Cookie` when request **lacks an unexpired match** (mint/remint; expired/missing record; cookie omitted/malformed/mismatched).
  - **Skip** only when an identical, unexpired cookie is present (same Name/Value/Path/SameSite/Secure).
- Set-Cookie attrs (normative): `Path=/`, `Secure` (HTTPS only), `HttpOnly`, `SameSite=Lax`, `Max-Age` = TTL on mint, or remaining lifetime on reissue.
- Response: `204` + `Cache-Control: no-store`.
- No header emission elsewhere except deletion per matrices (rerender/PRG).
- Slot unioning persists `slots_allowed`/`slot` using the same atomic write contract (no `issued_at/expires` rewrite) per [Security → Cookie-mode contract → Prime endpoint semantics](electronic_forms_SPEC.md#sec-cookie-mode).

**Acceptance**

- Matrix-conformant behavior for cookie-less hits.
- Cookie-less hit reissues positive `Set-Cookie`.
- Identical, unexpired cookie ⇒ skip header emission.
- Reissue uses remaining-lifetime (`record.expires - now`) for `Max-Age`.
- Renderer never emits Set-Cookie; `/eforms/prime` not called synchronously on GET.
- Never rewrite `issued_at/expires` on hit; only slot unioning is persisted here later (Phase 10).
- Tests for attribute equality & remaining-lifetime logic.
- Concurrency tests for `/eforms/prime` simulate partial slot unions and concurrent updates to ensure atomic persistence prevents truncated or reverted `slots_allowed` state.

---

## Phase 7A: Renderer & SubmitHandler Core {#phase-7a}

**Goal:** Ship deterministic GET and POST lifecycles that exercise frozen configuration snapshots, renderer priming, and POST orchestration without yet layering challenge or PRG semantics.

**Unblocks:** [Phase 6](#phase-6) confirmation tests and [Phase 10](#phase-10) slots UX once rerender contracts stabilize.

**Delivers**

- **Renderer (GET)**
	- Hidden-mode: embed payload from `mint_hidden_record()` including the normative `token`, `instance_id`, and `timestamp` hidden fields.
	- Cookie-mode: deterministic markup plus prime pixel `/eforms/prime?f={form_id}[&s={slot}]`; **renderer never emits Set-Cookie**.
	- Prime pixel only (no synchronous `/eforms/prime`); follow-up navigation performs the mint.
	- Shortcode `[eform id="…"]` `cacheable=true|false` switch controls hidden-mode vs. cookie-mode rendering per [Request Lifecycle → GET (§19)](electronic_forms_SPEC.md#sec-request-lifecycle-get).
	- Log and optionally surface the `max_input_vars` advisory per [Request lifecycle → GET (§19)](#sec-request-lifecycle-get).
	- Honor `html5.client_validation`: when `true`, omit `novalidate` and retain required/pattern attributes so browsers run native checks; when `false`, add `novalidate` and suppress native validation UI so server-side errors remain authoritative.
- WordPress shortcode and template tag entry points bootstrap through the frozen configuration snapshot and document caching guidance, including `Vary: Cookie` scoped to `eforms_s_{form_id}`.
- **SubmitHandler (POST)**
	- Orchestrates Security → Normalize → Validate → Coerce → Ledger before any side effects.
	- Enforces cookie handling and NCID transitions per matrices; **no mid-flow mode swaps**.
	- Error rerenders reuse persisted records, emit `Cache-Control: private, no-store`, and honor the NCID rerender contract (delete + re-prime when specified).
	- Wires spam headers (`X-EForms-Soft-Fails`, `X-EForms-Suspect`) and subject tagging while respecting `spam.soft_fail_threshold` outcomes.
- Anti-abuse helpers: honeypot modes, minimum-fill timers, `js_ok`, and `max_form_age` soft enforcement with explicit helper contracts and fixtures, including `stealth_success` flows emitting `X-EForms-Stealth: 1` and logging `stealth:true` per [Security → Honeypot (§7.2)](electronic_forms_SPEC.md#sec-honeypot). When `security.js_hard_mode=true`, non-JS submissions hard-fail as mandated by [Security → Timing Checks (§7.3)](electronic_forms_SPEC.md#sec-timing-checks) with defaults enumerated in [Configuration → Domains, Constraints, and Defaults (§17)](electronic_forms_SPEC.md#sec-configuration).
- Success placeholder: temporary no-op banner that defers PRG semantics to [Phase 7B](#phase-7b).

**Acceptance**

- Matrix-driven GET, POST, and rerender rows for renderer and SubmitHandler flows.
- Success placeholder path covered by integration tests (banner/no-op) while PRG is deferred.
- Spam and anti-abuse helpers exercised via golden tests.
- Golden tests cover `security.js_hard_mode`, proving `js_ok` hard-fails non-JS submissions when enabled while the default configuration retains soft behavior per [Security → Timing Checks (§7.3)](electronic_forms_SPEC.md#sec-timing-checks) and [Configuration → Domains, Constraints, and Defaults (§17)](electronic_forms_SPEC.md#sec-configuration).
- Honeypot fixtures assert the `X-EForms-Stealth: 1` header and stealth logging output when `stealth_success` is configured, alongside existing coverage.
- Integration or snapshot test asserts the `max_input_vars` advisory/comment appears when the heuristic triggers.

---

## Phase 7B: Challenge & Success (PRG) {#phase-7b}

**Goal:** Layer adaptive challenge providers and full success PRG flows atop the stabilized renderer/SubmitHandler core.

**Dependencies:** Requires [Phase 7A](#phase-7a) and the `/eforms/prime` contract from [Phase 6](#phase-6).

**Delivers**

- **Challenge providers**
	- Support `turnstile`, `hcaptcha`, and `recaptcha` with server-side verification via the WP HTTP API, request parameter mapping, and timeout handling per [Adaptive challenge (§12)](#sec-adaptive-challenge).
	- Lazy-load widgets only during POST rerenders or verification passes and defer configuration reads until after the snapshot is primed (see [Adaptive challenge (§12)](#sec-adaptive-challenge) and [Lazy-load lifecycle (components & triggers) (§6.1)](#sec-lazy-load-matrix)).
	- Provide verification hooks that update `require_challenge`, respect the NCID rerender contract, and avoid hidden-token rotation before success.
	- Soft-fail when providers are misconfigured or unreachable by setting `challenge_unconfigured`, clearing `require_challenge`, and continuing via the documented soft-cookie path.
- **Success (PRG)**
	- Always `303` with `Cache-Control: private, no-store, max-age=0`, success tickets minted as `eforms_s_{form_id}` with `Path=/`, `SameSite=Lax`, HTTPS-gated `Secure`, `HttpOnly=false`, and `Max-Age=security.success_ticket_ttl_seconds`, and a server-side ticket file created at `${uploads.dir}/eforms-private/success/{form_id}/{h2}/{submission_id}.json` containing `{form_id, submission_id, issued_at}` before redirecting, all per [Success Behavior (PRG) → Canonical inline verifier flow (§13)](#sec-success-flow).
	- Follow-up GETs hit `/eforms/success-verify?eforms_submission={submission_id}`, clear the ticket and cookie, strip query params, and re-prime via the pixel.
	- Emit `Set-Cookie: eforms_eid_{form_id}; Max-Age=0` on PRG responses so rerender rows re-prime as specified; never issue positive cookies in PRG.
	- Provide NCID redirect-only override so NCID-only completions must round-trip through PRG before success surfaces.
	- Challenge and success responses continue to advertise caching guidance via `Vary: Cookie` scoped to `eforms_s_{form_id}`.

**Acceptance**

- Challenge provider outcome matrix (success, failure, soft-fail/unconfigured, provider error) for Turnstile, hCaptcha, and reCAPTCHA per [Adaptive challenge (§12)](#sec-adaptive-challenge).
- Success verifier invalidation tests ensure tickets clear on first use, create the `${uploads.dir}/eforms-private/success/{form_id}/{h2}/{submission_id}.json` ticket with the `{form_id, submission_id, issued_at}` payload, and burn down verifier state per [Success Behavior (PRG) → Canonical inline verifier flow (§13)](#sec-success-flow).
- NCID-only completions enforced via redirect-only PRG paths.

---

## Phase 8: Emailer (fatal-on-send-failure semantics)

**Goal:** Deliver mail reliably and make send failures fatal in a user-friendly, deterministic way that preserves dedupe correctness.

**Delivers**

- SMTP/PHPMailer integration; DKIM optional; strict header sanitation; plain text default; HTML allowed via config.
- Attachments policy; enforce size/count caps before send.
- Email template selection pipeline matches [`email_template`] JSON keys to `/templates/email/{name}.txt.php` and `{name}.html.php`, enforces the token set/slot handling defined in [Email Templates (Registry) (§24)](#sec-email-templates), and constrains template inputs to the canonical fields/meta/uploads summary.
- `Reply-To` header sourced from the validated field configured via `email.reply_to_field` per [Email Delivery (§14)](#sec-email).
- `email.policy` modes (`strict`, `autocorrect`) implemented per [Email Delivery (§14)](#sec-email), including autocorrect's display-only normalization (no persisted mutations).
- Staging safety (`email.disable_send`, `email.staging_redirect_to`, `X-EForms-Env: staging`, `[STAGING]` subject).
- **Failure semantics (normative):**
	- If `send()` returns false/throws: abort success PRG, surface `_global` error **“We couldn't send your message. Please try again later.”**, respond **HTTP 500**, log at `error`, **do not** mutate cookie/hidden records, **skip positive Set-Cookie**, continue emitting NCID/challenge deletion headers when matrices require them, keep original identifier for rerender, **rollback ledger reservation** so user can retry.
- Error code: `EFORMS_ERR_EMAIL_SEND`.
- Success logs at `info`.

**Acceptance**

- Transport failure tests: retries/backoff (per config), error surfaced, 500 status, ledger unreserved, no cookie changes.
- No positive Set-Cookie emitted on email-failure rerender; NCID/challenge deletion headers still fire when required by matrices.
- Fixtures/tests cover supported template inputs (fields/meta/uploads summary), token expansion for `{{field.key}}`/`{{submitted_at}}`/`{{ip}}`/`{{form_id}}`/`{{submission_id}}`/`{{slot}}`, and escape rules for text (CR/LF normalization) and HTML contexts per [Email Templates (Registry) (§24)](#sec-email-templates).
- Acceptance tests cover both `email.policy` modes (`strict`, `autocorrect`)—including autocorrect's display-only normalization—and verify `Reply-To` resolution via `email.reply_to_field`.

---

## Phase 9A: Logging & Privacy {#phase-9a}

**Goal:** Provide observability aligned with §§15–16 while honoring privacy commitments and correlation requirements.

**Delivers**

- Logging modes and retention:
- `jsonl` / `minimal` / `off` pipelines with rotation/retention controls and `logging.level`, `logging.pii`, `logging.headers` toggles.
- Request correlation id `request_id` (filter → headers → UUIDv4) emitted on every log event, including email-failure paths.
- Optional Fail2ban emission writing to `logging.fail2ban.file` under `${uploads.dir}`, rotating alongside JSONL while remaining independent of `logging.mode`.
- Level-gated diagnostics per [Logging (§15)](electronic_forms_SPEC.md#sec-logging):
- `logging.on_failure_canonical=true` unlocks canonical field name/value emission for rejected inputs only.
- Level 2 appends the `desc_sha1` descriptor fingerprint to JSONL/minimal logs so fixtures can assert descriptor stability.
- Privacy & IP policy: `none|masked|hash|full` handling, trusted proxy evaluation, and consistent presentation across emails and logs.

**Acceptance**

- Redaction and rotation snapshots that verify PII handling across `jsonl`, `minimal`, and `off` configurations.
- Error events assert presence of `request_id`.
- Logging fixtures cover `logging.on_failure_canonical` by asserting canonical field outputs appear only on rejection when the toggle is enabled.
- Proxy resolution fixtures exercise `privacy.client_ip_header` and `privacy.trusted_proxies` in CI, covering untrusted `X-Forwarded-For` spoofing, trusted proxy chains, and private-IP fallbacks per [Privacy and IP Handling (§16)](electronic_forms_SPEC.md#sec-privacy).
- Masked/hash/full mode tests confirm the resolved client IP propagates to logs and emails with the expected redaction per [Privacy and IP Handling (§16)](electronic_forms_SPEC.md#sec-privacy).
- Level 2 logging tests assert `desc_sha1` emission across sinks.

---

## Phase 9B: Validation, Assets & RuntimeCap {#phase-9b}

**Goal:** Finalize the end-to-end validation and runtime experience consistent with §§8–11, 20, and 22.

**Dependencies:** Requires [Phase 9A](#phase-9a).

**Delivers**

- Full validation pipeline: normalize → validate → coerce with stable error codes, redirect safety (§9), suspect handling (§10), throttling (§11), and rerender metadata per [Error handling (§20)](#sec-error-handling).
- Private `HANDLERS` registries (validators, normalizers/coercers, renderers, uploads) with spec-aligned `resolve()` helpers so runtime lookups stay centralized and pluggable without bypassing the authoritative tables.
- RuntimeCap enforcement that clamps POST bodies using `security.max_post_bytes`, PHP INI (`post_max_size`, `upload_max_filesize`), and `uploads.*` overrides while guarding `CONTENT_LENGTH` and coordinating with upload slot validation (see [POST Size Cap (§6)](#sec-post-size-cap)).
- Assets and accessibility: enqueue scripts/styles only during rendering, deliver JS usability helpers, and implement accessibility focus/error summary guidance per [Accessibility (§12)](#sec-accessibility) and [Assets (§22)](#sec-assets), including label/control associations, fieldset/legend grouping, role/ARIA obligations for the error summary, and focus-management rules when native validation remains enabled (`html5.client_validation=true`): skip pre-submit summary focus to avoid double focus, yet still move focus to the first invalid control after server rerenders.
- Sanitize `textarea_html` via `wp_kses_post` and enforce the post-sanitize size bound per [Special Case: HTML-Bearing Fields](docs/electronic_forms_SPEC.md#sec-html-fields).
- SubmitHandler enforces the bounded cross-field rules defined in [Validation → Cross-field rules (§10)](#sec-cross-field-rules).

**Acceptance**

- Oversized and boundary payload fixtures covering RuntimeCap clamps, validation errors, and `CONTENT_LENGTH` guards.
- Accessibility tests cover focus management alongside markup requirements: labels tied to controls, grouped inputs wrapped in fieldset/legend, and role="alert" summary behavior.
- Tests cover each bounded cross-field rule type defined in [Validation → Cross-field rules (§10)](#sec-cross-field-rules).
- Registry fixtures assert lazy initialization and lookup failure paths for each `HANDLERS` table so the registries remain the single source of truth for validator, normalizer/coercer, renderer, and upload resolution.

---

## Phase 10: Slots (unioning & enforcement) {#phase-10}

**Goal:** Add slot semantics **after** core cookie flow is stable; keep unioning isolated to `/eforms/prime` and validation to POST.

**Dependencies:** Extends the `/eforms/prime` storage writes from [Phase 6](#phase-6); do not start until that endpoint ships the unexpired-match contract.

**Delivers**

- `/eforms/prime`: `slots_allowed ∪ {s}` (when allowed), derive `slot` when union size is 1; **do not** rewrite `issued_at/expires`.
- Renderer: deterministic slot selection per GET; surplus instances slotless.
- POST enforcement: when slotted, require posted integer slot ∈ allowed set and consistent with record; otherwise hard fail; slotless deployments reject posted slot.
- Submission ID shape in cookie mode when slotted: `eid__slot{n}`.

**Acceptance**

- Multi-instance page tests: deterministic, non-overlapping slots; surplus slotless.
- POST with wrong/missing slot → `EFORMS_ERR_TOKEN`.
- Rerender rows preserve deterministic slot & follow delete+re-prime contract.
- Global slots disabled ⇒ posted `eforms_slot` hard-fails.
- Posted slot must exist in config allow-list and the record's `slots_allowed`.

---

## Phase 11: Ops & CI Tooling {#phase-11}

**Goal:** Deliver operational safeguards and automation so supported environments stay compliant without manual babysitting.

**Dependencies:** Requires [Phase 7B](#phase-7b) and [Phase 9B](#phase-9b).

**Delivers**

- Compatibility checks across supported PHP and WordPress versions wired into CI.
- WP-CLI smoke tests covering Origin policy enforcement and RuntimeCap clamps, runnable locally and in CI.
- Uninstall/purge guard implementations plus retention hooks that honor configuration toggles and prevent destructive runs without the documented flags.
- Sample configuration diagnostics that surface misconfigurations and retention statuses for operators.
- Admin environment guard surfaces an actionable notice and auto-deactivates when PHP < 8.0 or WordPress < 5.8 per [Compatibility (§21)](electronic_forms_SPEC.md#sec-compatibility).

**Acceptance**

- CLI smoke tests execute in CI, asserting Origin policy and RuntimeCap outcomes.
- Automated coverage exercises uninstall guards and retention hooks.
- Environment fixtures prove PHP < 8.0 or WordPress < 5.8 trigger the admin notice and immediate deactivation required by [Compatibility (§21)](electronic_forms_SPEC.md#sec-compatibility).

---

## Cross-phase Guarantees & Matrices Conformance

- **Canonical sources:**
- Cookie policy outcomes (§7.1.3.2), Cookie-mode lifecycle (§7.1.3.3), Cookie header actions (§7.1.3.5), NCID rerender lifecycle (§7.1.4.2).
- Implementation and tests must treat generated tables as higher authority than narrative.
- **Matrices conformance harness:** CI tests load generated tables and assert behavior so spec/YAML changes fail when implementations drift.
- **Header boundary:** Only `/eforms/prime` emits positive `Set-Cookie`; deletion headers occur on rerender & PRG rows as specified; no positive header in PRG.
- **No rotation before success:** Identifiers (hidden/cookie/NCID) remain pinned until the success path triggers documented rotations.
- **Security invariants:** Regex guards before disk; tamper paths hard-fail; error rerenders reuse persisted records; NCID fallbacks preserve dedupe semantics; enforce origin-only CSRF boundary; audit cookie attributes (Path=/, SameSite=Lax, Secure on HTTPS, HttpOnly).
- **CI scaffolding checks:**
	- Config defaults parity test: CI loads the §17 configuration table and diffs it against `Config::DEFAULTS` (key coverage plus clamp/enum metadata) so spec/implementation drift fails fast; wire this parity check into phpunit/spec-lint as part of the build.【F:docs/electronic_forms_SPEC.md†L922-L992】
	- Descriptor resolution test proves every handler surfaced by `Spec::typeDescriptors()` is callable, preventing drift between matrices and helper implementations.【F:docs/electronic_forms_SPEC.md†L1116-L1117】
	- Schema parity diff regenerates JSON Schema from `TEMPLATE_SPEC` (or vice versa) and fails when enums, required fields, or shapes diverge from the canonical manifest.【F:docs/electronic_forms_SPEC.md†L1117-L1118】
	- Determinism validation replays a fixed template + inputs and asserts identical error ordering, canonical values, and rendered attribute sets for every run.【F:docs/electronic_forms_SPEC.md†L1117-L1118】
	- TTL alignment asserts minted records respect `security.token_ttl_seconds` and success tickets honor `security.success_ticket_ttl_seconds`.【F:docs/electronic_forms_SPEC.md†L1118-L1119】
- **WP-CLI smoke coverage:** Enumerate the commands required to exercise the Origin policy and RuntimeCap checks so CI can invoke them directly, matching the §23 CI scaffolding guidance for WP-CLI smoke tests.【F:docs/electronic_forms_SPEC.md†L1117-L1119】

**Acceptance**

- Documented WP-CLI command issues a POST without an `Origin` header and asserts the hard/missing Origin policy response (non-zero exit plus the documented error code/message).
- Documented WP-CLI command submits an oversized payload and asserts RuntimeCap enforcement (non-zero exit, clamped request reported, and the expected RuntimeCap error surface).
