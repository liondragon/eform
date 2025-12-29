electronic_forms - Spec
================================================================
<!-- SPEC_STATUS: BOOTSTRAP -->

<a id="sec-objective"></a>
1. OBJECTIVE
- Build a dependency-free, lightweight plugin that renders and processes multiple forms from JSON templates with strict DRY principles and a deterministic pipeline.
- Designed for real-world operation today (internal use across a handful of sites, modest daily volume) while laying durable groundwork for higher volume growth without redesign.
- Targets publicly accessible contact forms without authenticated sessions. Cache-friendly and session-agnostic: WordPress nonces are never used. The plugin does not implement authenticated user flows, account dashboards, or account management surfaces.
- Primary outcomes:
  - Prevent bot submissions and provide strong spam resistance.
  - Prevent accidental duplicate submissions (double-click, back/refresh, retries) via one-time token + ledger dedupe.
  - Support both long-cached form pages (CDN cached for days) and non-cached pages.
  - Support multiple forms per page. It doesn't need to support multiple intances of the same form on a single page for simplicity.
- Out of scope: multi-step or multi-page wizards/questionnaires, and any flows that depend on persistent user identity beyond a single submission.
- No admin UI.
- Platform requirements: see [Compatibility and Updates](#sec-compatibility).
- Focus on simplicity, elegancy, and efficiency; avoid overengineering. Easy to maintain and performant for the intended workloads.
- Lazy by design: the configuration snapshot is bootstrapped lazily on first access. Entry points (Renderer/SubmitHandler/challenge verifiers/Emailer/`/eforms/mint`) SHOULD call `Config::get()` early for clarity and predictable timing, but correctness does not rely on it because Security helpers also call `Config::get()` defensively as a backstop (see [Central Registries → Lazy-load Matrix](#sec-lazy-load-matrix) and [Configuration: Domains, Constraints, and Defaults](#sec-configuration).)
- No database writes; file-backed one-time token ledger for duplicate-submit prevention (no Redis/queues).
- Clear boundaries: render vs. validate vs. send vs. log vs. upload.
- Deterministic pipeline and schema parity: big win for testability.
- Lazy loading of registries/features and config snapshot: keeps coupling down.

<a id="sec-scope"></a>
2. SCOPE
	1. IN
		- Render forms
		- Normalization / validation / coercion
		- HTML sanitization for template fragments (before_html / after_html)
		- Email submission
		- Success handling (inline PRG or redirect)
		- Anti-spam
		- Accessibility
		- File logging
		- Basic CSS/JS enqueue
	2. OUT
		- Admin pages
		- External libraries
		- Internationalization
		- HMAC
		- Dynamic form rendering via magic call()
		- Multisite support
	3. Deployment profiles (defaults vs. opt-ins)
	- Baseline defaults (minimal ops):
		- ships with conservative security defaults and minimal operational overhead; see [Configuration: Domains, Constraints, and Defaults](#sec-configuration) for the authoritative configuration table and exact default values.
	- Opt-ins (enable only if needed):
		- Throttling, adaptive/always challenge
		- Fail2ban emission
		- Rejected-submission logging → set logging.mode="jsonl" (or "minimal") and logging.level>=1
		- Header logging, PII logging
- Operational profile: Cacheable pages require JS and use a JS-minted submission token via `/eforms/mint` (no anti-duplication cookies). Non-cacheable pages embed a server-minted hidden token during GET render.
<a id="sec-architecture"></a>
3. ARCHITECTURE AND FILE LAYOUT
	- /electronic_forms/
		- eforms.php		// bootstrap + autoloader + shortcode/template tag
		- uninstall.php	 // optional purge of uploads/logs (reads flags from Config; see [Configuration: Domains, Constraints, and Defaults](#sec-configuration))
			- uninstall.php requires __DIR__.'/src/Config.php' and calls Config::bootstrap() so it can read purge flags without relying on WP hooks.
			- Hardening (normative): uninstall.php must start with defined('WP_UNINSTALL_PLUGIN') || exit; and guard WP calls:
				- if (!function_exists('wp_upload_dir')) { require_once ABSPATH.'wp-admin/includes/file.php'; }.
				- If wp_upload_dir() still isn’t available, abort uninstall gracefully.
				- Ship index.html and server deny rules (.htaccess, web.config) in templates directories; enforce filename allow-list and prevent traversal outside /templates/.
	- /src/
		- Config.php, Helpers.php, Logging.php
		- Email/, Rendering/, Submission/, Validation/, Security/, Uploads/
	- /schema/
		- template.schema.json	// design-time only (editor/CI lint); kept in sync with PHP spec
	- /templates/
		- forms/		// default JSON templates that ship with the plugin
		- email/		// default email bodies that ship with the plugin
	- /assets/
		- forms.css	 // namespaced styles
		- forms.js		// JS marker (js_ok), error-summary/first-invalid focus, submit lock, spinner

<a id="sec-dry-principles"></a>
4. DRY PRINCIPLES (SINGLE SOURCES OF TRUTH)
- Determinism. Given the same template and inputs, the pipeline produces identical canonical outputs, including error ordering.
- Centralize (validator defaults + tiny helpers used by renderer):
	- Field type traits & defaults (is_multivalue, default max_length, allowed URL schemes, canonicalization toggles)
	- id/name generation (append [] only when multivalue)
	- Escape/URL helpers (renderer only)
	- Security gate (single entry point)
	- Option rendering and option-key storage semantics
	- Upload policy (accept tokens/mimes/exts; per-file/field/request caps)
	- Row group stack handling (balanced start/end)
	- Accept token -> MIME/extension registry (conservative mappings)
- Structural spec (PHP): The PHP `TEMPLATE_SPEC` array (defined in `src/Validation/TemplateValidator.php`) is the sole canonical definition for enums/required/unknown-key rules

<a id="sec-anchors"></a>
5. ANCHORS (AUTHORITATIVE NUMERIC CONSTRAINTS)

These anchors define the canonical numeric limits, TTLs, and bounds referenced throughout the spec. When defaults or constraints need to change, update the anchor value here first, then ensure all references remain consistent.

| Anchor | Value | Used for |
|--------|-------|----------|
| `[MIN_FILL_SECONDS_MIN]` | 0 | Lower bound for `security.min_fill_seconds` |
| `[MIN_FILL_SECONDS_MAX]` | 60 | Upper bound for `security.min_fill_seconds` |
| `[TOKEN_TTL_MIN]` | 1 | Lower bound for `security.token_ttl_seconds` |
| `[TOKEN_TTL_MAX]` | 86400 | Upper bound for `security.token_ttl_seconds` (1 day) |
| `[MAX_FORM_AGE_MIN]` | 1 | Lower bound for `security.max_form_age_seconds` |
| `[MAX_FORM_AGE_MAX]` | 86400 | Upper bound for `security.max_form_age_seconds` (1 day) |
| `[LEDGER_GC_GRACE_SECONDS]` | 3600 | Grace window before deleting expired ledger `.used` markers |
| `[CHALLENGE_TIMEOUT_MIN]` | 1 | Lower bound for `challenge.http_timeout_seconds` |
| `[CHALLENGE_TIMEOUT_MAX]` | 5 | Upper bound for `challenge.http_timeout_seconds` |
| `[THROTTLE_MAX_PER_MIN_MIN]` | 1 | Lower bound for `throttle.per_ip.max_per_minute` |
| `[THROTTLE_MAX_PER_MIN_MAX]` | 120 | Upper bound for `throttle.per_ip.max_per_minute` |
| `[THROTTLE_SOFT_THRESHOLD]` | 0.7 | Soft throttle signal threshold (fraction of `throttle.per_ip.max_per_minute`) |
| `[THROTTLE_COOLDOWN_MIN]` | 10 | Lower bound for `throttle.per_ip.cooldown_seconds` |
| `[THROTTLE_COOLDOWN_MAX]` | 600 | Upper bound for `throttle.per_ip.cooldown_seconds` (10 minutes) |
| `[LOGGING_LEVEL_MIN]` | 0 | Lower bound for `logging.level` (errors only) |
| `[LOGGING_LEVEL_MAX]` | 2 | Upper bound for `logging.level` (errors + warnings + info) |
| `[RETENTION_DAYS_MIN]` | 1 | Lower bound for `logging.retention_days` and `logging.fail2ban.retention_days` |
| `[RETENTION_DAYS_MAX]` | 365 | Upper bound for `logging.retention_days` and `logging.fail2ban.retention_days` (1 year) |
| `[MAX_FIELDS_MIN]` | 1 | Lower bound for `validation.max_fields_per_form` |
| `[MAX_FIELDS_MAX]` | 1000 | Upper bound for `validation.max_fields_per_form` |
| `[MAX_OPTIONS_MIN]` | 1 | Lower bound for `validation.max_options_per_group` |
| `[MAX_OPTIONS_MAX]` | 1000 | Upper bound for `validation.max_options_per_group` |
| `[MAX_MULTIVALUE_MIN]` | 1 | Lower bound for `validation.max_items_per_multivalue` |
| `[MAX_MULTIVALUE_MAX]` | 1000 | Upper bound for `validation.max_items_per_multivalue` |

<a id="sec-template-model"></a>
6. TEMPLATE MODEL

### 6.1 Field descriptors and namespacing {#sec-template-model-fields}
> **Contract — TemplateValidator::validate_fields**
>	- Inputs:
>	- Template field entries may declare `key`, `type`, `label?`, `placeholder?`, `required?`, `size?` (text-like only: `text`, `tel`, `url`, `email`), `autocomplete?`, `options?` (for radios/checkboxes/select), `class?`, `max_length?`, `min?`, `max?`, `step?`, `pattern?`, `before_html?`, and `after_html?`.
>	- Each entry MUST include a `key` slug matching `/^[a-z0-9_-]{1,64}$/` (lowercase). Square brackets are prohibited to prevent PHP array collisions, and reserved keys remain disallowed.
>	- `autocomplete` accepts exactly one token: `on`, `off`, or a WHATWG token such as `name`, `given-name`, `family-name`, `email`, `tel`, `postal-code`, `street-address`, `address-line1`, `address-line2`, or `organization`.
>	- `size` ranges from 1–100 and only applies to text-like controls.
>	- Side-effects:
>	- TemplateValidator sanitizes `before_html` and `after_html` via `wp_kses_post`; the sanitized string becomes canonical, inline styles are forbidden, and fragments may not cross `row_group` boundaries.
>	- Invalid `autocomplete` tokens are dropped during normalization, template-provided classes are preserved verbatim, and per-field upload overrides are merged into descriptor metadata.
>	- TemplateValidator enforces the reserved-key list so authors cannot collide with `form_id`, `instance_id`, `submission_id`, `eforms_token`, `eforms_hp`, `eforms_mode`, `timestamp`, `js_ok`, `eforms_email_retry`, `ip`, or `submitted_at`.
>	- Returns:
>	- `FormRenderer` emits `<form class="eforms-form eforms-form-{form_id}">` with `eforms_mode` metadata and delegates security fields to [Security → Submission Protection for Public Forms](#sec-submission-protection).
>	- Renderer-generated attributes follow the table below so markup mirrors validator bounds and deterministic per-instance metadata.
>	- `include_fields` may reference template keys plus the meta keys `ip`, `submitted_at`, `form_id`, `instance_id`, and `submission_id`.
>	- Upload descriptors (`type=file|files`) may override `accept[]`, `max_file_bytes`, `max_files` (for `files`), and `email_attach` (bool); overrides shadow global defaults without relaxing enforcement.
>	- Client-side hints (inputmode, pattern, accept tokens, `enterkeyhint`) follow the descriptor defaults summarized in the hint table below.
>	- Failure modes:
>	- Slugs outside the allowed regex, duplicate keys, or reserved names produce deterministic TemplateValidator schema errors (e.g., `EFORMS_ERR_SCHEMA_DUP_KEY`, `EFORMS_ERR_SCHEMA_KEY`), preventing render.
>	- HTML fragments that attempt inline styles or cross `row_group` boundaries are rejected during structural preflight.
>	- Upload overrides whose `accept[]` tokens fall outside the global allow-list fail validation, triggering `EFORMS_ERR_ACCEPT_EMPTY` per [Uploads → Accept-token policy](#sec-uploads-accept-tokens).

| Concern | Emission rule | Notes / exceptions |
|---------|---------------|-------------------|
| `id` | All modes: `{form_id}-{field_key}`. | Deterministic from template; no runtime dependency. |
| `name` | `{form_id}[{field_key}]` | Append `[]` only for multivalue descriptors (checkbox groups, multi-select). |
| `required` | Mirrors `required: true|false`. | UX hint only; server validation is authoritative. |
| Attribute mirrors | Renderer mirrors validator bounds into HTML attributes. | Includes `maxlength`, `minlength`, and numeric/date `min`/`max`/`step` where applicable. Some types may enforce fixed hints (see [Built-in Field Types (Defaults; US-focused)](#sec-field-types)). |
| Editing aids | Per-type helpers emit `inputmode`, `pattern`, and related typing aids. | See [Built-in Field Types (Defaults; US-focused)](#sec-field-types). |
| Upload affordances | `accept` reflects descriptor accept tokens (`image` / `pdf`). | Upload hint values never weaken server caps; upload-specific overrides remain validated (see [Uploads → Accept-token policy](#sec-uploads-accept-tokens)). |
| `enterkeyhint` | `"send"` on the last text-like control or `<textarea>` in DOM order. | Advisory only; does not affect validation or submission flow. |
| `multiple` | Emitted for `type=files` and multivalue selects/checkboxes | Mirrors `is_multivalue` from the descriptor; see [Built-in Field Types](#sec-field-types). |

### 6.2 Row groups (structured wrappers) {#sec-template-row-groups}
> **Contract — TemplateValidator::validate_row_groups**
>	- Inputs:
>	- Pseudo-fields use `type:"row_group"` with `{ mode:"start"|"end", tag:"div"|"section" (default `div`), class? }`.
>	- Row-group objects omit `key`, carry no submission data, and may be nested.
>	- Side-effects:
>	- `FormRenderer` adds a base wrapper class (for example `eforms-row`) to each emitted group and maintains a stack so dangling opens are auto-closed at form end to keep the DOM valid.
>	- TemplateValidator enforces `additionalProperties:false` for row-group objects to block unexpected keys.
>	- Returns:
>	- Row groups never count toward `validation.max_fields_per_form` and exist purely to organize markup.
>	- Failure modes:
>	- Any unbalanced stack (dangling opens at EOF or an `end` with an empty stack) emits a single global config error `EFORMS_ERR_ROW_GROUP_UNBALANCED` and prevents render.

### 6.3 Template JSON {#sec-template-json}
> **Contract — TemplateValidator::validate_template_envelope**
>	- Inputs:
>	- Templates live in `/templates/forms/` with filenames matching `/^[a-z0-9-]+\.json$/`.
>	- Authors may include a design-time schema pointer (recommended) using a stable URL or absolute path (avoid hard-coded `/wp-content/plugins/...` paths).
>	- Side-effects:
>	- None beyond normalization; runtime loads files lazily and never modifies them in place.
>	- Returns:
>	- Minimal shape includes `id` (slug), `version` (string), `title` (string), `success { mode:"inline"|"redirect", redirect_url?, message? }`, `email { to, subject, email_template, include_fields[], display_format_tel? }`, `fields[]` (see §5.1), `submit_button_text` (string), and bounded JSON `rules[]` (see §10).
>	- Failure modes:
>	- Filenames outside the allow-list are ignored. Malformed or incomplete JSON triggers a deterministic “Form configuration error” without a white screen.

### 6.4 display_format_tel tokens {#sec-display-format-tel}
> **Contract — TemplateValidator::validate_display_format_tel**
>	- Inputs:
>	- `email.display_format_tel` selects the formatting token applied to telephone values in email summaries.
>	- Side-effects:
>	- TemplateValidator enforces the enumerated list and retains the sanitized token in the TemplateContext.
>	- Returns:
>	- Allowed values: `"xxx-xxx-xxxx"` (default), `"(xxx) xxx-xxxx"`, and `"xxx.xxx.xxxx"`.
>	- Failure modes:
>	- Unknown tokens are flagged during preflight and revert to the default presentation at runtime.

### 6.5 Options shape {#sec-template-options}
> **Contract — TemplateValidator::validate_field_options**
>	- Inputs:
>	- `options` arrays contain objects `{ key, label, disabled? }` for radios, checkboxes, and selects.
>	- Side-effects:
>	- TemplateValidator ensures each option object matches the declared shape and preserves author-supplied ordering.
>	- Returns:
>	- Stored submission values equal the option `key`; `label` exists only for rendering.
>	- Failure modes:
>	- Options marked `disabled:true` MUST NOT be submitted; selecting one produces a validation error. Malformed option objects raise `EFORMS_ERR_SCHEMA_OBJECT`.

### 6.6 Versioning & cache keys {#sec-template-versioning}
> **Contract — TemplateContext::normalize_version**
>	- Inputs:
>	- Templates SHOULD provide an explicit `version` string; when omitted, runtime falls back to `filemtime()`.
>	- Side-effects:
>	- Version strings feed cache keys used by TemplateContext consumers; changes force downstream caches to invalidate.
>	- Returns:
>	- The normalized version value is stored in TemplateContext and mirrored into success/logging metadata.
>	- Failure modes:
>	- None; omission simply relies on `filemtime()` which may cache-bust less predictably.

### 6.7 Validation (design-time vs. runtime) {#sec-template-validation}
> **Contract — SubmitHandler::validate_template_lifecycle**
>	- Inputs:
>	- Runtime evaluation uses two phases: `(0)` structural preflight via `TemplateValidator`, then `(1)` normalize → validate → coerce via `Validator`.
>	- `/schema/template.schema.json` exists for CI/docs only and is mechanically derived from `TEMPLATE_SPEC`.
>	- Side-effects:
>	- TemplateValidator rejects unknown keys, enum violations, malformed rule objects, and reports deterministic error codes.
>	- CI MUST validate `/templates/forms/*.json` against the schema and assert parity with the PHP `TEMPLATE_SPEC` so dual sources do not drift.
>	- Returns:
>	- On failure, runtime surfaces a clear “Form configuration error” while continuing to render a fallback UX (no WSOD). Successful normalization yields canonical field arrays reused by Renderer, Security, and Validator.
>	- Failure modes:
>	- Unknown rule values or malformed JSON raise deterministic schema errors. File/file descriptors whose `accept[]` intersection with the global allow-list is empty trigger `EFORMS_ERR_ACCEPT_EMPTY`. Invalid `email.display_format_tel` tokens are flagged here and dropped before runtime use.

### 6.8 TemplateContext (internal) {#sec-template-context}
> **Contract — TemplateContext::build**
>	- Inputs:
>	- `TemplateValidator` resolves descriptors from `TEMPLATE_SPEC`, reading handler IDs (`validator_id`, `normalizer_id`, `renderer_id`), HTML traits, validation ranges, constants, and optional `alias_of` metadata.
>	- Handler registries are private to their owning classes (see [Central Registries (Internal Only)](#sec-central-registries)) and expose `resolve()` helpers for deterministic lookup.
>	- Side-effects:
>	- Descriptor resolution is fail-fast: unknown handler IDs throw a deterministic `RuntimeException` containing `{ type, id, registry, spec_path }`, which CI surfaces immediately.
>	- Alias hygiene runs during preflight, asserting that aliases share handler IDs with their target; violations fail CI.
>	- Returns:
>	- TemplateContext exposes `has_uploads` (bool), `descriptors[]` (resolved field descriptors), `version`, `id`, `email`, `success`, `rules`, normalized `fields`, and `max_input_vars_estimate` (advisory).
>	- Each resolved descriptor includes `{ key, type, is_multivalue, name_tpl, id_prefix, html, validate, constants, attr_mirror, handlers: { v, n, r } }` and remains immutable for the request. Renderer and Validator reuse the same descriptor objects to avoid re-merging during POST.
>	- Failure modes:
>	- Attempting to resolve unknown handlers, mutate descriptors post-preflight, or violate alias invariants aborts template loading and is treated as a configuration error surfaced during CI or first render.

<a id="sec-central-registries"></a>
7. CENTRAL REGISTRIES (INTERNAL ONLY)
	- Static registries (no public filters): field_types, validators, normalizers/coercers, renderers.
	- Registries are private to each owning class and exposed only through resolve() helpers.
		- Example:
			- Validator: private const HANDLERS = ['email' => [self::class,'validateEmail'], ...]
			- Normalizer: private const HANDLERS = ['scalar' => [self::class,'normalizeScalar'], ...]
			- Renderer: private const HANDLERS = ['text' => [self::class,'emitInput'], 'textarea' => [...], ...]
			- public static function resolve(string $id): callable { if (!isset(self::HANDLERS[$id])) throw RuntimeException(...); return self::HANDLERS[$id]; }
	- Uploads registry settings: token->mime/ext expansions; image sanity; caps
	- Accept token map lives in [Uploads → Accept-token policy](#sec-uploads-accept-tokens); default tokens and the review gate are summarized in [Default accept tokens callout](#sec-uploads-accept-defaults).
	- Upload registry loads on demand when a template with file/files is rendered or posted.
	- Structural registry (TEMPLATE_SPEC) defines allowed keys, required combos, enums (implements additionalProperties:false).
	- Escaping map (per sink) to be used consistently:
		- HTML text -> esc_html
		- HTML attribute -> esc_attr
		- Textarea -> esc_textarea
		- URL (render) -> esc_url
		- URL (storage/transport) -> esc_url_raw
		- JSON/logs -> wp_json_encode
	-	<a id="sec-lazy-load-matrix"></a>Lazy-load lifecycle (components & triggers):
		| Component		| Init policy | Trigger(s) (first use) | Notes |
		|------------------|------------:|-------------------------|-------|
               | Config snapshot | Lazy | First `Config::get()` call (entry points such as `FormRenderer::render()`, `SubmitHandler::handle()`, challenge verifiers, `Emailer::send()`, and `/eforms/mint` invoke it before helpers; `/eforms/mint` SHOULD call `Config::get()` before delegating to its mint handler—helpers still no-op repeat calls) | Idempotent (per request); entry points SHOULD trigger it early by calling `Config::get()` before any helper; see [Configuration: Domains, Constraints, and Defaults](#sec-configuration) for bootstrap timing. |
		| TemplateValidator / Validator | Lazy | Rendering (GET) preflight; POST validate | Builds resolved descriptors on first call; memoizes per request (no global scans). |
		| Static registries (`HANDLERS` maps) | Lazy | First call to `resolve()` / class autoload | Autoloading counts as lazy; classes hold only const maps; derived caches compute on demand. |
		| Renderer / FormRenderer | Lazy | Shortcode or template tag executes | Enqueues assets only when form present. |
        | Security (token/origin) | Lazy | **Hidden-token mint during GET render**, any token/origin check during POST, or `/eforms/mint` JS-token mint | `FormRenderer` **delegates** all minting to Security helpers (no local UUID/TTL logic). Minting helpers never evaluate challenge and skip origin evaluation; when throttling is enabled, minting helpers also enforce rate limiting (throttle counters) before minting. SubmitHandler enforces throttling during POST validation. `/eforms/mint` enforces origin policy at the endpoint boundary. |
		| Uploads | Lazy | Template declares file(s) or POST carries files | Initializes finfo and policy only when needed. |
		| Emailer | Lazy | After validation succeeds (just before send) | SMTP/DKIM init only on send; skipped on failures. |
		| Logging | Lazy | First log write when `logging.mode != "off"` | Opens/rotates file on demand. |
		| Throttle | Lazy | When `throttle.enable=true` and key present | File created on first check. |
	       | Challenge | Lazy | Only inside entry points: (1) `SubmitHandler::handle()` after `Security::token_validate()` returns `require_challenge=true`; (2) `FormRenderer::render()` on a POST rerender when `require_challenge=true`; or (3) verification step when a Turnstile response is present (`cf-turnstile-response`). | Provider script enqueued only when rendered. Even when `challenge.mode="always_post"`, challenge MUST NOT initialize on the initial GET; it loads only on: (a) POST rerender after `Security::token_validate()` sets `require_challenge=true`, or (b) the verification step when a provider response is present. |
		| Assets (CSS/JS) | Lazy | When a form is rendered on the page | Version via filemtime; opt-out honored. |
 	
<a id="sec-security"></a>
8. SECURITY
This section defines submission-protection contracts for hidden-token and JS-minted modes.

**Rationale:** The dual-mode token system (hidden vs JS-minted) balances cacheability with security. Hidden-mode serves non-cacheable pages with server-minted tokens embedded during GET rendering, enabling simple flows without JavaScript. JS-minted mode serves CDN-cached pages where tokens are minted on-demand via `/eforms/mint`, allowing aggressive edge caching while maintaining one-time-use token protection. Both modes share the same ledger-based deduplication contract to prevent replay attacks and accidental double-submissions without requiring cookies or persistent sessions.

<a id="sec-submission-protection"></a>1. Submission Protection for Public Forms (hidden vs JS-minted)
- See [Lifecycle quickstart](#sec-lifecycle-quickstart) for the canonical render → persist → POST → rerender/success contract that governs both modes.
- This spec does not use cookie/NCID matrices; token issuance and validation are defined by the contracts below.
<a id="sec-lifecycle-quickstart"></a>7.1.0 Lifecycle quickstart (normative)
This table routes each lifecycle stage to the normative matrices that govern its behavior.
<!-- BEGIN BLOCK: lifecycle-pipeline-quickstart -->
**Pipeline-first outline (render → mint → POST → challenge → normalization → ledger → success)**

| Stage | Overview |
|-------|----------|
| Render (GET) | Non-cacheable pages embed the payload from `Security::mint_hidden_record()`. Cacheable pages render empty security inputs; JS calls `/eforms/mint` to populate them. |
| Mint (cacheable only) | `/eforms/mint` enforces origin/throttle policy and mints a token record; responses MUST send `Cache-Control: no-store` and MUST NOT set cookies. |
| POST → Security gate | `Security::token_validate()` validates the posted security inputs against persisted records and decides whether a challenge is required. |
| Challenge (conditional) | Challenge renders only on POST rerender (or when a provider response is present) and never on the initial GET. Rerenders MUST reuse the same token metadata. |
| Normalize | Every POST runs normalize → validate → coerce before side effects. |
| Ledger | Reserve `${uploads.dir}/eforms-private/ledger/{form_id}/{h2}/{submission_id}.used` immediately before side effects. Treat `EEXIST` as duplicate. |
| Success | On success, run side effects then PRG per [Success behavior](#sec-success). |

> **Contract — Security::token_validate**
>	- Inputs:
>	- POST payload (`$_POST`) and mode metadata emitted by Renderer (including `eforms_mode`). Reads the frozen configuration snapshot (`security.*`, `challenge.*`, `privacy.*`, `throttle.*`) and the persisted token records.
>	- Side-effects:
>	- None. Read-only lookups only; never mutates storage or headers.
>	- Returns:
>	- Structured result `{ mode, submission_id, token_ok, hard_fail, require_challenge, soft_reasons[] }`.
>		- `mode ∈ {"hidden","js"}`.
>		- When `token_ok=true`, `submission_id` equals the posted `eforms_token`.
>	- Failure modes:
>	- Missing/expired/invalid token is a hard failure (`EFORMS_ERR_TOKEN`) and callers MUST abort before ledger reservation.
>	- IO/read errors bubble as hard failures.
>	- Challenge-required paths set `require_challenge=true` without mutating storage.

<!-- END BLOCK: lifecycle-pipeline-quickstart -->


<a id="sec-shared-lifecycle"></a>1. Shared lifecycle and storage contract
- Mode selection stays server-owned: `[eform id="slug" cacheable="false"]` (default) renders in hidden-token mode; `cacheable="true"` renders in JS-minted mode. All markup carries `eforms_mode`, and the renderer never gives the client a way to pick its own mode.
		- Directory sharding (`{h2}` placeholder) is universal: compute `Helpers::h2($id)` — `substr(hash('sha256', $id), 0, 2)` on UTF-8 bytes — and create the `{h2}` directory with `0700` perms before writing `0600` files. The same rule covers hidden tokens, JS-minted tokens, ledger entries, and throttles.
		- Regex guards (`/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i/` tokens, `^[A-Za-z0-9_-]{22,32}$` instance IDs) run before disk access to weed out obvious tampering.
		- <a id="sec-ledger-contract"></a>Ledger reservation contract

**Rationale:** The file-based ledger uses atomic exclusive-create (`fopen('xb')`) to prevent duplicate submissions without requiring a database. By reserving the ledger marker immediately before side effects (uploads moves, email send), the system guarantees that successful submissions cannot be replayed—even if the user hits back/refresh or retries after network failure. On email failure after reservation, the reservation remains committed; the rerender mints a fresh token to enable immediate retry without reopening a race window.

				- Duplicate suppression reserves `${uploads.dir}/eforms-private/ledger/{form_id}/{h2}/{submission_id}.used` via `fopen('xb')` (or equivalent) immediately before side effects.
				- Treat `EEXIST` as a duplicate submission.
				- Treat other filesystem failures as a hard failure (`EFORMS_ERR_LEDGER_IO`) and log `EFORMS_LEDGER_IO`.
				- Ledger reservations are GC-managed. Once `${submission_id}.used` is created, no code path may delete it except `wp eforms gc`, and GC MUST NOT delete any `.used` marker that might still correspond to an acceptably fresh token (see [Uploads → GC](#sec-uploads) for eligibility rules).
				- If email send fails after reservation, the token is consumed; a fresh token is minted for the rerender to enable immediate retry (see [Email-failure recovery](#sec-email-failure-recovery)).
				- Honeypot short-circuits burn the same ledger entry, and submission IDs for all modes remain colon-free.

		- <a id="sec-security-invariants"></a>Security invariants (apply to hidden/JS-minted):
- Minting helpers are authoritative: they return canonical metadata and persist records with atomic `0700`/`0600` writes (creating `{h2}` directories as needed). `/eforms/mint` is the only endpoint permitted to mint JS-mode token records.
  - Minting helpers never evaluate challenge; they consult the configuration snapshot for TTLs and storage paths.
  - Submission identifier: when token validation succeeds, `submission_id` equals the posted `eforms_token`.
  - Error rerenders reuse the persisted token record; tokens are not rotated before success.
  - Exception: On `EFORMS_ERR_EMAIL_SEND` after ledger reservation, the token is consumed and a fresh token MUST be minted for the rerender. This allows immediate retry without reload while keeping the original token burned in the ledger.
  - Tampering guards are uniform: regex validation precedes disk access; mode/form_id mismatches or cross-mode payloads are hard failures.

<a id="sec-hidden-mode"></a>2. Hidden-mode contract
> **Contract — Security::mint_hidden_record**
>	- Inputs:
>	- `form_id` (slug). Callers MUST invoke `Config::get()` first; the helper also calls it defensively so the snapshot and `security.token_ttl_seconds` are available.
>	- Side-effects:
>	- Atomically write `tokens/{h2}/{sha256(token)}.json` with `{ mode:"hidden", form_id, instance_id, issued_at, expires }`, using shared lifecycle sharding (`{h2}`) and permissions from [Shared lifecycle and storage](#sec-shared-lifecycle).
>	- Persisted records never rewrite on rerender.
>	- Returns:
>	- `{ token: UUIDv4, instance_id: base64url(16–24 bytes), issued_at: unix, expires: issued_at + security.token_ttl_seconds }`.
>	- Failure modes:
>	- Propagate filesystem errors (create/write/fsync) to the caller as hard failures; helpers never swallow IO issues.

Hidden-mode GET rate limiting (normative):
- When `throttle.enable=true`, `Security::mint_hidden_record()` MUST enforce throttling before minting.
- On throttle hard-fail (rate limit exceeded):
	- Do not mint a token record.
	- `FormRenderer` MUST render an inline per-form error (not a page-level 429).
	- HTTP status remains 200 (a page may contain multiple forms).
	- Response headers MUST include `Cache-Control: private, no-store, max-age=0`.
	- The user-facing message MUST be generic (e.g., "Please wait a moment and try again.").
	- Log the throttle event including `request_id`.
- `FormRenderer` must embed the returned `token`, `instance_id`, and `issued_at` (as `timestamp`) in HTML and **must not** generate or alter them (see [Security invariants](#sec-security-invariants)).
		- Markup: GET renders emit a CSPRNG `instance_id` (16–24 bytes → base64url `^[A-Za-z0-9_-]{22,32}$`), the persisted `timestamp`, and `<input type="hidden" name="eforms_token" value="…">` whose UUID matches `/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i/`. Rerenders MUST reuse the exact `{token, instance_id, timestamp}` trio and send `Cache-Control: private, no-store`.
		- Persisted record (`tokens/{h2}/{sha256(token)}.json`):
			| Field		| Notes |
			|--------------|-------|
			| `mode`		 | Always `"hidden"`.
			| `form_id`	| Must match the rendered form; mismatch is tampering.
			| `issued_at`	| Timestamp mirrored to the rendered `timestamp`.
			| `expires`	| `issued_at + security.token_ttl_seconds`.
			| `instance_id`| Base64url CSPRNG, never rewritten until token rotation.
		- POST requirements:
- Success requires a matching record: it MUST say `mode:"hidden"` for the same `form_id`, and TTL MUST be valid.
			- Missing or expired record is a hard `EFORMS_ERR_TOKEN`.
			- Schema requirement (when a hidden-token record is present): the record MUST contain a conformant `instance_id` (base64url, 16–24 bytes). If present but non-conformant → hard failure.
			- Replay after ledger burn is a hard fail.
			- Dedup behavior:
				- `submission_id` equals the raw token when the hidden record validates.
				- Ledger handling follows [Security invariants](#sec-security-invariants) and [Security → Ledger reservation contract](#sec-ledger-contract).
				- Hard failures present `EFORMS_ERR_TOKEN` (“This form was already submitted or has expired - please reload the page.”).

		- <a id="sec-hidden-email-failure"></a>Email-failure recovery (normative): When `EFORMS_ERR_EMAIL_SEND` occurs after ledger reservation, the rerender MUST call `Security::mint_hidden_record()` to generate a fresh `{token, instance_id, timestamp}` trio. The original token remains burned in the ledger. This is the sole exception to the "reuse token on error rerender" rule.

<a id="sec-js-mint-mode"></a>3. JS-minted mode contract
> **Contract — `/eforms/mint`**
>\t- Inputs:
>\t- HTTP method MUST be POST; return 405 Method Not Allowed for other methods.
>\t- `f` (form_id slug).
>\t- Side-effects:
>\t- Enforce origin policy and throttling.
>\t- Atomically write `tokens/{h2}/{sha256(token)}.json` with `{ mode:"js", form_id, instance_id, issued_at, expires }`.
>\t- Returns:
>\t- JSON `{ token, instance_id, timestamp, expires }` where `timestamp == issued_at`.
>\t- Response headers:
>\t- MUST send `Cache-Control: no-store, max-age=0` and MUST NOT set cookies.
>\t- Failure modes:
>\t- Invalid/unknown `f` inputs are hard failures.
>\t- Filesystem errors propagate as hard failures.

`/eforms/mint` HTTP response codes (normative):
| Condition | Status | Response | Headers |
|-----------|--------|----------|---------|
| Success | 200 | `{ "token": "…", "instance_id": "…", "timestamp": …, "expires": … }` | `Cache-Control: no-store, max-age=0` |
| Invalid/unknown `f` | 400 | `{ "error": "EFORMS_ERR_INVALID_FORM_ID" }` | `Cache-Control: no-store, max-age=0` |
| Origin hard-fail | 403 | `{ "error": "EFORMS_ERR_ORIGIN_FORBIDDEN" }` | `Cache-Control: no-store, max-age=0` |
| Rate limited | 429 | `{ "error": "EFORMS_ERR_THROTTLED" }` | `Cache-Control: no-store, max-age=0`, `Retry-After: {seconds}` |
| Filesystem error | 500 | `{ "error": "EFORMS_ERR_MINT_FAILED" }` | `Cache-Control: no-store, max-age=0` |
| Wrong method | 405 | `{ "error": "EFORMS_ERR_METHOD_NOT_ALLOWED" }` | `Cache-Control: no-store, max-age=0`, `Allow: POST` |

Notes (normative):
- `/eforms/mint` MUST return `application/json` for both success and error responses.
- When rate limited, implementations MUST include `Retry-After` with a deterministic value computed from the active fixed window (a positive integer number of seconds).

<a id="sec-js-email-failure"></a>Email-failure recovery (JS-minted, normative):
- When `EFORMS_ERR_EMAIL_SEND` occurs after ledger reservation, the rerender MUST include `data-eforms-remint="1"` on the form element.
- On this rerender, Renderer MUST leave the token and instance hidden fields empty so forms.js can inject without overwriting.
- forms.js MUST detect this marker on DOMContentLoaded, clear the cached token for that `form_id` from sessionStorage, call `/eforms/mint` to obtain a fresh token, inject the new `{token, instance_id}` into the form, and re-enable submission.
- The original token remains burned in the ledger.

**Duplicate form IDs (normative):**
- A single page MAY render multiple different forms, but MUST NOT render the same `form_id` twice. Attempting to do so is a configuration error: `EFORMS_ERR_DUPLICATE_FORM_ID`.

<a id="sec-honeypot"></a>2. Honeypot
	- Field: eforms_hp (fixed POST name). Control IDs follow the current mode’s `id` scheme; the posted value MUST be empty. Submitted value is discarded and never logged.
- Config: `security.honeypot_response` ∈ {`hard_fail`,`stealth_success`}.
	- Common behavior:
		- UX: treat as spam-certain, short-circuit before validation/coercion/email, delete temp uploads.
		- Ledger: attempt reservation to burn the ledger entry for that `submission_id`.
	- "stealth_success": mimic success UX and log stealth:true.
		- Log events emitted for stealth success MUST still record that the honeypot fired (not just `stealth=true`) so ops can distinguish it from genuine success.
	- "hard_fail": rerender with generic global error (HTTP 200) and no field-level hints.

<a id="sec-timing-checks"></a>3. Timing Checks
- min_fill_time: add `"min_fill_time"` to `soft_reasons` when submitted faster than `security.min_fill_seconds`. Measure from the persisted record’s `issued_at` (hidden and JS-minted); rerenders reuse the original timestamp.
- Email-failure retry bypass (normative): on email-failure rerender, Renderer MUST include `<input type="hidden" name="eforms_email_retry" value="1">`. When this field is present and non-empty in the POST, Timing Checks MUST NOT add `"min_fill_time"` to `soft_reasons`. Note: the fresh token minted on this path has a new `issued_at`; this bypass prevents immediate retries from triggering min_fill_time. Other timing-derived signals (e.g., age_advisory) use the fresh token's timestamp. This marker is UX-driven and client-asserted; it MUST NOT be treated as a security boundary.
- Max form age: treat as a soft signal: when `now - issued_at` exceeds `security.max_form_age_seconds`, add `"age_advisory"` to `soft_reasons`; never hard-fail on age alone.
- js_ok flips to "1" on DOM Ready: if missing or != "1", add `"js_missing"` to `soft_reasons` unless `security.js_hard_mode=true` (then HARD FAIL).

<a id="sec-origin-policy"></a>4. Headers (Origin policy)
	- Normalize + truncate UA to printable chars; cap to a fixed internal maximum (implementation constant).
	- Origin check: normalize to scheme+host+effective port (80/443 normalized; non-default ports significant). origin_state = same | cross | unknown | missing.
	- Policy (security.origin_mode): off (no signal), soft (default), hard (hard fail on cross/unknown; when missing: hard-fail if `security.origin_missing_hard=true`, else add `"origin_soft"` to `soft_reasons`).
	- Log only origin_state (no Referrer). Referrer is not consulted.
	- Security::origin_evaluate() returns {state, hard_fail, soft_reasons?: string[]}.
	- When `security.origin_mode="soft"` and the evaluated request is cross-origin or unknown, add `"origin_soft"` to `soft_reasons`. Missing Origin adds `"origin_soft"` in soft mode regardless of `security.origin_missing_hard`.
	- Operational guidance: Only enable `security.origin_mode="hard"` with `security.origin_missing_hard=true` after validating your environment (some older agents omit Origin). Provide a tiny WP-CLI smoke test that POSTs without Origin to verify behavior.

<a id="sec-post-size-cap"></a>6. POST Size Cap (authoritative)
	- Applies after Type gate:
		- AppCap = security.max_post_bytes
		- IniPost = Helpers::bytes_from_ini(ini_get('post_max_size'))
		- IniUpload = Helpers::bytes_from_ini(ini_get('upload_max_filesize'))
	- RuntimeCap:
		- uploads.enable=false or urlencoded → min(AppCap, IniPost)
		- uploads.enable=true and multipart/form-data → min(AppCap, IniPost, IniUpload)
		- Enforce also uploads.total_request_bytes + per-file/field/max_files caps.
	- Guards:
		- If CONTENT_LENGTH present and > RuntimeCap → early abort with generic message (before reading body).
		- When CONTENT_LENGTH missing/inaccurate, rely on PHP INI limits and post-facto aggregate checks.
		- uploads.enable=false → never factor any uploads.* values into RuntimeCap.
	- Token checks:
		- Valid token + matching record → PASS; ledger reservation burns token on first success.
		- Wrong form_id in record or POST payload → HARD FAIL.
		- Missing/expired/invalid token record → HARD FAIL with `EFORMS_ERR_TOKEN`.
		- Reused token after ledger sentinel exists → HARD FAIL with `EFORMS_ERR_TOKEN`.

<a id="sec-test-qa"></a>7. Test/QA Checklist (mandatory)
	- Hidden-mode scenarios → follow [Security → Hidden-mode contract](#sec-hidden-mode).
	- JS-minted scenarios → follow [Security → JS-minted mode contract](#sec-js-mint-mode).
	- Ledger scenarios → follow [Security → Ledger reservation contract](#sec-ledger-contract).
	- Honeypot scenarios → follow [Security → Honeypot](#sec-honeypot).
	- Inline success scenarios → follow [Success behavior → Inline success flow](#sec-success-flow).

<a id="sec-spam-decision"></a>8. Spam Decision
	- Ordering (normative): the Security gate runs before Normalize/Validate/Coerce; on hard failure it MUST stop processing before side effects (uploads moves, email). Honeypot may still perform its ledger burn/cleanup as specified in [Security → Honeypot](#sec-honeypot).
	- Hard checks first: honeypot, token/origin hard failures, and hard throttle. Any hard fail stops processing.
	- Throttle integration (normative): `throttle_soft` is added to `soft_reasons` only by the throttle check itself; do not add it out of band.
	- `soft_reasons` (closed set, deduplicated): `min_fill_time` | `age_advisory` | `js_missing` | `origin_soft` | `throttle_soft`. Producers MUST use only these labels; unknown labels are an implementation bug.
	- Scoring (computed, not stored): let `soft_fail_count = |soft_reasons|`. Decision: if `soft_fail_count >= spam.soft_fail_threshold` → spam-fail; else if `soft_fail_count > 0` → deliver as suspect; else deliver normal.
	- Spam-fail behavior: short-circuit before validation/email; respond per `security.honeypot_response` (stealth_success or hard_fail); log with `spam_decision=fail` and the triggering `soft_reasons`.
	- `spam.soft_fail_threshold` is clamped at bootstrap to a minimum of 1.
	- Accessibility note: `js_hard_mode=true` blocks non-JS users; keep opt-in.

<a id="sec-redirect-safety"></a>9. Redirect Safety
	- wp_safe_redirect; same-origin only (scheme/host/port).

<a id="sec-suspect-handling"></a>10. Suspect Handling
	- add headers: X-EForms-Soft-Fails, X-EForms-Suspect; subject tag (configurable)
	- X-EForms-Soft-Fails value = `|soft_reasons|` (computed length of the deduplicated set)

<a id="sec-throttling"></a>11. Throttling (optional; file-based)
	- Throttle uses a fixed 60s window tracked via JSON files under `${uploads.dir}/throttle/` with flock() for concurrency. Throttle evaluation is part of the Security gate and runs before normalize/validate.
	- Hosting requirement (normative): file-based throttling requires a filesystem with reliable `flock()` and atomic exclusive-create semantics; if your hosting cannot guarantee these (verify with your provider), set `throttle.enable=false`.

	- Window semantics (normative):
		- Each fixed window is derived deterministically from current time so concurrent requests agree on the active window.
		- Counter increments on each request that reaches the throttle check.
		- On hard-fail, `Retry-After` equals the time until the active fixed window resets (a positive integer number of seconds); implementations MAY prune stale window files opportunistically.

	- Thresholds (normative):
		- Soft: when `count >= (throttle.per_ip.max_per_minute × [THROTTLE_SOFT_THRESHOLD])`, add `"throttle_soft"` to `soft_reasons`. This triggers challenge in `auto` mode without blocking.
		- Hard: when `count >= throttle.per_ip.max_per_minute`, hard-fail with HTTP 429 and `Retry-After`.

	- Cooldown (normative):
		- After a hard-fail, the IP enters cooldown for `throttle.per_ip.cooldown_seconds`.
		- During cooldown, all requests from that IP receive 429 immediately without incrementing the counter or evaluating other gates.
		- Cooldown is tracked via `${uploads.dir}/throttle/{h2}/{key}.cooldown.json` with `{ "expires": <unix_timestamp> }`. Cooldown files follow the same sharding and permissions as window files (dirs `0700`, files `0600`).

	- Response semantics by entrypoint (normative):
		| Entrypoint | Hard-fail response | Headers |
		|------------|-------------------|----------|
		| GET render (hidden-mode mint) | HTTP 200 + inline per-form error | `Cache-Control: private, no-store, max-age=0` |
		| POST submit | HTTP 429 | `Retry-After: {seconds}` |
		| `/eforms/mint` | HTTP 429 | `Retry-After: {seconds}` |

	- Form-ID fanout guard (normative):
		- When a validated `form_id` is available, include it in the throttle key material.
		- When `form_id` is unknown/invalid (pre-validation), include the literal `"invalid"` (do not incorporate user-supplied values).
		- Prevents disk-spam via arbitrary `form_id` values.
	- Key derivation uses the resolved client IP per [Privacy and IP Handling](#sec-privacy) regardless of `privacy.ip_mode` (do not persist the literal IP); storage path `${uploads.dir}/throttle/{h2}/{key}.json` with `{h2}` derived from the key per [Security → Shared Lifecycle and Storage Contract](#sec-shared-lifecycle)’s shared sharding and permission guidance; GC files >2 days old.
	- Hard failures MUST abort the pipeline before side effects (uploads, email, ledger).

<a id="sec-adaptive-challenge"></a>12. Adaptive challenge (optional; Turnstile preferred)

**Rationale:** The adaptive challenge system provides progressive spam defense without penalizing legitimate users. `auto` mode only triggers challenges when soft signals (timing anomalies, origin mismatches, throttle pressure) suggest potential abuse, balancing security with UX. `always_post` mode enforces challenges on every submission for high-risk forms. Challenges render only on POST rerenders (never on initial GET) to preserve edge cacheability and avoid unnecessary provider script loads. The token-reuse policy across rerenders prevents challenge exhaustion and maintains form state through the verification flow.

	- Challenge rotation follows [Security → Security invariants](#sec-security-invariants); tokens are reused across rerenders until success or expiry.
	- Modes: off | auto (require when `soft_reasons` is non-empty) | always_post.
	- Migration: legacy value `always` is accepted as an alias for `always_post`.
	- Provider (v1): Turnstile only (`cf-turnstile-response`).
	- If `challenge.mode` requires verification but Turnstile site_key/secret_key are not configured, treat it as a configuration error and fail with `EFORMS_CHALLENGE_UNCONFIGURED` until fixed.
	- Render only on POST rerender when required or during verification; never on the initial GET.
	- Extension point (future): The provider abstraction supports adding hCaptcha (`h-captcha-response`) and reCAPTCHA v2 (`g-recaptcha-response`). Adding a provider requires: (1) enum extension, (2) verification implementation following the provider's server-side API, (3) script URL in asset enqueue map. These are reserved for future versions based on operator demand.

<a id="sec-validation-pipeline"></a>
9. VALIDATION & SANITIZATION PIPELINE (DETERMINISTIC)
	0. Structural preflight (stop on error; no field processing)
	- Unknown keys rejected at every level (root/email/success/field/rule).
	- Unknown-key errors SHOULD include a best-effort "did you mean …?" suggestion when an unambiguous close match exists.
	- fields[].key must be unique; duplicates → EFORMS_ERR_SCHEMA_DUP_KEY.
	- Enum enforcement (field.type, rule.rule, row_group.mode, row_group.tag).
	- Conditional requirements (redirect mode requires redirect_url; files must have max_files>=1 if present; row_group must omit key).
	- accept[] ∩ global allow-list must be non-empty; else EFORMS_ERR_ACCEPT_EMPTY.
	- Row-group object shape must match spec; mis-shapes → EFORMS_ERR_SCHEMA_OBJECT.
	- Handler resolution: resolve all handler IDs to callables; unknown → deterministic RuntimeException (caught → config error).

	1. Security gate (hard failures + `soft_reasons`; stop on hard failure)

	2. Normalize (lossless)
	- Apply wp_unslash and trim; Helpers::nfc for Unicode NFC (no-op without intl).
	- Flatten $_FILES; shape items as { tmp_name, original_name, size, error, original_name_safe }.
	- Treat UPLOAD_ERR_NO_FILE or empty original_name as "no value".
	- Scalar vs array:
		- Do not reject here.
		- If a single-value field received an array, retain the array for Validate to reject deterministically.
		- If a multivalue field received a scalar, wrap it into a single-element array.
		- For multivalue arrays, discard empty-string and null entries; if the result is empty, treat as "no value".
	- No rejection allowed in Normalize.

	3. Validate (authoritative; may reject)
	- Check required, length/pattern/range, allow-lists, cross-field rules (see [Cross-Field Rules (BOUNDED SET)](#sec-cross-field-rules)).
	- Options: reject when a disabled option key is submitted.
	- Uploads:
		- Enforce per-file, per-field, per-request caps; count cap for files.
		- MIME/ext/finfo agreement required. finfo=false/unknown → reject EFORMS_ERR_UPLOAD_TYPE.
		- application/octet-stream allowed only when finfo and extension agree and accept-token permits.
		- Optional image sanity via getimagesize.
		- No SVG; no macro-enabled Office formats.
		- Reject arrays on single-file fields.
		- Only evaluate fields declared in template; ignore extraneous POST keys but still reject arrays where a scalar is expected.
	- Client validation (when enabled) is advisory; server runs always.

	4. Coerce (post-validate, canonicalization only)
	- Lowercase email domain; NANP canonicalization for tel_us; whitespace collapse when enabled.
	- Defer file moves until global success; move to private dir; perms 0600/0700; stored name derived from `submission_id` + upload position + sha16; compute sha256.

	5. Use canonical values only (email/logs)

	6. Escape at sinks only (per map in [Central Registries (Internal Only)](#sec-central-registries))

<a id="sec-html-fields"></a>
10. SPECIAL CASE: HTML-BEARING FIELDS
	- Template fragments (before_html / after_html)
	- Sanitize with wp_kses_post; sanitized result is canonical; escape per sink.

<a id="sec-cross-field-rules"></a>
11. CROSS-FIELD RULES (BOUNDED SET)
	- Supported:
		`target` identifies the field that will receive an error when the rule triggers. The `field` or `fields` entries list the field(s) inspected to determine whether the rule triggers.
		- required_if: { "rule":"required_if", "target":"state", "field":"country", "equals":"US" } (state required when country is US)
		- required_if_any: { "rule":"required_if_any", "target":"discount_code", "fields":["customer_type","membership"], "equals_any":["partner","gold"] } (discount_code required if any field matches)
		- required_unless: { "rule":"required_unless", "target":"email", "field":"phone", "equals":"provided" } (email required unless phone is provided)
		- matches: { "rule":"matches", "target":"confirm_password", "field":"password" } (confirm_password must match password)
		- one_of: { "rule":"one_of", "fields":["email","phone","fax"] } (at least one contact method is required)
		- mutually_exclusive: { "rule":"mutually_exclusive", "fields":["credit_card","paypal"] } (cannot provide both payment methods)
	- Deterministic evaluation order: top-to-bottom
	- additionalProperties:false per rule object
	- Multiple violations reported together

<a id="sec-field-types"></a>
12. BUILT-IN FIELD TYPES (DEFAULTS; US-FOCUSED)
	- Spec::descriptorFor($type) exposes a descriptor for each field type:
	- is_multivalue: bool
	- html { tag:"input|textarea|select", type?, multiple?, inputmode?, pattern?, attrs_mirror:[ maxlength?, minlength?, min?, max?, step? ] }
	- validate { required?, pattern?, range?, canonicalize? }
	- handlers { validator_id, normalizer_id, renderer_id }	 // short tokens, e.g., "email"
	- constants { ... }	// e.g., email: spellcheck=false, autocapitalize=off
	- alias_of?	// explicit alias target if applicable
	- name / first_name / last_name: aliases of text; trim internal multiples; default autocomplete accordingly.
	- text: length/charset/regex
	- textarea: length/charset/regex
	- email: type="email", inputmode="email", spellcheck="false", autocapitalize="off"; mirror maxlength/minlength.
	- url: wp_http_validate_url + allowed schemes (http, https). type="url", spellcheck="false", autocapitalize="off".
	- tel_us: NANP; digits-only canonical 10 digits; optional +1 stripped; no extensions. type="tel", inputmode="tel"; mirror maxlength.
	- tel (generic): freeform; trimmed.
	- number / range: native input types; inputmode="decimal"; mirror min/max/step exactly as validated server-side.
	- select / radio: store option key
	- checkbox: single -> bool; group -> array of keys
	- zip_us: type="text", inputmode="numeric", pattern="\\d{5}" (hint only); always set maxlength=5; server enforces ^\d{5}$.
	- zip (generic): freeform
	- file: single upload. See [Uploads → Accept-token policy](#sec-uploads-accept-tokens) for the canonical MIME/extension mapping and default token policy.
	- files: multiple upload with max_files; reuse the same token definitions from [Uploads → Accept-token policy](#sec-uploads-accept-tokens); email attachment policy follows [Email Delivery](#sec-email).
	- date: mirror min/max and step when provided.
	- For each field, the HTML attributes emitted (inputmode, pattern, multiple, accept, etc.) must match attr_mirror derived from the resolved descriptor.
	- Resolved descriptor cache per request:
	- Include name_tpl and id_prefix to avoid recomputing; reuse in Renderer + Validator.

<a id="sec-accessibility"></a>
13. ACCESSIBILITY (A11Y)
	1. Labels
	- Always render a <label> for each control; if missing, derive Title Case label and mark visually hidden
	- label@for matches control id; control id unique
	2. Required Fields
	- Native controls: use native required only (no aria-required)
	- Custom widgets: aria-required="true"
	- Show a visual indicator (e.g., "*")
	3. Grouped Controls
	- radio/checkbox groups wrapped in <fieldset> with <legend>
	- Error summary links target the fieldset/legend (or first control); use aria-describedby to include error id
	4. Error Summary (top)
	- role="alert" container appears after submit when errors exist; list links to invalid controls; forms.js focuses summary (tabindex="-1") once, then first invalid control
	- Do not use role="alert" on each field; if live updates are needed, use aria-live="polite" or role="status"
	5. Per-field Errors
	- <span id="error-{field_id}" class="eforms-error">...</span>
	- when invalid: aria-invalid="true"; aria-describedby includes error id
	6. Focus Behavior
	- forms.js focuses first invalid after submission
	- Do not set multiple autofocus attributes.
	7. File Inputs
	- follow same patterns as native inputs

<a id="sec-success"></a>
14. SUCCESS BEHAVIOR (PRG)
			- PRG status is fixed at 303. Success responses MUST send `Cache-Control: private, no-store, max-age=0`. Any request containing `eforms_*` query args MUST also send `Cache-Control: private, no-store, max-age=0`.
			- Namespace internal query args with `eforms_*`. `success.message` is plain text and escaped.
			- Caching: do not disable page caching globally. Only bypass caching for requests containing `eforms_*` query args.
			- <a id="sec-success-modes"></a>Modes (normative summary):
				| Mode | PRG target | Display rule | Cache guidance |
				|------|------------|--------------|----------------|
				| Inline | `303` back to the same URL with `?eforms_success={form_id}`. | Renderer shows the banner when `?eforms_success={form_id}` is present. Show banner only in the first instance in source order; suppress subsequent duplicates. | The success message is idempotent and can be displayed on multiple visits to the URL. |
				| Redirect | `wp_safe_redirect(redirect_url, 303)`. | Destination renders its own success UX. | No special cache requirements. |
			- <a id="sec-success-flow"></a>Inline success flow (normative):
				1. On successful POST, redirect with `?eforms_success={form_id}` (303).
				2. On the follow-up GET, renderer checks for the `?eforms_success={form_id}` query parameter and displays the success banner (using `success.message` from template) when present.
				3. The success banner is displayed based solely on the query parameter; no verification is required. Users can revisit or bookmark the success URL.

<a id="sec-email"></a>
15. EMAIL DELIVERY
	- Primary transport (normative): use WordPress `wp_mail()`. The plugin MUST NOT implement SMTP, DKIM signing, retry/backoff, or provider debug transcript capture. Transport-level behavior is delegated to the site's mail plugin/MTA.
	- DMARC alignment: From: no-reply@{site_domain}
	- From precedence: if `email.from_address` is a valid same-domain address, use it; if non-empty but invalid, treat as empty (fall back to no-reply@{site_domain}) and emit a warning when logging is enabled. Always keep From: on site domain.
	- From domain: parse_url(home_url()).host (lowercase; strip www)
	- default content type: text/plain; HTML emails only if `email.html=true`
	- subjects/headers: sanitize CR/LF; collapse control chars; truncate Subject/From Name to ≤255 bytes (UTF-8 safe) before assembly. Never accept raw user header input.
	- Reject arrays where a scalar is expected in headers/subject fields.
	- Email addresses are validated via `is_email()` (WordPress core) and MUST be a single address (no display-names, groups, or comma-separated lists); malformed values are treated as empty.
	- Reply-To: if the template declares exactly one field with `type="email"` and that field validates as a single email address, set Reply-To to it; otherwise omit Reply-To.
	- deliverability: recommend SMTP with SPF/DKIM/DMARC
	- template tokens: {{field.key}}, {{submitted_at}}, {{ip}}, {{form_id}}, {{submission_id}}
	- If an upload field key appears in include_fields, render value as comma-separated list of original_name_safe in the email body (attachments separate).
	- attachments: only for fields with email_attach=true; enforce uploads.max_email_bytes and email.upload_max_attachments; summarize overflow in body before send.
	- Enforce size/count before calling `wp_mail()` to avoid SMTP 552.
	- Hooks: register `wp_mail_failed` (log reason).
	- display_format_tel follows [Template Model → display_format_tel tokens](#sec-display-format-tel); formatting affects email presentation only.

<a id="sec-logging"></a>
16. LOGGING

**Rationale:** The three-tier logging strategy balances operational visibility with privacy compliance and ops burden. JSONL mode provides structured, machine-readable logs for forensics and analytics while respecting privacy flags (`pii`, `headers`). Minimal mode offers lightweight error tracking via `error_log()` for sites that don't need full audit trails. The independent Fail2ban emission channel uses raw IP addresses (ignoring `privacy.ip_mode`) to enable rate-limiting enforcement, while all other logging respects privacy configuration. Severity-based filtering (`logging.level`) lets operators tune verbosity without code changes.

	- Mode selects destination; level selects severities; pii/headers select detail; rotation keeps files sane.
	- logging.mode: "jsonl" | "minimal" | "off" (authoritative)
	- jsonl — structured files in ${uploads.dir} with rotation/retention.
	- minimal — compact line per event via error_log(); rotation governed by server.
	- off — no logging (except optional Fail2ban emission).
	- Severity mapping: error (fatal pipeline failures), warning (rejections, validation, challenge timeouts), info (successful sends, token rotations, throttling state changes).
- `logging.level`: 0 errors; 1 +warnings; 2 +info. Default: see the Defaults note in [Configuration: Domains, Constraints, and Defaults](#sec-configuration).
- `logging.headers` (bool) — if true, log normalized UA/Origin (scheme+host only). Default: see the Defaults note in [Configuration: Domains, Constraints, and Defaults](#sec-configuration).
- `logging.pii` (bool) — allows full emails/IPs in JSONL only; minimal mode always masks. Default: see the Defaults note in [Configuration: Domains, Constraints, and Defaults](#sec-configuration).
	- Rotation/retention for JSONL: dirs 0700, files 0600, rotate when file exceeds an internal size cap (implementation constant, not user-configurable), prune > retention_days. flock() used; note NFS caveats.
	- What to log (all modes, subject to pii/headers):
		- Timestamp (UTC ISO-8601), severity, code, form_id, submission_id, request URI (path + only `eforms_*` query), privacy-processed IP, spam signals summary (honeypot, origin_state, soft_reasons, throttle_state), mail send failure reason when applicable (from `wp_mail_failed`).
		- Token evaluation mode (meta.mode) when the submission gate runs: "hidden" or "js".

		- Request correlation identifier (normative):
			- Canonical log key: `request_id`. Every log event emitted while handling a request MUST include this key; JSONL encodes it as a top-level property and minimal mode injects `req=<request_id>` into the `meta=` blob.
			- Resolution order:
				- Apply `apply_filters('eforms_request_id', $candidate, $request)` first. Any non-empty ASCII string returned by the filter wins.
				- If the filter yields an empty value, look for HTTP headers (case-insensitive) in this order: `X-Eforms-Request-Id`, `X-Request-Id`, `X-Correlation-Id`. The first header containing a printable token ≤128 bytes wins after trimming surrounding whitespace.
				- When no upstream identifier is available, generate a UUIDv4 at bootstrap and reuse it for the lifetime of the request.
			- Sanitization: collapse internal whitespace to single spaces, strip control characters, and cap the final token at 128 bytes. If sanitization empties the value, advance to the next source in the resolution order.
			- Entry points (SubmitHandler, Renderer AJAX endpoints, mint/success controllers) MUST pass the resolved identifier to `Logging::event()` so both JSONL and minimal sinks expose it. Email send failure logs required by [Error Handling](#sec-error-handling) MUST include this `request_id`.

	- Throttle & challenge outcomes at level >=1 (redact provider tokens).
	- At level=2, include a compact descriptor fingerprint for this request: desc_sha1 = sha1(json_encode(resolved descriptors)). Optionally include a compact spam bitset alongside the human list.
	- Minimal mode line format
	- eforms severity=<error|warning|info> code=<EFORMS_*|PHPMailer> form=<form_id> subm=<submission_id> ip=<masked|hash|full|none> uri="<path?eforms_*...>" msg="<short>" meta=<compact JSON>
	- Fail2ban (optional; independent of logging.mode; enabled only when `logging.fail2ban.*` is configured)

	**Privacy notice:** Fail2ban emission uses the resolved client IP in plaintext regardless of `privacy.ip_mode`. This is intentional—external rate-limiting tools require real IPs to function. Operators who enable Fail2ban should be aware that raw IPs will appear in the Fail2ban log even when `privacy.ip_mode` is set to `masked`, `hash`, or `none`.

	- Emit single-line: eforms[f2b] ts=<unix> code=<EFORMS_ERR_*> ip=<resolved_client_ip> form=<form_id>
	- Rotation/retention similar to JSONL.
	- Implementation notes:
	- Initialize JSONL/minimal logger only when logging.mode!='off'. Fail2ban emission is independent.

<a id="sec-privacy"></a>
17. PRIVACY AND IP HANDLING
- `privacy.ip_mode` ∈ {`none`,`masked`,`hash`,`full`}. Default: see the Defaults note in [Configuration: Domains, Constraints, and Defaults](#sec-configuration).
	- masked: IPv4 last octet(s) redacted; IPv6 last 80 bits zeroed (compressed)
	- hash: sha256(ip + optional salt); store hash only
	- full: store/display IP as-is
	- logs and emails honor this setting for IP presentation
	- Rate-limiting enforcement (throttle and Fail2ban) uses the resolved client IP regardless of `privacy.ip_mode`.
	- include ip in email.include_fields only when mode != none
	- UA and Origin never included in emails; logging only
	- submitted_at set server-side (UTC ISO-8601) for logs/emails
	- Trusted proxies:
	- privacy.client_ip_header (e.g., X-Forwarded-For or CF-Connecting-IP), privacy.trusted_proxies (CIDR[])
	- If REMOTE_ADDR is in trusted_proxies and a valid public IP exists in header list, use left-most public IP; else REMOTE_ADDR.
	- Public IP excludes private/reserved ranges.
	- Header parsed case-insensitively; comma-separated list; strip brackets/ports; accept only valid literals.
	- CI tests: forged XFF from untrusted source → use REMOTE_ADDR; trusted proxy + XFF(client,proxy) → pick client; header with only private IPs → fall back to REMOTE_ADDR.

<a id="sec-configuration"></a>
18. CONFIGURATION: DOMAINS, CONSTRAINTS, AND DEFAULTS
- Authority: Default *values* live in code as `Config::DEFAULTS` (see `src/Config.php`). This spec no longer duplicates every literal; the code array is the single source of truth for defaults.

Defaults note: When this spec refers to a ‘Default’, the authoritative literal is `Config::DEFAULTS` in code; the spec does not restate those literals.
	- Normative constraints (this spec): types, enums, required/forbidden combinations, range clamps, migration/fallback behavior, and precedence rules remain authoritative here. Implementations MUST enforce these even when defaults evolve.
	- Lazy bootstrap: The first call to `Config::get()` (including the invocations performed by `FormRenderer::render()`, `SubmitHandler::handle()`, `Security::token_validate()`, `Emailer::send()`, or the prime/success endpoints) invokes `Config::bootstrap()`; within a request it runs at most once, applies the `eforms_config` filter, clamps values, then freezes the snapshot. `uninstall.php` calls it eagerly to honor purge flags; standalone tooling MAY force bootstrap.
	- Bootstrap ownership (normative):
		- Entry points SHOULD call `Config::get()` before invoking helpers (see [Lazy-load Matrix](#sec-lazy-load-matrix) for trigger ownership).
- Helpers MUST ALSO call `Config::get()` on first use as a safety net; the call is idempotent so callers that forget still behave correctly.
		- When adding a new public endpoint, that endpoint owns calling `Config::get()` up front; do not call `Config::bootstrap()` directly (the uninstall carve-out described in [Architecture and file layout](#sec-architecture) is the lone exception).
		- Call order (illustrative): Endpoint → `Config::get()` → Helper (which internally no-ops `Config::get()` again) → …
	- Migration behavior: unknown keys MUST be rejected; missing keys fall back to defaults before clamping; invalid enums/ranges/booleans MUST trigger validation errors rather than coercion; POST handlers MUST continue to enforce constraints after bootstrap.

	`Config::DEFAULTS` also powers uninstall/CLI flows; it exposes a stable public symbol for ops tooling.

	1. Domains (key groups)
	| Domain	| Key prefix			 | Purpose (summary)												|
	|-----------|----------------------|------------------------------------------------------------------|
	| Security	| `security.*`		 | Token modes, TTLs, origin challenge policy, POST limits	 |
	| Spam		| `spam.*`			 | Soft-fail thresholds and spam heuristics						 |
	| Challenge | `challenge.*`		| CAPTCHA/Turnstile providers and HTTP timeouts					|
	| Email	 | `email.*`			| Email delivery policy (delegated transport via `wp_mail()`)	 |
	| HTML5	| `html5.*`			| Browser-native validation controls							 |
	| Logging	 | `logging.*`			| Mode/level/PII policy, retention, Fail2ban emission				|
	| Privacy	 | `privacy.*`			| IP handling, salts, proxy trust									|
		| Throttle	| `throttle.*`		 | Per-IP rate limits, cooldowns								 |
	| Validation| `validation.*`		 | Form shape guardrails (field/option caps, HTML size)			 |
	| Uploads	 | `uploads.*`			| Allow-lists, per-file/per-request caps, retention policy		 |
	| Assets	| `assets.*`			 | CSS enqueue controls											 |
	| Install	 | `install.*`			| Minimum platform versions, uninstall purge flags				 |

	2. Normative constraints (summary)
	| Domain	| Key									 | Type	| Constraints (normative)																						|
	|-----------|---------------------------------------|-------|----------------------------------------------------------------------------------------------------------------|
	| Security	| `security.origin_mode`				| enum	| {`off`,`soft`,`hard`} — governs whether missing Origin headers are tolerated.									|
	| Security	| `security.honeypot_response`			| enum	| {`stealth_success`,`hard_fail`} — determines the observable response when the honeypot triggers.				 |
	| Security	| `security.min_fill_seconds`			 | int	 | clamp `[MIN_FILL_SECONDS_MIN]`–`[MIN_FILL_SECONDS_MAX]`; values below min become min; above max become max (see [Anchors](#sec-anchors)).																|
	| Security	| `security.token_ttl_seconds`			| int	 | clamp `[TOKEN_TTL_MIN]`–`[TOKEN_TTL_MAX]`; minted tokens MUST set `expires - issued_at` equal to this value (see [Anchors](#sec-anchors)).								 |
	| Security	| `security.max_form_age_seconds`		 | int	 | clamp `[MAX_FORM_AGE_MIN]`–`[MAX_FORM_AGE_MAX]`; defaults to `security.token_ttl_seconds` when omitted (see [Anchors](#sec-anchors)).											|
	| Security	| `security.origin_missing_hard`		 | bool	| When `security.origin_mode="hard"`, treat missing Origin header as hard-fail.														|
	| Challenge | `challenge.mode`						| enum	| {`off`,`auto`,`always_post`} — controls when human challenges execute; invalid values MUST be rejected; legacy `always` is accepted as an alias.			|
	| Challenge | `challenge.provider`					| enum	| {`turnstile`} (v1). `hcaptcha` and `recaptcha` reserved for future extension.			 |
	| Challenge | `challenge.http_timeout_seconds`		| int	 | clamp `[CHALLENGE_TIMEOUT_MIN]`–`[CHALLENGE_TIMEOUT_MAX]` seconds (see [Anchors](#sec-anchors)).																							|
	| Email	 | `email.from_address`				| string | Optional; when non-empty but invalid, treat as empty and emit a warning when logging is enabled; fall back to no-reply@{site_domain}. |
	| Email	 | `email.html`						| bool	| When `true`, prefer HTML email templates; when `false`, send text/plain only. |
		| HTML5	| `html5.client_validation`			| bool	| When `true`, omit `novalidate` so browsers present native validation UI; when `false`, include `novalidate` to suppress native UI in favor of server-rendered errors. |
		| Throttle	| `throttle.per_ip.max_per_minute`		| int	 | clamp `[THROTTLE_MAX_PER_MIN_MIN]`–`[THROTTLE_MAX_PER_MIN_MAX]`; values beyond clamp saturate; 0 disables throttle only via `throttle.enable = false` (see [Anchors](#sec-anchors)).			|
		| Throttle	| `throttle.per_ip.cooldown_seconds`	| int	 | clamp `[THROTTLE_COOLDOWN_MIN]`–`[THROTTLE_COOLDOWN_MAX]` seconds (see [Anchors](#sec-anchors)).																							|
	| Logging	 | `logging.mode`						| enum	| {`off`,`minimal`,`jsonl`} — determines logging sink ([Logging](#sec-logging)).													 |
	| Logging	 | `logging.level`						 | int	 | clamp `[LOGGING_LEVEL_MIN]`–`[LOGGING_LEVEL_MAX]`; level ≥1 unlocks verbose submission diagnostics (see [Anchors](#sec-anchors)).													|
	| Logging	 | `logging.headers`					| bool	| When `true`, log normalized UA/Origin (scheme+host only). |
	| Logging	 | `logging.pii`						| bool	| When `true` and logging.mode=jsonl, allow full emails/IPs; otherwise redact per privacy policy. |
	| Logging	 | `logging.retention_days`				| int	 | clamp `[RETENTION_DAYS_MIN]`–`[RETENTION_DAYS_MAX]` days (see [Anchors](#sec-anchors)).																								 |
	| Logging	 | `logging.fail2ban.target`			 | enum	| {`file`} — fail2ban emission is file-only in v1; all other values MUST be rejected.		 |
	| Logging	 | `logging.fail2ban.retention_days`	 | int	 | clamp `[RETENTION_DAYS_MIN]`–`[RETENTION_DAYS_MAX]`; defaults to `logging.retention_days` when unspecified (see [Anchors](#sec-anchors)).											|
	| Privacy	 | `privacy.ip_mode`					 | enum	| {`none`,`masked`,`hash`,`full`} — see [Logging](#sec-logging) for hashing/masking details.										 |
	| Validation| `validation.max_fields_per_form`		| int	 | clamp `[MAX_FIELDS_MIN]`–`[MAX_FIELDS_MAX]`; protects renderer/validator recursion (see [Anchors](#sec-anchors)).															|
	| Validation| `validation.max_options_per_group`	| int	 | clamp `[MAX_OPTIONS_MIN]`–`[MAX_OPTIONS_MAX]`; denies pathological option fan-out (see [Anchors](#sec-anchors)).																|
	| Validation| `validation.max_items_per_multivalue` | int	 | clamp `[MAX_MULTIVALUE_MIN]`–`[MAX_MULTIVALUE_MAX]`; governs checkbox/select count (see [Anchors](#sec-anchors)).																	 |
	| Assets	| `assets.css_disable`				| bool	| When `true`, do not enqueue plugin CSS. |

	Additional notes:
		- `security.js_hard_mode = true` enforces a hard failure for non-JS submissions ([Security → Submission Protection for Public Forms](#sec-submission-protection)).
		- `security.max_post_bytes` MUST honor PHP INI limits (post_max_size, upload_max_filesize) and never exceed server caps.
		- Range/enumeration clamps are mirrored to HTML attributes for UX hints only; server enforcement is authoritative.
		- Spam heuristics (`spam.*`) and upload caps (`uploads.*`) are documented in [Validation & Sanitization Pipeline (Deterministic)](#sec-validation-pipeline) and [Uploads (Implementation Details)](#sec-uploads); they inherit defaults from runtime defaults (see Defaults below) but keep their behavioral rules in those sections.

	3. Defaults
		- Runtime defaults contract (normative):
			- Implementation MUST define the canonical defaults array at `src/Config.php` as `Config::DEFAULTS`. `Config::DEFAULTS` is the single source of truth for default *values* at runtime.
			- `Config::defaults()` MUST inject runtime-derived values such as `uploads.dir` (resolved from `wp_upload_dir()`); these dynamic entries remain code-driven.
		- Bootstrap note (informative): Until `Config::DEFAULTS` exists in code, any "default" values mentioned elsewhere in this spec are intended targets; constraints (clamps, enums, required combinations) remain authoritative in this spec.
		- Changing a default in code changes runtime behavior but MUST NOT weaken any constraint defined in this spec (constraints live here; defaults live in `Config::DEFAULTS`).

	4. CI guardrails
		- Repository CI MUST assert that every key documented above exists in `Config::DEFAULTS` and that the clamp/enum metadata in code matches the normative ranges listed here. This keeps the spec and implementation from drifting.
		- If CI is not yet present, maintainers MUST add an equivalent automated parity check before claiming conformance with this section.

	5. Contract stability (normative)
		- Configuration keys and meanings are treated as stable; evolve append-only.
		- Error codes are treated as stable; evolve append-only.
		- Machine-readable surfaces (e.g., `/eforms/mint` responses, log schemas) evolve append-only.

<a id="sec-uploads"></a>
19. UPLOADS (IMPLEMENTATION DETAILS)
	- <a id="sec-uploads-accept-tokens"></a>Accept-token policy (normative):
		- image → `image/jpeg`, `image/png`, `image/gif`, `image/webp` (SVG excluded).
		- pdf → `application/pdf`.
		- Explicit exclusions by default: `image/svg+xml`, `image/heic`, `image/heif`, `image/tiff`.
	- <a id="sec-uploads-accept-defaults"></a>
		Callout — Default accept tokens (informative): The default accept tokens remain `{image, pdf}`. Adding tokens requires explicit review and MUST update this policy. The MIME and extension mapping stays authoritative in [Uploads → Accept-token policy](#sec-uploads-accept-tokens).
	- Applies to both `file` and `files` field types. Email attachment policy inherits the same mappings and is further constrained by [Email Delivery](#sec-email).
				- <a id="sec-uploads-filenames"></a>Filename policy (display vs storage, normative):

**Rationale:** The dual-name approach separates user-facing display names from storage keys. Display names (`original_name_safe`) preserve the user's original filename (sanitized for safety) for email/logging readability. Stored filenames use a deterministic, collision-resistant scheme (`{Ymd}/{submission_id}-{file_index}-{sha16}.{ext}`) that prevents path traversal attacks, ensures uniqueness, and enables content-addressable verification via SHA-256. Private permissions (`0600` files, `0700` dirs) prevent direct web access, forcing controlled retrieval through application logic.

								- Display filename (`original_name_safe`): start from the client-supplied name; strip paths; NFC normalize; strip control characters; collapse redundant whitespace or dots; strip CR/LF; truncate to `uploads.original_maxlen`; fallback to `file.{ext}` if the result is empty.
								- Stored filename: `{Ymd}/{submission_id}-{file_index}-{sha16}.{ext}` with files `0600`, dirs `0700`; record full SHA-256 in logs.
								- `file_index` is the deterministic per-submission upload position (computed from template field order and per-field file order).
								- Always keep UTF-8 for display; when emitting to email headers, use RFC 5987 `filename*` when needed.
				- Path collisions are treated as internal errors; implementations MUST NOT overwrite existing files.
				- Intersection: field `accept[]` ∩ global allow-list must be non-empty → else `EFORMS_ERR_ACCEPT_EMPTY`.
				- Delete uploads after successful send unless retention applies; if email send fails after files were stored, cleanup per retention policy. On final send failure, delete unless `uploads.retention_seconds>0` (then GC per retention).
				- GC (normative): the plugin MUST NOT schedule WP-Cron. Operators SHOULD run `wp eforms gc` via system cron for predictable pruning. The plugin also runs best-effort GC on request shutdown when the lock is available; this baseline prevents unbounded growth but may lag under low traffic.
				- GC targets: expired token records (past TTL), uploads (past retention), stale throttle window files, and expired ledger `.used` markers.
				- Ledger GC eligibility (normative): a `${submission_id}.used` marker MAY be deleted when `now >= file_mtime + [TOKEN_TTL_MAX] + [LEDGER_GC_GRACE_SECONDS]`. This conservative rule covers the worst-case token validity window plus a grace period, without requiring a token record lookup.
				- Provide an idempotent `wp eforms gc` WP-CLI command.
				- GC runs under a single-run lock (e.g., `${uploads.dir}/eforms-private/gc.lock`) to prevent overlapping work.
				- Liveness checks MUST skip unexpired token records and ledger `.used` markers within the eligibility window.
				- GC MUST delete expired token records (hidden and JS-minted) whose `expires` timestamp has passed.
				- Dry-run mode lists candidate counts and total bytes without deleting.
				- Emit GC summaries (scanned/deleted/bytes) at `info`.
				- `has_uploads` flag computed during preflight; guard Uploads init on that.
				- Fileinfo hard requirement: if ext/fileinfo unavailable, define `EFORMS_FINFO_UNAVAILABLE` at bootstrap and deterministically fail any upload attempt.
				- MIME validation requires agreement of finfo + extension + accept-token; finfo=false/unknown ⇒ reject with `EFORMS_ERR_UPLOAD_TYPE`.
20. REQUEST LIFECYCLE
	<a id="sec-request-lifecycle-get"></a>1. GET
	- Shortcode `[eform id="slug" cacheable="true|false"]` (`cacheable` defaults to `false`).
	- Template tag `eform_render('slug', ['cacheable' => true|false])` (`cacheable` defaults to `false`).
	- `cacheable=false` forces hidden-mode; `cacheable=true` uses JS-minted mode.
	- Duplicate form IDs are unsupported: Renderer MUST NOT render the same `form_id` twice on one page; doing so is a configuration error `EFORMS_ERR_DUPLICATE_FORM_ID`.
	- FormRenderer loads the template and injects the appropriate hidden-token or JS-minted metadata per [Security → Submission Protection for Public Forms](#sec-submission-protection).
	- Registers/enqueues CSS/JS only when rendering
	- Always set method="post". If any upload field present, add enctype="multipart/form-data".
	- Max-input-vars heuristic: log advisory.
	- CDN/cache notes: bypass caching on non-cacheable token pages; `/eforms/mint` is no-store.
	- Initialize Logging only when logging.mode != "off".
	- Initialize Uploads only when uploads.enable=true and template declares file/files (detected at preflight).
- Renderer toggles the `novalidate` attribute based on `html5.client_validation`: omit it when the flag is `true` so browsers present native validation UI, and include it when the flag is `false` to suppress native validation in favor of server feedback. The server validator always runs on POST.
	- Preflight resolves and freezes per-request resolved descriptors; reuse across Renderer and Validator (no re-merge on POST).

	<a id="sec-request-lifecycle-post"></a>2. POST
	- SubmitHandler orchestrates Security gate -> Normalize -> Validate -> Coerce
	- Mode, hidden-field reuse, and rerender behavior follow the canonical contract in [Security → Submission Protection for Public Forms](#sec-submission-protection); lifecycle logic never swaps modes mid-flow.
	- Early enforce RuntimeCap using CONTENT_LENGTH when present; else rely on PHP INI limits and post-facto caps.
	- Error rerenders and duplicate handling follow [Security → Ledger reservation contract](#sec-ledger-contract). SubmitHandler performs the exclusive-create reservation immediately before side effects, treats `EEXIST` as a duplicate submission, and treats other IO failures as hard failures (`EFORMS_ERR_LEDGER_IO`) while logging `EFORMS_LEDGER_IO` for diagnosis.
	- On success: after reserving the ledger entry (the `.used` marker stays committed), move stored uploads; send email; log; PRG/redirect; cleanup per retention.
	- <a id="sec-email-failure-recovery"></a>Email send failure (Emailer::send() returns false or throws) is fatal: abort the success PRG, rerender with a fresh token (see [Hidden-mode email-failure recovery](#sec-hidden-email-failure) and [JS-minted email-failure recovery](#sec-js-email-failure) for mode-specific behavior), surface a `_global` error, log the event at `error` severity, and return HTTP 500. The ledger reservation remains committed; the fresh token enables immediate retry.
	- Best-effort GC on shutdown (opportunistically if lock is available; complements cron); no persistence of validation errors/canonical values beyond request.
	- throttle.enable=true and key available → run throttle; over → +1 soft and add Retry-After; hard → HARD FAIL (skip side effects).
	- Challenge hook: if required (always/auto or the Security gate), verify; success removes the relevant labels from `soft_reasons` (hard failures are unaffected).

<a id="sec-error-handling"></a>
21. ERROR HANDLING
	- Errors stored by field_key; global errors under _global
	- Renderer prints global summary + per-field messages
- Email send failures MUST surface `_global` ⇒ "We couldn't send your message. Please try again.", respond with HTTP 500, and tag the error as `EFORMS_ERR_EMAIL_SEND`. The log entry for this failure MUST include `form_id`, the transport/provider identifier, any exception class/message, and the correlation/request identifier.
- On `EFORMS_ERR_EMAIL_SEND` rerender, Renderer MUST:
	- Mint a fresh token (hidden-mode) or emit `data-eforms-remint="1"` (JS-minted mode).
	- Preserve submitted non-file field values (pre-filled).
	- Render a read-only `<textarea>` containing the submission content for manual copy as a fallback safety net (exclude file contents; include `original_name_safe` filenames only; honor `privacy.ip_mode` for any IP shown).
	- Upload user-facing messages:
	- "This file exceeds the size limit."
	- "Too many files."
	- "This file type isn't allowed."
	- "File upload failed. Please try again."
	- Re-render after errors passes the mode-specific security metadata defined in [Security → Submission Protection for Public Forms](#sec-submission-protection) back to Renderer (hidden: `{eforms_token, instance_id, timestamp}`; JS-minted: `{eforms_token, instance_id, timestamp}`).
	- Emit stable error codes (e.g., EFORMS_ERR_TOKEN, EFORMS_ERR_HONEYPOT, EFORMS_ERR_TYPE, EFORMS_ERR_ACCEPT_EMPTY, EFORMS_ERR_THROTTLED, EFORMS_ERR_DUPLICATE_FORM_ID, EFORMS_ERR_ROW_GROUP_UNBALANCED, EFORMS_ERR_SCHEMA_UNKNOWN_KEY, EFORMS_ERR_SCHEMA_ENUM, EFORMS_ERR_SCHEMA_REQUIRED, EFORMS_ERR_SCHEMA_TYPE, EFORMS_ERR_SCHEMA_OBJECT, EFORMS_ERR_UPLOAD_TYPE, EFORMS_ERR_LEDGER_IO, EFORMS_ERR_INVALID_FORM_ID, EFORMS_ERR_ORIGIN_FORBIDDEN, EFORMS_ERR_MINT_FAILED, EFORMS_ERR_INLINE_SUCCESS_REQUIRES_NONCACHEABLE).
	- Large form advisory via logs.
	- "This form was already submitted or has expired - please reload the page." maps to EFORMS_ERR_TOKEN.

<a id="sec-compatibility"></a>
22. COMPATIBILITY AND UPDATES
	- Deployment constraint (normative): token records, ledger markers, throttle state, and uploads live under `wp_upload_dir()` and MUST be readable/writable across the render→POST lifecycle. Single-webhead deployments work out of the box. Multi-webhead or containerized deployments MUST mount `${uploads.dir}` as a shared persistent volume; ephemeral container storage is unsupported.
	- Changing type defaults or rules updates behavior globally via registry
	- Templates remain portable (no callbacks)
	- Minimum versions: PHP >= 8.0; WordPress >= 5.8 (admin notice + deactivate if unmet)
	- Terminology: use allow-list/deny-list consistently.

<a id="sec-assets"></a>
23. ASSETS (CSS & JS)
	- Enqueued only when a form is rendered; version strings via filemtime().
	- forms.js provides js_ok="1" on DOM Ready, submit-lock/disabled state, error-summary focus, and first-invalid focus. Required for cacheable pages (JS-minted mode); otherwise optional unless `security.js_hard_mode=true`.
	- JS-minted token injection (normative): forms.js MUST, on DOMContentLoaded, ensure each JS-minted form has a token by issuing a POST to `/eforms/mint` with form-encoded body `f={form_id}`, inject `token` into `eforms_token`, and inject `instance_id` into the hidden instance field. forms.js MUST block submission until minting succeeds and MUST NOT overwrite a non-empty `eforms_token`. forms.js SHOULD reuse the minted token across refreshes/back-navigation within the same tab (e.g., sessionStorage keyed by `{form_id}`) until expiry; if cached state is unavailable, mint a fresh token.

	- Email-failure remint (normative): When a form element has `data-eforms-remint="1"`, forms.js MUST clear the cached token for that `form_id` from sessionStorage, POST to `/eforms/mint`, inject the fresh `{token, instance_id}`, remove the `data-eforms-remint` attribute, and re-enable submission. This occurs on DOMContentLoaded before the normal injection logic.

	- assets.css_disable=true lets themes opt out
	- On submit failure, focus the first control with an error
	- Focus styling (a11y): do not remove outlines unless visible replacement is provided. For inside-the-box focus: outline: 1px solid #b8b8b8 !important; outline-offset: -1px;
	- When `html5.client_validation=true`, forms.js MUST skip pre-submit summary focus to avoid double-focus with browser-native validation, but still move focus to the first invalid control after server rerenders. When `html5.client_validation=false`, forms.js owns focus for both pre-submit and post-rerender states.
	- Only enqueue provider script when the challenge is rendered:
		- Turnstile (v1): https://challenges.cloudflare.com/turnstile/v0/api.js (defer, crossorigin=anonymous)

	- Reserved for future extension (not implemented in v1):
		- hCaptcha: https://hcaptcha.com/1/api.js (defer)
		- reCAPTCHA v2: https://www.google.com/recaptcha/api.js (defer)
- Do not load challenge script on the initial GET. `always_post` mode does not override this; challenges are rendered on POST rerender or during verification only.
	- Secrets hygiene: Render only site_key to HTML. Never expose secret_key or verify tokens in markup/JS. Verify server-side; redact tokens in logs.

<a id="sec-implementation-notes"></a>
24. NOTES FOR IMPLEMENTATION
	- Reference: Escape targets for `<textarea>` and other sinks follow [Central Registries (Internal Only)](#sec-central-registries).
	- Reference: Asset enqueueing requirements are summarized in [Lazy-load lifecycle (components & triggers)](#sec-lazy-load-matrix).
	- Reference: Directory permissions, deny rules, and rotation constraints follow [Security → Shared lifecycle and storage contract](#sec-shared-lifecycle) and [Security invariants](#sec-security-invariants).
	- Reference: Option-key and class-token limits derive from [Template Model](#sec-template-model-fields).
	- Non-normative tips (supplemental):
		- Sanitize template classes by splitting on whitespace, keeping `[A-Za-z0-9_-]{1,32}` tokens, truncating longer tokens to 32 characters, deduplicating while preserving the first occurrence, joining with single spaces, capping the final attribute at 128 characters, and omitting the attribute when empty.
		- Filename policy reference: see [Uploads → Filename policy](#sec-uploads-filenames).
		- TemplateValidator sketch: pure-PHP walkers with per-level allowed-key maps; normalize scalars/arrays; emit `EFORMS_ERR_SCHEMA_*` with path details (e.g., `fields[3].type`).
		- Caching: prefer in-request static memoization only; avoid cross-request caches.
		- No WordPress nonce usage; submission token TTL is controlled via `security.token_ttl_seconds`.
		- `max_input_vars` heuristic is conservative; it does not count `$_FILES`.
		- Keep deny rules (index.html + .htaccess/web.config) in uploads/logs directories.
		- Renderer & escaping: keep canonical values unescaped until sink time; avoid double-escaping or mixing escaped/canonical values.
		- Helpers:
			- `Helpers::nfc(string $v): string` — normalize to Unicode NFC; no-op without intl.
			- `Helpers::cap_id(string $id, int $max=128): string` — length cap with middle truncation + stable 8-character base32 suffix.
			- `Helpers::bytes_from_ini(?string $v): int` — parses K/M/G; `"0"`/null/`""` → `PHP_INT_MAX`; clamps non-negative.
			- `Helpers::h2(string $id): string` — derive the shared `[0-9a-f]{2}` shard (see `{h2}` directories in [Security → Shared lifecycle and storage contract](#sec-shared-lifecycle)).
			- `Helpers::throttle_key(Request $r): string` — derive the throttle key per [Throttling](#sec-throttling) using the resolved client IP regardless of `privacy.ip_mode`.
		- Renderer consolidation:
			- Shared text-control helper centralizes attribute assembly; `<input>` and `<textarea>` emitters stay small and focused.
			- Keep group controls (fieldset/legend), selects, and file(s) as dedicated renderers for a11y semantics.
		- Minimal logging via `error_log()` is a good ops fallback; JSONL is the primary structured option.
		- Fail2ban emission isolates raw IP use to a single, explicit channel designed for enforcement.
		- Fail2ban rotation uses the same timestamped rename scheme as JSONL.
		- If `logging.fail2ban.file` is relative, resolve it under `uploads.dir` (e.g., `${uploads.dir}/f2b/eforms-f2b.log`).
		- Uninstall: when `install.uninstall.purge_logs=true`, also delete the Fail2ban file and rotated siblings.
		- Header name comparisons are case-insensitive; cap header length at ~1–2 KB before parsing to avoid pathological inputs.
		- Recommend `logging.mode="minimal"` in setup docs to capture critical failures; provide guidance for switching to `"off"` once stable.
		- Element ID length cap: cap generated IDs (e.g., `"{form_id}-{field_key}"`) at 128 characters via `Helpers::cap_id()`.
		- Permissions fallback: create directories `0700` (files `0600`); on failure, fall back once to `0750/0640` and emit a single warning when logging is enabled.
		- Hidden-token mode does not require JS.
		- CI scaffolding:
			- Descriptor resolution test: iterate `Spec::typeDescriptors()`, resolve all handler IDs, and assert each is callable.
			- Schema parity test: generate JSON Schema from `TEMPLATE_SPEC` (or vice versa) and diff; fail on enum/required/shape drift.
			- Determinism tests: fixed template + inputs → assert identical error ordering, canonical values, and rendered attribute sets.
			- TTL alignment test: assert `minted_record.expires - minted_record.issued_at == security.token_ttl_seconds`.
			- WP-CLI smoke tests:
				- Command to POST without Origin to confirm hard/missing policy behavior.
				- Command to POST oversized payload to verify RuntimeCap handling.

<a id="sec-email-templates"></a>
25. EMAIL TEMPLATES (REGISTRY)
	- Files: /templates/email/{name}.txt.php and {name}.html.php
	- JSON "email_template": "foo" selects those files ("foo.html.php" when email.html=true); missing/unknown names raise an error
	- Template inputs:
	- form_id, submission_id, submitted_at (UTC ISO-8601)
	- fields (canonical values only, keyed by field key)
	- meta limited to { submitted_at, ip, form_id, submission_id }
	- uploads summary (attachments per Emailer policy)
	- Token expansion: {{field.key}}, {{submitted_at}}, {{ip}}, {{form_id}}, {{submission_id}}
	- Escaping:
	- text emails: plain text; CR/LF normalized
	- HTML emails: escape per context; no raw user HTML injected
	- Security hardening: template PHP files include ABSPATH guard (defined('ABSPATH') || exit;).

<a id="sec-templates-to-include"></a>
26. TEMPLATES TO INCLUDE
	1. [`templates/forms/quote-request.json`](../templates/forms/quote-request.json)
		- Canonical “Quote Request” flow with `success.mode="redirect"` to illustrate post-submit navigation.
		- Demonstrates row-group wrappers for a temporary two-column layout (`row_group` start/end with `class="columns_nomargins"`).
		- Shows required `tel_us` and `zip_us` fields with autocomplete hints alongside standard `name`/`email` inputs.
		- Email block includes `include_fields` that capture the submitter IP and applies `display_format_tel="xxx-xxx-xxxx"`.
	2. [`templates/forms/contact.json`](../templates/forms/contact.json)
		- Inline-success contact form (`success.mode="inline"`) that thanks the user without redirecting.
		- Example of injecting sanitized template fragments via `before_html` on the first field.
		- Highlights placeholder usage, explicit `size` for the email control, and subject templating (`"Contact Form - {{field.name}}"`).
	3. eforms.css
		- Keep your existing CSS file as-is. Not reproduced here to keep this text plain.

<a id="sec-appendices"></a>
27. APPENDICES
	1. Codes (examples)
	- EFORMS_ERR_TOKEN - "Security check failed."
	- EFORMS_ERR_HONEYPOT - "Form submission failed."
	- EFORMS_ERR_TYPE - "Unsupported field type."
	- EFORMS_ERR_ACCEPT_EMPTY - "No allowed file types for this upload."
	- EFORMS_ERR_ROW_GROUP_UNBALANCED - "Form configuration error: group wrappers are unbalanced."
	- EFORMS_ERR_SCHEMA_UNKNOWN_KEY - "Form configuration error: unknown setting."
	- EFORMS_ERR_SCHEMA_ENUM - "Form configuration error: invalid value."
	- EFORMS_ERR_SCHEMA_REQUIRED - "Form configuration error: missing required setting."
	- EFORMS_ERR_SCHEMA_TYPE - "Form configuration error: wrong type."
	- EFORMS_ERR_SCHEMA_OBJECT - "Form configuration error: invalid object shape."
	- EFORMS_ERR_DUPLICATE_FORM_ID - "Form configuration error: duplicate form id on page."
	- EFORMS_ERR_LEDGER_IO - "Submission failed due to a server error. Please try again."
	- EFORMS_ERR_UPLOAD_TYPE - "This file type isn't allowed."
		- EFORMS_ERR_EMAIL_SEND - "We couldn't send your message. Please try again."
		- EFORMS_ERR_INLINE_SUCCESS_REQUIRES_NONCACHEABLE - "Inline success requires a non-cacheable page."
		- EFORMS_ERR_THROTTLED - "Please wait a moment and try again."
		- EFORMS_ERR_CHALLENGE_FAILED - "Please complete the verification and submit again."
		- EFORMS_CHALLENGE_UNCONFIGURED – "Verification unavailable; please try again."
	- EFORMS_RESERVE - "Reservation outcome (info)."
	- EFORMS_LEDGER_IO - "Ledger I/O problem."
	- EFORMS_FAIL2BAN_IO - "Fail2ban file I/O problem."
	- EFORMS_FINFO_UNAVAILABLE - "File uploads are unsupported on this server."

	2. <a id="sec-accept-token-map"></a>Accept Token -> MIME/Extension Map (informative summary)
	- Canonical rules live in [Uploads → Accept-token policy](#sec-uploads-accept-tokens). This appendix summarizes the current defaults for quick reference.
	- Current defaults (informative): image → image/jpeg, image/png, image/gif, image/webp (SVG excluded); pdf → application/pdf. Other tokens are excluded by default (e.g., image/svg+xml, image/heic, image/heif, image/tiff).
	- Applies to both `file` and `files` field types; multi-file inputs reuse these lists, and email attachment policy remains governed by [Email Delivery](#sec-email).


	3. Schema Source of Truth
	- The PHP `TEMPLATE_SPEC` array (defined in `src/Validation/TemplateValidator.php`) is the sole authoritative source at runtime for structural validation (enums, required/unknown-key rules, type enforcement)

<a id="sec-known-debt"></a>
28. KNOWN DEBT & OPTIMIZATIONS
	(No outstanding debt items at this time.)

<a id="sec-past-decisions"></a>
29. PAST DECISION NOTES
	See [PAST_DECISIONS.md](PAST_DECISIONS.md) for architectural decisions, design principles, and major simplifications.
