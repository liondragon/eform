electronic_forms - Spec
================================================================

<a id="sec-objective"></a>
1. OBJECTIVE
- Build a dependency-free, lightweight plugin that renders and processes multiple forms from JSON templates with strict DRY principles and a deterministic pipeline.
- Internal use on 4-5 sites, ~40 submissions/day across forms, USA only. Not publicly marketed/distributed.
- Targets publicly accessible contact forms without authenticated sessions. Cache-friendly and session-agnostic: WordPress nonces are never used. The plugin does not implement authenticated user flows, account dashboards, or any account management surfaces.
- Out of scope for this project: any multi-step or multi-page questionnaires/wizards, or flows that depend on persistent user identity beyond a single submission.
- No admin UI.
- Focus on simplicity and efficiency; avoid overengineering. Easy to maintain and performant for intended use.
- Lazy by design: the configuration snapshot is bootstrapped lazily on first access (Renderer/SubmitHandler/Emailer/Security) rather than at plugin load; modules initialize only when their triggers occur (see [Central Registries → Lazy-load Matrix (§6)](#sec-lazy-load-matrix) and [Configuration: Domains, Constraints, and Defaults (§17)](#sec-configuration).)
- No database writes; file-backed one-time token ledger for duplicate-submit prevention (no Redis/queues).
- Clear boundaries: render vs. validate vs. send vs. log vs. upload.
- Deterministic pipeline and schema parity: big win for testability.
- Lazy loading of registries/features and config snapshot: keeps coupling down.

<a id="sec-scope"></a>
2. SCOPE
	1. IN
		- Render forms
		- Normalization / validation / coercion
		- HTML sanitization for textarea_html and for template fragments (before_html / after_html)
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
		- ships with conservative security defaults and minimal operational overhead; see [Configuration: Domains, Constraints, and Defaults (§17)](#sec-configuration) for the authoritative configuration table and exact default values.
	- Opt-ins (enable only if needed):
		- Throttling, adaptive/always challenge
		- Fail2ban emission
		- Rejected-submission logging → set logging.mode="jsonl" (or "minimal") and logging.level>=1
		- Header logging, PII logging, SMTP debug

<a id="sec-architecture"></a>
3. ARCHITECTURE AND FILE LAYOUT
	- /electronic_forms/
		- eforms.php		// bootstrap + autoloader + shortcode/template tag
		- uninstall.php	 // optional purge of uploads/logs (reads flags from Config; see 17)
		- uninstall.php requires __DIR__.'/src/Config.php' and calls Config::bootstrap() so it can read purge flags without relying on WP hooks.
		- Hardening: uninstall.php must start with defined('WP_UNINSTALL_PLUGIN') || exit; and guard WP calls:
			- if (!function_exists('wp_upload_dir')) { require_once ABSPATH.'wp-admin/includes/file.php'; }.
			- If wp_upload_dir() still isn’t available, abort uninstall gracefully.
			- ship index.html and server deny rules (.htaccess, web.config) in templates directories; enforce filename allow-list and prevent traversal outside /templates/.
	- /src/
		- Config.php
		- Helpers.php		 // tiny esc/url/id-name/fs utilities
		- Logging.php		 // JSONL logger; rotation; masking
		- Email/
			- Emailer.php		 // build & send; safe headers; text/plain by default
		- Rendering/
			- Renderer.php		 // pure HTML; escape only at sinks
			- FormRenderer.php	// handles GET rendering; enqueues CSS/JS
		- Submission/
			- SubmitHandler.php	 // handles POST submissions, PRG
		- Validation/
			- Normalizer.php		// normalization
			- Validator.php		 // normalize -> validate -> coerce (deterministic)
			- TemplateValidator.php	// strict JSON structural preflight (unknown keys/enums/combos; accept[] intersection)
		- Security/
			- Security.php		// token, honeypot, min-fill-time, max-form-age
			- Throttle.php		// request throttling
			- Challenge.php		 // optional challenge logic
		- Uploads/
			- Uploads.php		 // normalize/validate/move uploads; enforce caps/allow-list; GC/retention; name/perms policy
	- /schema/
		- template.schema.json	// design-time only (editor/CI lint); kept in sync with PHP spec
	- /templates/
		- forms/
			- contact.json		// kebab-case filenames only
	- email/
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
	- Structural spec (PHP): TEMPLATE_SPEC array drives enums/required/unknown-key rules; JSON Schema is generated from or checked against it to avoid drift

<a id="sec-template-model"></a>
5. TEMPLATE MODEL
	1. Field Generation and Namespacing
	- Template field keys may include:
		- key (slug), type, label?, placeholder?, required (bool), size (1-100; text-like only: text, tel, url, email), autocomplete?, options (for radios/checkboxes/select), class?, max_length?, min?, max?, step?, pattern?, before_html?, after_html?
	- key (slug): required; must match `/^[a-z0-9_-]{1,64}$/` (lowercase). [] prohibited to prevent PHP array collisions; reserved keys remain disallowed. Dropping `:` keeps generated IDs/CSS selectors safe.
	- autocomplete: exactly one token. "on"/"off" accepted; else must match WHATWG tokens (name, given-name, family-name, email, tel, postal-code, street-address, address-line1, address-line2, organization, …). Invalid tokens are dropped.
	- size: 1-100; honored only for text-like controls (text, tel, url, email).
	- Renderer injects security metadata per the active submission mode. See [Security → Submission Protection for Public Forms (§7.1)](#sec-submission-protection) for the canonical hidden-token and cookie-mode contract (required hidden fields, cookie/EID minting, rerender reuse rules, and POST expectations).
	- Form tag classes: <form class="eforms-form eforms-form-{form_id}"> (template id slug)
        - Renderer-generated attributes:
		| Attribute(s) | Emission rule | Notes |
		|--------------|---------------|-------|
		| `id` | Hidden mode: `{form_id}-{field_key}-{instance_id}`. Cookie mode: `{form_id}-{field_key}-s{slot}` when slots are active; omit the suffix when no slot is assigned. | Slot suffix mirrors the deterministic renderer assignment; slotless instances remain suffix-free. |
		| `name` | `{form_id}[{field_key}]` | Append `[]` only for multivalue descriptors (checkboxes, multi-select). |
		| `required` | Mirrors the template’s `required: true|false`. | Server-side validation remains authoritative; the attribute is a UX hint only. |
		| `maxlength` / `minlength` | Emitted from `max_length` / `min_length` in the descriptor or template overrides. | Client-side mirrors of validator bounds; never relax the server rules. |
		| `min` / `max` / `step` | Derived from numeric/date traits in the descriptor. | Applies to number/date-like controls per type defaults or template overrides. |
		| `pattern` | Pulled from descriptor typing hints when provided. | Acts as a hint only; validator enforces canonical rules. |
		| `inputmode` | Provided by descriptor typing aids. | Complements `pattern` and other per-type editing helpers (see 11). |
		| `multiple` | Added when the descriptor reports `is_multivalue=true`. | Ensures DOM/UI affordances align with array POST bodies. |
		| `accept` | Mirrors descriptor/token-driven MIME allow-lists. | `image` token → `image/jpeg,image/png,image/gif,image/webp`; `pdf` token → `application/pdf`. |
		| `enterkeyhint` | `"send"` on the last text-like control or `<textarea>` in DOM order. | Best-effort UX affordance; does not affect validation or submission flow. |
        - Reserved field keys (templates must not use): form_id, instance_id, submission_id, eforms_token, eforms_hp, eforms_mode, eforms_slot, timestamp, js_ok, ip, submitted_at.
	- include_fields accepts template keys and meta keys:
		- allowed meta keys: ip, submitted_at, form_id, instance_id (hidden-mode only), submission_id, slot (available for email/logs only)
	- Template fragments (before_html / after_html):
		- Sanitized via wp_kses_post (same as textarea_html); sanitized result is canonical.
		- No inline styles. May not cross row_group boundaries.
	- Upload field options: for type=file/files, optional accept[], max_file_bytes, max_files (files only), email_attach (bool). Per-field values override global limits.
        - Client-side hints (summary):
		| Hint | Emission rule | Notes |
		|------|---------------|-------|
		| Attribute mirrors | Renderer copies validator bounds to HTML attributes (`max_length` → `maxlength`, numeric/date bounds → `min`/`max`/`step`, `min_length` → `minlength`). | Keeps UX hints aligned with server policy; the validator remains canonical. |
		| Editing aids | Per-type helpers emit `inputmode`, `pattern`, and related typing aids. | See [Built-in Field Types (Defaults; US-focused) (§11)](#sec-field-types) for the per-type matrix. |
		| Upload affordances | `accept` reflects descriptor tokens (`image` / `pdf`); other per-field upload options (e.g., `max_file_bytes`, `max_files`, `email_attach`) follow the descriptor defaults unless overridden. | Hints do not override the global upload caps enforced server-side. |
		| UX niceties | `enterkeyhint="send"` marks the final text-like control or `<textarea>`; other renderer-managed classes mirror template-provided `class` values. | All hints are advisory and never weaken validation. |

	2. Row Groups (Structured Wrappers)
	- pseudo-field: type=row_group with { mode:"start"|"end", tag:"div"|"section" (default div), class:"..." }
	- no key; no data; supports nesting
	- renderer adds a base wrapper class (e.g., "eforms-row") to each row_group element.
	- Dangling opens auto-closed at form end to keep DOM valid; emit one _global config error EFORMS_ERR_ROW_GROUP_UNBALANCED. A stray "end" with an empty stack is ignored and logged.
	- row_group pseudo-fields do not count toward validation.max_fields_per_form.
	- Row-group objects must omit key and allow only {type, mode, tag, class}; enforce additionalProperties:false.
	- Mis-balance reporting: if the row_group stack is mis-balanced at form end, emit a single _global config error (do not duplicate per-field errors).

	3. Template JSON
	- Location: /templates/forms/
	- Filename allow-list: /^[a-z0-9-]+\.json$/
	- Design-time schema pointer (optional but recommended): use a stable web URL to the schema in your repo (e.g., "${SCHEMA_URL}/template.schema.json") or a local absolute path. Avoid hard-coded /wp-content/plugins/... paths.
	- Minimal shape:
		- id (slug), version (string), title (string)
		- success { mode:"inline"|"redirect", redirect_url?, message? }
		- email { to, subject, email_template ("foo" -> templates/email/foo.*), include_fields[], display_format_tel? }
			- display_format_tel enum: "xxx-xxx-xxxx" (default), "(xxx) xxx-xxxx", "xxx.xxx.xxxx" (any other value falls back to default at runtime)
		- fields[] of field objects (see 5.1)
		- submit_button_text (string)
		- rules[] of bounded JSON rules (see 10)

	4. Options Shape
	- options = [{ key, label, disabled? }, ...]
	- stored value = option key; label is for rendering only
	- Validation rule: if options[i].disabled === true, that option key may not be submitted; selecting it is a validation error.

	5. Versioning & Cache Keys
	- prefer explicit version; fallback to filemtime()

	6. Validation (Design-time vs Runtime)
	- Runtime in PHP, 2 phases:
		- (0) Structural preflight by TemplateValidator
		- (1) Normalize -> Validate -> Coerce by Validator
	- /schema/template.schema.json is CI/docs only; ensure parity with TEMPLATE_SPEC
	- If JSON is malformed or missing keys, fail gracefully with a clear "Form configuration error" (no white-screen).
	- Unknown rule values are rejected by the PHP validator.
	- For file/files: accept[] ∩ global allow-list must be non-empty; else EFORMS_ERR_ACCEPT_EMPTY.
	- CI MUST validate /templates/forms/*.json against /schema/template.schema.json and assert parity with the PHP TEMPLATE_SPEC.
	- Enforce email.display_format_tel enum; unknown values are dropped at runtime but flagged in preflight.

	7. TemplateContext (internal)
	- TemplateValidator returns a normalized TemplateContext consumed by Renderer, Validator, and Security.
	- Keys include: has_uploads (bool), descriptors[] (resolved field descriptors), version, id, email, success, rules, fields (normalized copies), max_input_vars_estimate (advisory).
	- Type Descriptors & Handler Resolution
		- TEMPLATE_SPEC provides type descriptors. Each descriptor bundles:
		{
			type: string,
			is_multivalue: bool,
			html: { tag:"input|textarea|select", type?, multiple?, inputmode?, pattern?, attrs_mirror:[...] },
			validate: { required?, pattern?, range?, canonicalize? },
			handlers: {
			validator_id: string,	 // e.g., "email"
			normalizer_id: string,	// e.g., "email"
			renderer_id: string	 // e.g., "email"
			},
			constants: { ... },		 // per-type constants mirrored to DOM (e.g., spellcheck=false)
			alias_of?: string		 // explicit alias target type name when applicable
		}
		- Handler IDs are short tokens scoped to each registry (e.g., "email", "text"). IDs are resolved to callables once during preflight via per-class private registries (see [Central Registries (Internal Only) (§6)](#sec-central-registries).)
		- Resolution is fail-fast: unknown IDs throw a deterministic RuntimeException including {type, id, registry, spec_path}. CI surfaces exact descriptor failures.
		- Alias hygiene: when alias_of is present, assert the alias shares handler IDs with its target; traits may differ. CI enforces alias invariants.

	- Resolved-descriptor cache (per request)
		- For each field (template key + type), precompute a resolved descriptor:
		{
			key, type, is_multivalue,
			name_tpl: "{form_id}[{key}]" | "{form_id}[{key}][]",
			id_prefix: "{form_id}-{key}-",
			html, validate, constants,
			attr_mirror: [...],
			handlers: { v: callable, n: callable, r: callable }
		}
		- Treat resolved descriptors as immutable after preflight and reuse in both Renderer and Validator (no re-merge on POST). Zero string lookups in hot paths; perfect determinism.

<a id="sec-central-registries"></a>
6. CENTRAL REGISTRIES (INTERNAL ONLY)
	- Static registries (no public filters): field_types, validators, normalizers/coercers, renderers.
	- Registries are private to each owning class and exposed only through resolve() helpers.
		- Example:
			- Validator: private const HANDLERS = ['email' => [self::class,'validateEmail'], ...]
			- Normalizer: private const HANDLERS = ['scalar' => [self::class,'normalizeScalar'], ...]
			- Renderer: private const HANDLERS = ['text' => [self::class,'emitInput'], 'textarea' => [...], ...]
			- public static function resolve(string $id): callable { if (!isset(self::HANDLERS[$id])) throw RuntimeException(...); return self::HANDLERS[$id]; }
	- Uploads registry settings: token->mime/ext expansions; image sanity; caps
        - Accept token map lives in [Appendices → Accept Token Map (§26.2)](#sec-accept-token-map). Default tokens remain image and pdf; do not add new tokens without explicit review.
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
		| Config snapshot | Lazy | First `Config::get()` or entry into `FormRenderer::render()`, `SubmitHandler::handle()`, `Security::token_validate()`, `Emailer::send()`, **`/eforms/prime`**, or **`/eforms/success-verify`** | Idempotent (per request); entry points decide when to create the snapshot; see [Configuration: Domains, Constraints, and Defaults (§17)](#sec-configuration) for bootstrap timing. |
		| TemplateValidator / Validator | Lazy | Rendering (GET) preflight; POST validate | Builds resolved descriptors on first call; memoizes per request (no global scans). |
		| Static registries (`HANDLERS` maps) | Lazy | First call to `resolve()` / class autoload | Autoloading counts as lazy; classes hold only const maps; derived caches compute on demand. |
		| Renderer / FormRenderer | Lazy | Shortcode or template tag executes | Enqueues assets only when form present. |
		| Security (token/origin) | Lazy | **Hidden-token mint during GET render**, any token/origin check during POST, or `/eforms/prime` cookie mint | `FormRenderer` **delegates** all minting to Security helpers (no local UUID/TTL logic). Minting helpers do not load challenge/throttle and only read the config snapshot needed for TTL/paths. |
		| Uploads | Lazy | Template declares file(s) or POST carries files | Initializes finfo and policy only when needed. |
		| Emailer | Lazy | After validation succeeds (just before send) | SMTP/DKIM init only on send; skipped on failures. |
		| Logging | Lazy | First log write when `logging.mode != "off"` | Opens/rotates file on demand. |
		| Throttle | Lazy | When `throttle.enable=true` and key present | File created on first check. |
		| Challenge | Lazy | Only inside entry points: (1) `SubmitHandler::handle()` after `Security::token_validate()` returns `require_challenge=true`; (2) `FormRenderer::render()` on a POST re-render when `require_challenge=true`; or (3) verification step when a provider response is present (`cf-turnstile-response` / `h-captcha-response` / `g-recaptcha-response`). | Provider script enqueued only when rendered. Presence of `challenge.mode != "off"` MUST NOT initialize on initial GET; challenge loads only at the entry points above. |
		| Assets (CSS/JS) | Lazy | When a form is rendered on the page | Version via filemtime; opt-out honored. |
 	
<a id="sec-security"></a>
7. SECURITY
<a id="sec-submission-protection"></a>1. Submission Protection for Public Forms (hidden vs cookie)
<a id="sec-shared-lifecycle"></a>1. Shared lifecycle and storage contract
		- Mode selection stays server-owned: `[eform id=\"slug\" cacheable=\"false\"]` (default) renders in hidden-token mode; `cacheable=\"true\"` renders in cookie mode. All markup carries `eforms_mode`, and the renderer never gives the client a way to pick its own mode.
		- Canonical lifecycle (render → persist → POST → rerender/success):
			- Render (GET): Both modes inject `form_id`, `eforms_mode`, the fixed honeypot `eforms_hp`, and the static hidden `js_ok`. Responses include CSS/JS enqueueing decisions and caching headers per [Request Lifecycle (§19)](#sec-request-lifecycle). `eforms_mode` is informational; persisted records are authoritative.
			- Persist:
				- **Hidden mode**: `FormRenderer` calls **`Security::mint_hidden_record(form_id)`** to obtain `{ token, instance_id, issued_at, expires }` and to atomically write `tokens/{h2}/{sha256(token)}.json`. The renderer **does not** generate UUIDs/timestamps/TTL itself; it only embeds returned values into HTML.
				- **Cookie mode**: `/eforms/prime` calls **`Security::mint_cookie_record(form_id, slot?)`** to mint `eid` (`i-<UUIDv4>`), set `{ issued_at, expires, slots_allowed, slot }`, and write `eid_minted/{form_id}/{h2}/{eid}.json` with `Set-Cookie` when appropriate.
				- Minting helpers are **pure** w.r.t. validation/challenge (they do not evaluate origin/challenge, do not consult throttle, and do not rotate cookies except as specified for `/eforms/prime`); they may read the configuration snapshot for TTLs/paths and create directories/files with the permissions defined below (see [Security invariants (§7.1.2)](#sec-security-invariants)).
			- POST evaluate: `Security::token_validate()` reads the persisted record, returns `{ mode, submission_id, slot?, token_ok, hard_fail, require_challenge, cookie_present?, is_ncid?, soft_reasons? }`, and drives dedupe/challenge policy. NCID handling lives in [Security → NCIDs, Slots, and Validation Output (§7.1.4)](#sec-ncid).
			- Rerender/success: Error rerenders reuse authoritative metadata. Successful submits hand off to [Success Behavior (PRG) (§13)](#sec-success) for ticket minting and PRG behavior.
                - Directory sharding (`{h2}` placeholder) is universal: compute `Helpers::h2($id)` — `substr(hash('sha256', $id), 0, 2)` on UTF-8 bytes — and create the `{h2}` directory with `0700` perms before writing `0600` files. The same rule covers hidden tokens, minted cookies, ledger entries, throttles, and success tickets.
                - Regex guards (`/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i/` hidden tokens, `/^i-[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i/` cookie EIDs, `^[A-Za-z0-9_-]{22,32}$` instance IDs) run before disk access to weed out obvious tampering.
                - <a id="sec-ledger-contract"></a>Ledger reservation contract
                        - Duplicate suppression reserves `${uploads.dir}/eforms-private/ledger/{form_id}/{h2}/{submission_id}.used` via `fopen('xb')` (or equivalent) immediately before side effects.
                        - Treat `EEXIST` or any filesystem failure as a duplicate and log `EFORMS_LEDGER_IO` on unexpected IO errors.
                        - Honeypot short-circuits burn the same ledger entry, and submission IDs for all modes remain colon-free.

		- <a id="sec-security-invariants"></a>Security invariants (apply to hidden/cookie/NCID):
			- Minting helpers are authoritative: they return canonical metadata and persist records with atomic `0700`/`0600` writes (creating `{h2}` directories as needed).
			- Minting helpers never evaluate challenge, throttle, or origin policy; they only consult the configuration snapshot for TTLs/paths, and entry points embed the returned fields verbatim.
			- Minting/verification helpers MUST ensure a configuration snapshot exists by calling `Config::get()` on first use.
			- Error rerenders reuse the persisted record; rotation occurs only after expiry or a successful submission (PRG). Mode-specific challenge flows layer on top of this invariant.
			- Ledger reservation is uniform: reserve `${uploads.dir}/eforms-private/ledger/{form_id}/{h2}/{submission_id}.used` immediately before side effects, treat `EEXIST` as a duplicate, and burn honeypot/soft paths the same way.
			- Tampering guards are uniform: regex validation precedes disk access; mode/form_id mismatches or cross-mode payloads are hard failures; NCID fallbacks mark `token_ok=false` while preserving dedupe semantics.

<a id="sec-hidden-mode"></a>2. Hidden-mode contract
		- **Minting helper (authority)**:
			- `Security::mint_hidden_record(form_id)`:
				- Returns `{ token: UUIDv4, instance_id: base64url(16–24 bytes), issued_at: unix, expires: issued_at + security.token_ttl_seconds }`.
				- Writes JSON record at `tokens/{h2}/{sha256(token)}.json` with `{ mode:"hidden", form_id, instance_id, issued_at, expires }` (never rewritten on rerender).
			- `FormRenderer` must embed the returned `token`, `instance_id`, and `issued_at` (as `timestamp`) in HTML and **must not** generate or alter them (see [Security invariants (§7.1.2)](#sec-security-invariants)).
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
			- Token lookup MUST succeed; the record MUST say `mode:"hidden"` for the same `form_id`, and TTL MUST be valid.
			- Failure is a hard `EFORMS_ERR_TOKEN` when `security.submission_token.required=true`.
			- When `security.submission_token.required=false`, continue with a soft label `token_soft` and an NCID-derived `submission_id` per
			  [Security → NCIDs, Slots, and Validation Output (§7.1.4)](#sec-ncid) (hidden-mode NCID). This enables duplicate suppression
			  and success tickets even when the token is absent or expired.
			- Schema requirement (when a hidden-token record is present): the record MUST contain a conformant `instance_id` (base64url, 16–24 bytes). If present but non-conformant → hard failure. When continuing via hidden-mode NCID due to a missing/expired record, this check is not evaluated.
			- Expired or missing records trigger the same policy as above. Replay after ledger burn is a hard fail.
		- Dedup behavior:
			- `submission_id` equals the raw token when the hidden record validates; the NCID fallback above replaces it only when continuation occurs without a hidden token.
			- Ledger handling follows [Security invariants (§7.1.2)](#sec-security-invariants) and [Security → Ledger reservation contract (§7.1.1)](#sec-ledger-contract).
			- Hard failures present `EFORMS_ERR_TOKEN` (“This form was already submitted or has expired - please reload the page.”); soft paths retain the original record for deterministic retries.

<a id="sec-cookie-mode"></a>3. Cookie-mode contract
		- **Minting helper (authority)**:
			- `Security::mint_cookie_record(form_id, slot?)`:
				- Returns `{ eid: "i-" UUIDv4, issued_at: unix, expires: issued_at + security.token_ttl_seconds, slots_allowed:[], slot:null|int }` (slot handling per policy).
				- Writes JSON record at `eid_minted/{form_id}/{h2}/{eid}.json` with `{ mode:"cookie", form_id, eid, issued_at, expires, slots_allowed, slot }`.
				- For `/eforms/prime`, sets `Set-Cookie: eforms_eid_{form_id}={eid}` **only when** minting a fresh EID; unions `slots_allowed` on reuse; never rewrites `issued_at`/`expires`.
			- `/eforms/prime` must use the values returned by `Security::mint_cookie_record(…)` and **must not** generate or alter EIDs/timestamps/TTLs itself.
		- Markup: GET renders remain deterministic: no `instance_id`, timestamp, or hidden token. When `security.cookie_mode_slots_enabled=true` and `security.cookie_mode_slots_allowed` is non-empty, each instance emits `eforms_slot`, a matching hidden `<input>`, and a 1×1 `/eforms/prime?f={form_id}&s={slot}` pixel (`aria-hidden="true"`, fixed size so assistive tech ignores the noise). Slotless deployments omit both the field and the `s` query parameter. `/eforms/prime` responds 204 with `Cache-Control: no-store`.
		- Persisted record (`eid_minted/{form_id}/{h2}/{eid}.json`):
			| Field			 | Notes |
			|-----------------|-------|
			| `mode`			| Always `"cookie"`.
			| `form_id`		 | Authoritative binding for the EID.
			| `eid`			 | `i-<UUIDv4>` minted by `/eforms/prime`.
			| `issued_at`/`expires` | TTL enforced server-side; never rewritten on reuse.
			| `slots_allowed` | Deduplicated list of **observed** slots for this EID, updated atomically by `/eforms/prime` (union only). Slotless installs keep `[]`.
			| `slot`			| Canonical slot: **set to the single observed slot when and only when `|slots_allowed| == 1`; else `null`.** Never set by POST; only `/eforms/prime` transitions it via the rule above. Once `|slots_allowed| > 1`, `slot` remains `null` for the lifetime of the EID.
		- Slot lifecycle (canonical semantics):
			- Renderer assigns a deterministic `eforms_slot` per instance; `/eforms/prime` **unions** that value into `slots_allowed` for the EID.
			- Canonical `slot` is derived purely from `slots_allowed`:
				- If after union `|slots_allowed| == 1` → `slot = that single value`.
				- If after union `|slots_allowed| > 1` → `slot = null` (and stays `null` until the EID expires/rotates).
			- POST **does not** mutate `slots_allowed` or `slot`; only `/eforms/prime` performs unions/derivation.
			- Effects on validation/dedupe (consistent with existing rules):
				- When the rendered instance included `eforms_slot`, POST **MUST** include the same value; `submission_id = eid__slot{slot}`.
				- When the rendered instance was slotless (no `eforms_slot` field), POST **MUST NOT** include `eforms_slot`; `submission_id = eid` (valid regardless of the record’s `slot`).
			- Rerender behavior: normal rerenders reuse the existing `{eid, slot}`; deterministic assignment minimizes collisions but does not override these rules.
                - POST requirements (policy-gated presence; tampering always hard-fails):
						- Scope: The first row applies to slotless renders. All subsequent rows assume the rendered instance included `eforms_slot`.
		| Scenario | Required fields / behavior | Rejection cases |
		|----------|---------------------------|-----------------|
		| Rendered instance slotless | Do not include eforms_slot; `submission_id = eid`. | Including eforms_slot → HARD FAIL (EFORMS_ERR_TOKEN). |
		| `security.cookie_missing_policy="hard"` | Present `eforms_eid_{form_id}` (matches `/^i-[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i/`) and a matching minted record `{mode:"cookie", form_id, eid}`. | Missing/invalid cookie or missing record → **HARD FAIL**. |
		| `security.cookie_missing_policy="challenge"` | Prefer the cookie; when missing/invalid, continue via NCID with `token_ok=false`, add `cookie_missing`, set `require_challenge=true`, and defer delivery until verification. | Tampering still hard-fails even on the NCID path. |
		| `security.cookie_missing_policy="soft"` / `"off"` | Prefer the cookie; when missing/invalid, continue via NCID with `token_ok=false` and add `cookie_missing` (no challenge). | Missing cookie alone does not hard-fail, but tampering rules still apply. |
		| All policies | Always validate the minted record `{mode:"cookie", form_id, eid}` before trusting the POST body. | Mode/form_id mismatch, mixing hidden tokens into cookie submissions, forged/malformed EID, cross-mode payloads, or slot violations → **HARD FAIL**. |
		| Slots disabled | Omit `eforms_slot`. | Any posted slot is tampering → `EFORMS_ERR_TOKEN`. |
		| Slots enabled | Posted `eforms_slot` must be an integer 1–255 present in both `security.cookie_mode_slots_allowed` and the record’s `slots_allowed`; slotless records (`slot:null`, empty `slots_allowed`) reject posted slots. | Value outside either allow-list or a non-integer post → **HARD FAIL**. |
		| Persisted slot non-null | Submitted `eforms_slot` MUST equal the persisted `slot`. | Missing/mismatched slot → **HARD FAIL**. |
		| Persisted slot null with observed slots | Accept only posted slots enumerated in `slots_allowed`; omit `eforms_slot` only when the renderer emitted a slotless instance. | Unexpected or missing slot when `slots_allowed` is non-empty → **HARD FAIL**. |
		- All **HARD FAIL** cases above surface `EFORMS_ERR_TOKEN`; see [Security invariants (§7.1.2)](#sec-security-invariants).
		- Rerender + rotation (supplements [Security invariants (§7.1.2)](#sec-security-invariants)):
			- Normal rerenders reuse the existing `{eid, slot}` tuple. No mid-flow rotation occurs.
			- Challenge rerender **before verification** (i.e., when `require_challenge=true`): MUST clear `eforms_eid_{form_id}` via `Set-Cookie: … deleted` **and** embed the next `/eforms/prime?f={form_id}[&s={slot}]` pixel so the browser mints a new EID before the next POST.
			- Response **after successful verification** for `cookie_missing_policy="challenge"`: **do not rotate again** on that success response (no additional clear or remint); proceed with the validated submission and PRG.
			- Challenge rotation state machine: `require_challenge=true` is authoritative for "waiting for verification". Any rerender that still reports `require_challenge=true` (including retries after a failed provider response) follows the clear + fresh `/eforms/prime` rule above. Once verification succeeds and `require_challenge=false`, reuse the just-verified cookie with **no** additional rotation on the success response. Other cookie-loss policies (`off`/`soft`/`hard`) never rotate mid-flow.
			- `/eforms/prime` MUST load the minted record before skipping `Set-Cookie`. A missing/truncated/expired record is treated as stale and MUST mint a fresh EID so implementations never “adopt” a forged/orphaned cookie.
			- `/eforms/prime` sends `Set-Cookie` only when minting a fresh EID; otherwise it unions the observed slot into `slots_allowed` and leaves timestamps untouched.
		- Dedup behavior:
			- `submission_id` is the EID with an optional `__slot{n}` suffix when slots are active (or an NCID when allowed by policy above).
			- Ledger handling and tamper responses follow [Security invariants (§7.1.2)](#sec-security-invariants) and [Security → Ledger reservation contract (§7.1.1)](#sec-ledger-contract); cookie-specific policies above govern the NCID fallback labels.
			- **Record retention on NCID**: when validation continues via an NCID, leave any previously minted cookie record untouched until it naturally expires. Do **not** delete, rewrite, or repurpose it for the NCID flow; ledger dedupe still treats the orphaned cookie record as reserved for its original EID.
			- **Prime after NCID**: a rerender triggered by the NCID path still embeds `/eforms/prime`; because no cookie accompanies that request, `/eforms/prime` MUST mint a brand-new EID and issue a new cookie while leaving the abandoned record on disk. Later `/eforms/prime` hits during the same TTL likewise mint fresh EIDs (never attempting to "resurrect" the orphan), and once the orphan expires the next call proceeds as a normal fresh mint.
			- These rules ensure NCID dedupe remains stable for the flow that lacked a cookie while avoiding silent adoption or overwrite of server records minted earlier.
		- <a id="sec-slot-selection"></a>Slot selection (minimal invariants):
			- When `cookie_mode_slots_enabled=true`, the renderer MUST choose the `eforms_slot` deterministically per GET render and MUST reuse that choice on rerender; clients cannot pick slots.
			- Determinism MUST depend only on inputs available at render time (e.g., `form_id`, the allowed-slots set, and the instance’s document order/index), not on client state or timing.
			- If multiple instances of the same `form_id` are rendered on one page and there are more instances than values in `security.cookie_mode_slots_allowed`, excess instances MUST be slotless (omit `eforms_slot`; call `/eforms/prime` without `s`).
			- When multiple instances share a page, implementations SHOULD assign distinct allowed slots in document order and only reuse a slot after all allowed slots are used once; this minimizes canonical-slot nulling from collisions.
			- Implementations MAY offer an author override to pin a specific slot (e.g., `slot="N"`). If `N` is not allowed, ignore it and fall back to the implementation’s deterministic choice.
			- This section constrains determinism only; the exact algorithm (e.g., instance index, round-robin) is implementation-defined.
		- Prime endpoint semantics (`/eforms/prime`):
			- Parse `s` as an integer 1–255. When slots are disabled or `s` is not in `security.cookie_mode_slots_allowed`, treat it as `null` (do not set `slot` and do not modify `slots_allowed`).
			- Update `slots_allowed` atomically (write-temp + rename, or `flock()` + fsync). Never rewrite `issued_at`/`expires` on reuse.

<a id="sec-ncid"></a>4. NCIDs, slots, and validation output
		- `Security::token_validate()` exposes `{ mode, submission_id, slot?, token_ok, hard_fail, require_challenge, cookie_present?, is_ncid?, soft_reasons? }` to downstream handlers. Hidden mode normally reports the token; cookie mode reports the EID (with slot suffix when present) **or an NCID when `security.cookie_missing_policy!="hard"`**.
		- `cookie_present?` is true iff the request carried a syntactically valid `eforms_eid_{form_id}` cookie; otherwise false. Presence does not imply that a minted record exists.
		- NCID generation (`Helpers::ncid`) activates when an authoritative identifier is missing/invalid and the mode’s policy permits soft continuation:
			- Hidden mode: when the hidden token lookup fails and `security.submission_token.required=false`, emit an NCID with `token_ok=false`, add `token_soft` to `soft_reasons`, and set `is_ncid=true`. `cookie_present?` is omitted in this mode.
			- Cookie mode:
				- If `security.cookie_missing_policy="off"` → continue with `token_ok=false`, emit no `cookie_missing` soft reason, and set `submission_id="nc-…"` with `cookie_present=false` / `is_ncid=true` when the cookie was absent or malformed.
				- If `security.cookie_missing_policy="soft"` → continue with an NCID, add `cookie_missing` to `soft_reasons`, set `token_ok=false`, and set `cookie_present=false` when the cookie was absent or malformed.
				- If `security.cookie_missing_policy="challenge"` → mark `require_challenge=true`, add `cookie_missing`, set `cookie_present=false` when the cookie was absent or malformed, and defer delivery until [Security → Adaptive challenge (§7.12)](#sec-adaptive-challenge) succeeds; on success remove only `cookie_missing` from `soft_reasons`.
				- If `security.cookie_missing_policy="hard"` → **no NCID**; treat missing/invalid cookie as **HARD FAIL**.
			- Deterministic NCID recipe (matches [Configuration → Helpers::ncid (§17)](#sec-configuration)):
				- Inputs: `form_id`, the throttle `client_key` derived by `Helpers::throttle_key()` (privacy rules applied), the rolling `window_idx`, and the normalized POST body serialized as `canon_body` (stable key ordering, UTF-8 bytes).
				- Concatenate the UTF-8 inputs as `form_id . "\n" . client_key . "\n" . window_idx . "\n" . canon_body`, compute the SHA-256 digest, and prefix the hex output with `"nc-"` to form the `submission_id` consumed by the ledger and downstream flows.
				- The `window_idx` advances once per `security.token_ttl_seconds` horizon so NCID dedupe shares the same TTL boundary as hidden/cookie tokens; when the window rolls forward a fresh NCID (and ledger reservation) is minted automatically.
		- Canonical soft-reason labels: `min_fill`, `js_off`, `ua_missing`, `age_advisory`, `origin_soft`, `token_soft`, `throttle_soft`, `cookie_missing`, `challenge_unconfigured`.
		- Slot metadata from cookie mode flows into `submission_id` and `slots_allowed` as described in [Security → Cookie-mode contract (§7.1.3)](#sec-cookie-mode). Slotless deployments MUST omit `s` parameters so records remain `{ slot:null, slots_allowed:[] }`.
		- Ledger behavior for NCIDs follows [Security invariants (§7.1.2)](#sec-security-invariants) and [Security → Ledger reservation contract (§7.1.1)](#sec-ledger-contract); reserve `${submission_id}.used` immediately before side effects, treat duplicates (`EEXIST`) as spam, and continue with [Success Behavior (PRG) (§13)](#sec-success) using the NCID-based submission ID (applies to both cookie-missing and hidden-mode NCIDs).
<a id="sec-honeypot"></a>2. Honeypot
	- Runs after CSRF gate; never overrides a CSRF hard fail.
	- Stealth logging: JSONL { code:"EFORMS_ERR_HONEYPOT", severity:"warning", meta:{ stealth:true } }, header X-EForms-Stealth: 1. Do not emit "success" info log.
	- Field: eforms_hp (fixed POST name). Hidden-mode ids incorporate the per-instance suffix; cookie-mode ids are deterministic "{form_id}-hp-s{slot}" when slots are active; otherwise use a slotless id "{form_id}-hp". Must be empty. Submitted value is discarded and never logged.
	- Config: security.honeypot_response: "hard_fail" | "stealth_success" (default stealth_success).
	- Common behavior:
		- UX: treat as spam-certain, short-circuit before validation/coercion/email, delete temp uploads, and avoid cookie rotation.
		- Logging: record the throttle signal.
		- Ledger: attempt reservation to burn the ledger entry for that `submission_id`.
	- "stealth_success":
		- UX: mimic success (inline PRG cookie + 303, or redirect).
		- Logging: do not count as real successes; log stealth:true.
	- "hard_fail":
		- UX: re-render with generic global error (HTTP 200) and no field-level hints.
		- Logging: emit no success log.

<a id="sec-timing-checks"></a>3. Timing Checks
	- min_fill_time default 4s (soft; configurable). Hidden-mode measures from the original hidden timestamp (reused on re-render). Cookie-mode measures from the minted record’s `issued_at` (prime pixel time) and ignores client timestamps entirely.
	- Max form age:
		- Cookie mode: enforce via minted record `expires`. Expired → treat as missing cookie and apply `security.cookie_missing_policy`. Because `/eforms/prime` never refreshes `issued_at`/`expires` for a still-valid cookie, the countdown is monotonic: QA fixtures and POST handlers can assert that a re-primed-but-unexpired cookie continues to age out on the original schedule, while an expired record prompts a full remint (new timestamps + Set-Cookie).
		- Hidden-mode: posted timestamp is best-effort; over `security.max_form_age_seconds` → +1 soft (never hard on age alone).
	- js_ok flips to "1" on DOM Ready (soft unless `security.js_hard_mode=true`, then HARD FAIL). Cookie-mode markup keeps the field static; only the value toggles via JS.

<a id="sec-origin-policy"></a>4. Headers (Origin policy)
	- Normalize + truncate UA to printable chars; cap length security.ua_maxlen.
	- Origin check: normalize to scheme+host+effective port (80/443 normalized; non-default ports significant). origin_state = same | cross | unknown | missing.
	- Policy (security.origin_mode): off (no signal), soft (default), hard (hard fail on cross/unknown; missing depends on origin_missing_hard).
	- Log only origin_state (no Referrer). Referrer is not consulted.
	- Security::origin_evaluate() returns {state, hard_fail, soft_reasons?: string[]}.
	- When `origin_mode="soft"` and the evaluated request is cross-origin or unknown (respecting `origin_missing_hard`), add `"origin_soft"` to `soft_reasons`.
	- Operational guidance: Only enable origin_mode=hard + origin_missing_hard=true after validating your environment (some older agents omit Origin). Provide a tiny WP-CLI smoke test that POSTs without Origin to verify behavior.

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
	- Hidden-mode checks:
		- Valid hidden token + matching record → PASS; ledger reservation burns token on first success.
		- Wrong form_id in hidden record or POST payload → HARD FAIL (tampering path).
		- Missing/expired hidden record → HARD FAIL when `security.submission_token.required=true`; add "token_soft" to soft_reasons when false.
		- Reused hidden token after ledger sentinel exists → HARD FAIL with `EFORMS_ERR_TOKEN`.
	- Cookie-mode checks:
		- Valid minted record + cookie → PASS; ledger burns `eid` (+slot when enabled).
		- Missing minted record for posted `eid` (stale cache) → HARD FAIL.
		- Cookie present but form_id mismatch in record → HARD FAIL.
		- Hidden token posted while minted record says cookie → HARD FAIL (tampering).
		- Slot posted outside allow-list → HARD FAIL on `EFORMS_ERR_TOKEN`.
	- Honeypot checks:
		- Empty honeypot + valid submission → PASS.
		- Honeypot filled with `security.honeypot_response="stealth_success"` → mimic success UX, log stealth=true, burn ledger.
		- Honeypot filled with `security.honeypot_response="hard_fail"` → HARD FAIL with generic error, no success log.
	- Success handshake checks:
		- Valid success ticket + matching cookie → PASS; banner renders once and clears cookie/query.
		- Missing success ticket (cookie only) → suppress banner; log a warning (no change to `soft_reasons`).
		- Success ticket re-use after verifier burn → HARD FAIL / no banner.
	- Determinism checks:
		- Hidden-mode error rerender reuses original `instance_id`, `timestamp`, and hidden token.
		- Hidden-mode `timestamp` equals the record’s `issued_at` on all renders (first and rerenders); drift → hard fail.
		- Hidden-mode `instance_id` is identical across rerenders until token rotation; drift → hard fail.
		- Cookie-mode rerender emits identical markup (no new randomness) and reuses the minted `eid` and slot.
		- Renderer id/name attributes stable per descriptor; attr mirror parity holds.
<a id="sec-test-qa"></a>7. Test/QA Checklist (mandatory)
	- Hidden-mode scenarios → follow [Security → Hidden-mode contract (§7.1.2)](#sec-hidden-mode) and the POST flow coverage in [Request Lifecycle → POST (§19.2)](#sec-request-lifecycle-post).
	- Cookie-mode scenarios → follow [Security → Cookie-mode contract (§7.1.3)](#sec-cookie-mode) for minted-record reuse, TTL re-priming, slot handling, and challenge flows, with lifecycle storage details from [Security → Shared Lifecycle and Storage Contract (§7.1.1)](#sec-shared-lifecycle).
	- NCID and ledger scenarios → follow [Security → Ledger reservation contract (§7.1.1)](#sec-ledger-contract) and [Security → NCIDs, Slots, and Validation Output (§7.1.4)](#sec-ncid) for cookie-loss policies, dedupe behavior, and redirect-only PRG handoffs.
	- Honeypot scenarios → follow [Security → Honeypot (§7.2)](#sec-honeypot) for both response modes.
	- Success-ticket scenarios → follow [Success Behavior (PRG) (§13)](#sec-success) for one-time banner display and TTL enforcement.
<a id="sec-spam-decision"></a>8. Spam Decision
	- Hard checks first: honeypot, token/origin hard failures, and hard throttle. Any hard fail stops processing.
	- `soft_reasons`: a deduplicated set of labels from the canonical list above.
	- When `cookie_missing_policy="challenge"` verification succeeds, remove only the `"cookie_missing"` label that policy added to `soft_reasons`. Other labels (from the canonical set above) remain counted. Hard failures still override.
	- Scoring (computed, not stored): let `soft_fail_count = |soft_reasons|`. Decision: `soft_fail_count >= spam.soft_fail_threshold` → spam-fail; `soft_fail_count = 1` → deliver as suspect; `soft_fail_count = 0` → deliver normal.
	- Accessibility note: `js_hard_mode=true` blocks non-JS users; keep opt-in.

<a id="sec-redirect-safety"></a>9. Redirect Safety
	- wp_safe_redirect; same-origin only (scheme/host/port).

<a id="sec-suspect-handling"></a>10. Suspect Handling
	- add headers: X-EForms-Soft-Fails, X-EForms-Suspect; subject tag (configurable)
	- X-EForms-Soft-Fails value = `|soft_reasons|` (computed length of the deduplicated set)

<a id="sec-throttling"></a>11. Throttling (optional; file-based)
	- As previously specified: fixed 60s window, small JSON file, flock; soft over-limit → add `"throttle_soft"` to `soft_reasons`; hard over-limit = HARD FAIL.
	- Key derivation respects privacy.ip_mode; storage path ${uploads.dir}/throttle/{h2}/{key}.json with `{h2}` derived from the key per [Security → Shared Lifecycle and Storage Contract (§7.1.1)](#sec-shared-lifecycle)’s shared sharding and permission guidance; GC files >2 days old.

<a id="sec-adaptive-challenge"></a>12. Adaptive challenge (optional; Turnstile preferred)
	- Modes: off | auto (require when `soft_reasons` is non-empty) | always (evaluated after the Security gate populates `soft_reasons`).
	- Providers: turnstile | hcaptcha | recaptcha v2. Verify via WP HTTP API (short timeouts). Unconfigured required challenge adds `"challenge_unconfigured"` to `soft_reasons` and logs `EFORMS_CHALLENGE_UNCONFIGURED`.
	- If a challenge is required (by `challenge.mode` or `cookie_missing_policy="challenge"`) but no provider is correctly configured, set `challenge_unconfigured` in `soft_reasons`, set `require_challenge=false`, and continue as if the cookie policy were soft (proceed via NCID when applicable). Do not hard-fail solely because the provider is unavailable.
	- Bootstrap boundaries & where checks happen:
		- No eager checks at plugin load. Whether challenge is needed is determined inside `SubmitHandler::handle()` after `Security::token_validate()` sets `require_challenge`, or during a POST re-render when `require_challenge=true`, or during verification when a provider response is present.
		- `challenge.mode` is read only when an entry point has already required the configuration snapshot (e.g., during POST handling or the subsequent re-render). This preserves lazy config bootstrap semantics in [Template Model (§5)](#sec-template-model)/[Configuration: Domains, Constraints, and Defaults (§17)](#sec-configuration).
	- Render only on POST re-render when required (or always); never on initial GET unless [Security → Submission Protection for Public Forms (§7.1)](#sec-submission-protection) requires challenge.
		- In cookie mode:
			- **Before verification** (when `require_challenge=true`), the challenge rerender MUST clear `eforms_eid_{form_id}` and embed the `/eforms/prime?f={form_id}[&s={slot}]` pixel to mint a new EID before the next POST (see [Security → Cookie-mode contract (§7.1.3)](#sec-cookie-mode)).
			- **After successful verification** for `cookie_missing_policy="challenge"`, do **not** rotate the cookie again on that success response; proceed to deliver/PRG with the just-verified submission.
	- Turnstile → cf-turnstile-response; hCaptcha → h-captcha-response; reCAPTCHA v2 → g-recaptcha-response.

<a id="sec-validation-pipeline"></a>
8. VALIDATION & SANITIZATION PIPELINE (DETERMINISTIC)
	0. Structural preflight (stop on error; no field processing)
	- Unknown keys rejected at every level (root/email/success/field/rule).
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
		- Do not reject here. If a single-value field received an array, retain array for Validate to reject deterministically.
	- No rejection allowed in Normalize.

	3. Validate (authoritative; may reject)
	- Check required, length/pattern/range, allow-lists, cross-field rules (see [Cross-Field Rules (BOUNDED SET) (§10)](#sec-cross-field-rules)).
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
	- Defer file moves until global success; move to private dir; perms 0600/0700; stored name hashed; compute sha256.

	5. Use canonical values only (email/logs)

	6. Escape at sinks only (per map in [Central Registries (Internal Only) (§6)](#sec-central-registries))

<a id="sec-html-fields"></a>
9. SPECIAL CASE: HTML-BEARING FIELDS
	- textarea_html and template fragments (before_html / after_html)
	- textarea_html: size bound via validation.textarea_html_max_bytes (default 32768 bytes)
	- Sanitize with wp_kses_post; sanitized result is canonical; escape per sink.
	- textarea_html: post-sanitize bound – after wp_kses_post, re-check canonical size; if > max, fail with EFORMS_ERR_HTML_TOO_LARGE (no auto-truncate).

<a id="sec-cross-field-rules"></a>
10. CROSS-FIELD RULES (BOUNDED SET)
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
11. BUILT-IN FIELD TYPES (DEFAULTS; US-FOCUSED)
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
	- textarea_html: see [Special Case: HTML-Bearing Fields (§9)](#sec-html-fields); mirror maxlength/minlength when provided.
	- email: type="email", inputmode="email", spellcheck="false", autocapitalize="off"; mirror maxlength/minlength.
	- url: wp_http_validate_url + allowed schemes (http, https). type="url", spellcheck="false", autocapitalize="off".
	- tel_us: NANP; digits-only canonical 10 digits; optional +1 stripped; no extensions. type="tel", inputmode="tel"; mirror maxlength.
	- tel (generic): freeform; trimmed.
	- number / range: native input types; inputmode="decimal"; mirror min/max/step exactly as validated server-side.
	- select / radio: store option key
	- checkbox: single -> bool; group -> array of keys
	- zip_us: type="text", inputmode="numeric", pattern="\\d{5}" (hint only); always set maxlength=5; server enforces ^\d{5}$.
	- zip (generic): freeform
	- file: single upload. See [Appendices → Accept Token Map (§26.2)](#sec-accept-token-map) for the canonical MIME/extension mapping and default token policy.
	- files: multiple upload with max_files; reuse the same token definitions from [Appendices → Accept Token Map (§26.2)](#sec-accept-token-map); email attachment policy unchanged ([Email Delivery (§14)](#sec-email)).
	- date: mirror min/max and step when provided.
	- For each field, the HTML attributes emitted (inputmode, pattern, multiple, accept, etc.) must match attr_mirror derived from the resolved descriptor.
	- Resolved descriptor cache per request:
	- Include name_tpl and id_prefix to avoid recomputing; reuse in Renderer + Validator.

<a id="sec-accessibility"></a>
12. ACCESSIBILITY (A11Y)
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
13. SUCCESS BEHAVIOR (PRG)
	- inline: PRG (303) to same URL with `eforms_success={form_id}`. Renderer shows success only in the first instance in source order when multiple same-ID instances exist; suppress in subsequent instances.
	- redirect: `wp_safe_redirect(redirect_url, 303)`; no flag on destination. Cookie-mode deployments SHOULD prefer `success.mode="redirect"` pointing at a non-cached endpoint.
	- Fallback UX: when a redirect target is impossible (e.g., static cached page without a non-cached handoff), continue to use inline success on cached pages as the graceful fallback.
	- PRG status: fixed at 303.
	- Caching: do not disable page caching globally. Only vary/bypass for (a) the short-lived success cookie `eforms_s_{form_id}` and (b) requests containing `eforms_*` query args.
	- Success responses MUST send: `Cache-Control: private, no-store, max-age=0` and SHOULD include `Vary: Cookie` scoped to `eforms_s_{form_id}`.
	- Any request containing `eforms_*` query args MUST send: `Cache-Control: private, no-store, max-age=0`.
	- Namespace internal query args with `eforms_*`.
	- `success.message` is treated as plain text and escaped.
	- Anti-spoofing (inline mode only):
	1. On successful POST, create a one-time success ticket `${uploads.dir}/eforms-private/success/{form_id}/{h2}/{submission_id}.json` (short TTL, e.g., 5 minutes, with `{h2}` derived from the `submission_id` per [Security → Shared Lifecycle and Storage Contract (§7.1.1)](#sec-shared-lifecycle)’s shared sharding and permission guidance) containing `{ form_id, submission_id, issued_at }`. `submission_id` matches the ledger naming scheme (e.g., `eid__slot2` when slots are enabled) so the ticket filenames remain colon-free and Windows-compatible. Set `eforms_s_{form_id}={submission_id}` (`SameSite=Lax`, HttpOnly=false, `Secure` on HTTPS, `Path`=current request path, `Max-Age≈300`).
	2. Redirect with `?eforms_success={form_id}`.
	3. Cached page loads a lightweight verifier that calls `/eforms/success-verify?f={form_id}&s={submission_id}` (`Cache-Control: no-store`). Render the success banner only when both the query flag and verifier response succeed. A successful verifier response MUST immediately invalidate the ticket so any subsequent verify call for the same `{form_id, submission_id}` pair returns false. Then clear the cookie and strip the query parameter. This prevents replaying old cookie/query combinations on cached pages.
	- Success UX without cookies (NCID flow): When the submission proceeded under an NCID (no acceptable cookie), implementations MUST use `success.mode="redirect"` to a non-cached endpoint. Append `&eforms_submission={submission_id}` to the 303 redirect. The `/eforms/success-verify` endpoint MUST accept the `submission_id` (`s`) from either the `eforms_s_{form_id}` cookie or the `eforms_submission` query parameter. Inline success on a cached page MUST NOT be used in this case.
	- Inline success MUST NOT rely solely on a bare `eforms_s_{form_id}=1` cookie; always pair it with the ticket verifier to prevent spoofing. Logs and downstream consumers MUST treat `submission_id` values as colon-free strings and rely on the separate `slot` metadata when disambiguating multi-instance submissions.

<a id="sec-email"></a>
14. EMAIL DELIVERY
	- DMARC alignment: From: no-reply@{site_domain}
	- From precedence: if email.from_address is a valid same-domain address, use it; otherwise default to no-reply@{site_domain}. Always keep From: on site domain.
	- email.envelope_sender optional (same-domain recommended) → PHPMailer->Sender
	- From domain: parse_url(home_url()).host (lowercase; strip www)
	- default content type: text/plain; HTML emails only if email.html=true
	- subjects/headers: sanitize CR/LF; collapse control chars; truncate Subject/From Name to ≤255 bytes (UTF-8 safe) before assembly. Never accept raw user header input.
	- Reject arrays where a scalar is expected in headers/subject fields.
	- Reply-To from a validated email field (email.reply_to_field).
	- deliverability: recommend SMTP with SPF/DKIM/DMARC
	- template tokens: {{field.key}}, {{submitted_at}}, {{ip}}, {{form_id}}, {{submission_id}}, {{slot}} (emitted only for cookie-mode submissions where slots are configured and successfully bound)
	- If an upload field key appears in include_fields, render value as comma-separated list of original_name_safe in the email body (attachments separate).
	- attachments: only for fields with email_attach=true; enforce uploads.max_email_bytes and email.upload_max_attachments; summarize overflow in body before send.
	- Enforce size/count before PHPMailer->send() to avoid SMTP 552.
	- Staging safety: email.disable_send; or email.staging_redirect_to (string|array) to override all recipients; add X-EForms-Env: staging; prefix subject [STAGING]. CI should assert production configs do not enable these.
	- optional DKIM via PHPMailer when email.dkim.* set; if incomplete/invalid, proceed without DKIM and log a warning.
	- PHPMailer debug enabled only when email.debug.enable=true and logging.level>=1; capture via Debugoutput; strip CR/LF; redact secrets; redact full emails when logging.pii=false; truncate to email.debug.max_bytes.
	- SMTP Timeout from email.smtp.timeout_seconds; transient failures retry up to email.smtp.max_retries with email.smtp.retry_backoff_seconds backoff.
	- Hooks: register wp_mail_failed (log reason) and phpmailer_init (apply DKIM/debug).
	- email.policy:
	- strict: RFC-compliant parsing; trim; single @; reject otherwise.
	- autocorrect: do strict parsing, then trim/collapse spaces, lowercase domain, normalize common domain typos in display only (.con→.com, .c0m→.com); canonical stays strict; log [corrected] note when applied.
	- display_format_tel tokens: "xxx-xxx-xxxx" (default), "(xxx) xxx-xxxx", "xxx.xxx.xxxx" (affects email display only).

<a id="sec-logging"></a>
15. LOGGING
	- Mode selects destination; level selects severities; pii/headers select detail; rotation keeps files sane.
	- logging.mode: "jsonl" | "minimal" | "off" (authoritative)
	- jsonl — structured files in ${uploads.dir} with rotation/retention.
	- minimal — compact line per event via error_log(); rotation governed by server.
	- off — no logging (except optional Fail2ban emission).
	- Severity mapping: error (fatal pipeline failures), warning (rejections, validation, challenge timeouts), info (successful sends, token rotations, throttling state changes).
	- logging.level: 0 errors; 1 +warnings; 2 +info (default 0)
	- logging.headers (bool; default false) — if true, log normalized UA/Origin (scheme+host only).
	- logging.pii (bool; default false) — allows full emails/IPs in JSONL only; minimal mode still masks unless explicitly overridden.
	- Rotation/retention for JSONL: dirs 0700, files 0600, rotate when file_max_size exceeded, prune > retention_days. flock() used; note NFS caveats.
	- What to log (all modes, subject to pii/headers):
	- Timestamp (UTC ISO-8601), severity, code, form_id, submission_id, slot? (when provided), request URI (path + only `eforms_*` query), privacy-processed IP, spam signals summary (honeypot, origin_state, soft_reasons, throttle_state), SMTP failure reason when applicable.
	- Token evaluation mode (meta.mode) when the submission gate runs, to differentiate hidden-token vs cookie flows.
	- Cookie consultation boolean (meta.cookie_consulted): true iff the cookie path was evaluated (cookie-mode); false in hidden-mode. Lets tests assert that cookies were never read when a hidden token was posted.

	- Optional on failure: canonical field names + values only for fields causing rejection when logging.on_failure_canonical=true.
	- Throttle & challenge outcomes at level >=1 (redact provider tokens).
	- At level=2, include a compact descriptor fingerprint for this request: desc_sha1 = sha1(json_encode(resolved descriptors)). Optionally include a compact spam bitset alongside the human list.
	- Minimal mode line format
	- eforms severity=<error|warning|info> code=<EFORMS_*|PHPMailer> form=<form_id> subm=<submission_id> ip=<masked|hash|full|none> uri="<path?eforms_*...>" msg="<short>" meta=<compact JSON>
	- Fail2ban (optional; independent of logging.mode; controlled by logging.fail2ban.*)
	- Emit single-line: eforms[f2b] ts=<unix> code=<EFORMS_ERR_*> ip=<resolved_client_ip> form=<form_id>
	- Uses resolved client IP per [Privacy and IP Handling (§16)](#sec-privacy) (ignores privacy.ip_mode). Rotation/retention similar to JSONL when target=file.
	- Implementation notes:
	- Initialize JSONL/minimal logger only when logging.mode!='off'. Fail2ban emission is independent.

<a id="sec-privacy"></a>
16. PRIVACY AND IP HANDLING
	- privacy.ip_mode = none | masked | hash | full (default masked)
	- masked: IPv4 last octet(s) redacted; IPv6 last 80 bits zeroed (compressed)
	- hash: sha256(ip + optional salt); store hash only
	- full: store/display IP as-is
	- logs and emails honor this setting for IP presentation
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
17. CONFIGURATION: DOMAINS, CONSTRAINTS, AND DEFAULTS
	- Authority: Default *values* live in code as `Config::DEFAULTS` (see `src/Config.php`). This spec no longer duplicates every literal; the code array is the single source of truth for defaults.
	- Normative constraints (this spec): types, enums, required/forbidden combinations, range clamps, migration/fallback behavior, and precedence rules remain authoritative here. Implementations MUST enforce these even when defaults evolve.
	- Lazy bootstrap (unchanged): `Config::bootstrap()` is invoked on the first use from `Config::get()`, `FormRenderer::render()`, `SubmitHandler::handle()`, `Security::token_validate()`, `Emailer::send()`, or the prime/success endpoints. Within a request it runs at most once, applies the `eforms_config` filter, clamps values, then freezes the snapshot. `uninstall.php` calls it eagerly to honor purge flags; standalone tooling MAY force bootstrap.
	- Migration behavior: unknown keys MUST be rejected; missing keys fall back to defaults before clamping; invalid enums/ranges/booleans MUST trigger validation errors rather than coercion; POST handlers MUST continue to enforce constraints after bootstrap.

	`Config::DEFAULTS` also powers uninstall/CLI flows; it exposes a stable public symbol for ops tooling.

	1. Domains (key groups)
	| Domain	| Key prefix			 | Purpose (summary)												|
	|-----------|----------------------|------------------------------------------------------------------|
	| Security	| `security.*`		 | Token/cookie modes, TTLs, origin challenge policy, POST limits	 |
	| Spam		| `spam.*`			 | Soft-fail thresholds and spam heuristics						 |
	| Challenge | `challenge.*`		| CAPTCHA/Turnstile providers and HTTP timeouts					|
	| Email	 | `email.*`			| Transport policy, SMTP tuning, DKIM, debug hooks				 |
	| Logging	 | `logging.*`			| Mode/level/PII policy, retention, fail2ban emission				|
	| Privacy	 | `privacy.*`			| IP handling, salts, proxy trust									|
	| Throttle	| `throttle.*`		 | Per-IP rate limits, cooldowns, hard-fail multipliers			 |
	| Validation| `validation.*`		 | Form shape guardrails (field/option caps, HTML size)			 |
	| Uploads	 | `uploads.*`			| Allow-lists, per-file/per-request caps, retention policy		 |
	| Assets	| `assets.*`			 | CSS enqueue controls											 |
	| Install	 | `install.*`			| Minimum platform versions, uninstall purge flags				 |

	2. Normative constraints (summary)
	| Domain	| Key									 | Type	| Constraints (normative)																						|
	|-----------|---------------------------------------|-------|----------------------------------------------------------------------------------------------------------------|
	| Security	| `security.origin_mode`				| enum	| {`off`,`soft`,`hard`} — governs whether missing Origin headers are tolerated.									|
	| Security	| `security.honeypot_response`			| enum	| {`stealth_success`,`hard_fail`} — determines the observable response when the honeypot triggers.				 |
	| Security	| `security.cookie_missing_policy`		| enum	| {`off`,`soft`,`hard`,`challenge`} — invalid inputs MUST be rejected; challenge mode may force [Security → Submission Protection for Public Forms (§7.1)](#sec-submission-protection) flow.	 |
	| Security	| `security.min_fill_seconds`			 | int	 | clamp 0–60; values <0 become 0; >60 become 60.																|
	| Security	| `security.token_ttl_seconds`			| int	 | clamp 1–86400; minted tokens MUST set `expires - issued_at` equal to this value.								 |
	| Security	| `security.max_form_age_seconds`		 | int	 | clamp 1–86400; defaults to `security.token_ttl_seconds` when omitted.											|
	| Security	| `security.success_ticket_ttl_seconds` | int	 | clamp 30–3600; governs success ticket validity for redirect mode ([Success Behavior (PRG) (§13)](#sec-success)).										 |
	| Security	| `security.cookie_mode_slots_allowed`	| list	| Normalized to unique ints 1–255; honored only when paired with `cookie_mode_slots_enabled = true`.			 |
	| Challenge | `challenge.mode`						| enum	| {`off`,`auto`,`always`} — controls when human challenges execute; invalid values MUST be rejected.			|
	| Challenge | `challenge.provider`					| enum	| {`turnstile`,`hcaptcha`,`recaptcha`} — provider-specific keys MUST be populated before enablement.			 |
	| Challenge | `challenge.http_timeout_seconds`		| int	 | clamp 1–5 seconds.																							|
	| Throttle	| `throttle.per_ip.max_per_minute`		| int	 | clamp 1–120; values beyond clamp saturate; 0 disables throttle only via `throttle.enable = false`.			|
	| Throttle	| `throttle.per_ip.cooldown_seconds`	| int	 | clamp 10–600 seconds.																							|
	| Throttle	| `throttle.per_ip.hard_multiplier`	 | float | clamp 1.5–10.0; multiplier applies to hard-fail windows when soft threshold is exceeded.						 |
	| Logging	 | `logging.mode`						| enum	| {`off`,`minimal`,`jsonl`} — determines logging sink ([Logging (§15)](#sec-logging)).													 |
	| Logging	 | `logging.level`						 | int	 | clamp 0–2; level ≥1 unlocks verbose submission diagnostics.													|
	| Logging	 | `logging.retention_days`				| int	 | clamp 1–365 days.																								 |
	| Logging	 | `logging.fail2ban.target`			 | enum	| {`error_log`,`syslog`,`file`} — `file` requires a writable path; invalid values MUST be rejected.		 |
	| Logging	 | `logging.fail2ban.retention_days`	 | int	 | clamp 1–365; defaults to `logging.retention_days` when unspecified.											|
	| Privacy	 | `privacy.ip_mode`					 | enum	| {`none`,`masked`,`hash`,`full`} — see [Logging (§15)](#sec-logging) for hashing/masking details.										 |
	| Validation| `validation.max_fields_per_form`		| int	 | clamp 1–1000; protects renderer/validator recursion.															|
	| Validation| `validation.max_options_per_group`	| int	 | clamp 1–1000; denies pathological option fan-out.																|
	| Validation| `validation.max_items_per_multivalue` | int	 | clamp 1–1000; governs checkbox/select count.																	 |
	| Validation| `validation.textarea_html_max_bytes`	| int	 | clamp 1–1_000_000 bytes; applies before sanitizer; see [Built-in Field Types (Defaults; US-focused) (§11)](#sec-field-types) for mirroring to DOM hints.						 |

	Additional notes:
		- `security.js_hard_mode = true` enforces a hard failure for non-JS submissions ([Security → Submission Protection for Public Forms (§7.1)](#sec-submission-protection)).
		- `security.max_post_bytes` MUST honor PHP INI limits (post_max_size, upload_max_filesize) and never exceed server caps.
		- Range/enumeration clamps are mirrored to HTML attributes for UX hints only; server enforcement is authoritative.
		- Spam heuristics (`spam.*`) and upload caps (`uploads.*`) are documented in [Validation & Sanitization Pipeline (Deterministic) (§8)](#sec-validation-pipeline) and [Uploads (Implementation Details) (§18)](#sec-uploads); they inherit defaults from code but keep their behavioral rules in those sections.

	3. Defaults
		- The canonical defaults array resides at `src/Config.php` as `Config::DEFAULTS`. `Config::defaults()` injects runtime-derived values such as `uploads.dir` (resolved from `wp_upload_dir()`); these dynamic entries remain code-driven.
		- Changing a default in code changes runtime behavior but MUST NOT weaken any constraint defined in this spec.

	4. CI guardrails
		- Repository CI asserts that every key documented above exists in `Config::DEFAULTS` and that the clamp/enum metadata in code matches the normative ranges listed here. This keeps the spec and implementation from drifting.

<a id="sec-uploads"></a>
18. UPLOADS (IMPLEMENTATION DETAILS)
	- Intersection: field accept[] ∩ global allow-list must be non-empty → else EFORMS_ERR_ACCEPT_EMPTY
	- Stored filename: {Ymd}/{original_slug}-{sha16}-{seq}.{ext}; files 0600, dirs 0700; full SHA-256 recorded in logs.
	- Path collision: increment seq
	- Path length cap: enforce uploads.max_relative_path_chars; when exceeded, shorten original_slug deterministically to fit.
	- Email attachments use original_name_safe (RFC 5987 as needed); de-dup per email scope: name.ext, name (2).ext, ...
	- Delete uploads after successful send unless retention applies; if email send fails after files were stored, cleanup per retention policy. On final send failure, delete unless uploads.retention_seconds>0 (then GC per retention).
	- GC: opportunistic on GET and best-effort on POST shutdown only. No WP-Cron.
	- has_uploads flag computed during preflight; guard Uploads init on that.
	- fileinfo hard requirement: if ext/fileinfo unavailable, define EFORMS_FINFO_UNAVAILABLE at bootstrap and deterministically fail any upload attempt.
	- MIME validation requires agreement of finfo + extension + accept-token; finfo=false/unknown → reject with EFORMS_ERR_UPLOAD_TYPE.

<a id="sec-request-lifecycle"></a>
19. REQUEST LIFECYCLE
	<a id="sec-request-lifecycle-get"></a>1. GET
	- Shortcode `[eform id="slug" cacheable="true|false"]` (`cacheable` defaults to `false`).
	- Template tag `eform_render('slug', ['cacheable' => true|false])` (`cacheable` defaults to `false`).
	- `cacheable=false` forces hidden-mode; `cacheable=true` uses cookie-mode.
	- FormRenderer loads the template and injects the appropriate hidden-token or cookie metadata per [Security → Submission Protection for Public Forms (§7.1)](#sec-submission-protection).
	- Registers/enqueues CSS/JS only when rendering
	- Always set method="post". If any upload field present, add enctype="multipart/form-data".
	- Opportunistic GC may run (no WP-Cron).
	- Max-input-vars heuristic: log advisory and (when WP_DEBUG) emit an HTML comment near the form.
	- CDN/cache notes: bypass caching on non-cacheable token pages; /eforms/prime is no-store; do not strip Set-Cookie on 204.
	- Initialize Logging only when logging.mode != "off".
	- Initialize Uploads only when uploads.enable=true and template declares file/files (detected at preflight).
	- html5.client_validation=true → omit novalidate; server validator still runs on POST.
	- Preflight resolves and freezes per-request resolved descriptors; reuse across Renderer and Validator (no re-merge on POST).

	<a id="sec-request-lifecycle-post"></a>2. POST
	- SubmitHandler orchestrates Security gate -> Normalize -> Validate -> Coerce
	- Mode, hidden-field reuse, and rerender behavior follow the canonical contract in [Security → Submission Protection for Public Forms (§7.1)](#sec-submission-protection); lifecycle logic never swaps modes mid-flow.
	- Early enforce RuntimeCap using CONTENT_LENGTH when present; else rely on PHP INI limits and post-facto caps.
        - Error rerenders and duplicate handling follow [Security → Ledger reservation contract (§7.1.1)](#sec-ledger-contract). SubmitHandler performs the exclusive-create reservation immediately before side effects, treats `EEXIST` or other IO failures as duplicates (logging `EFORMS_LEDGER_IO`), and sequences normalization, validation, email, and logging around that contract with the colon-free `submission_id` supplied by Security.
	- Hidden-mode NCID fallback: when a hidden record is missing or expired and continuation is permitted, expect the NCID metadata documented in [Security → Hidden-mode contract (§7.1.2)](#sec-hidden-mode) and [Security → NCIDs, Slots, and Validation Output (§7.1.4)](#sec-ncid); rerenders retain the persisted `{instance_id, timestamp}` while dedupe/logging use the NCID `submission_id`.
	- On success: move stored uploads; send email; log; PRG/redirect; cleanup per retention.
	- Best-effort GC on shutdown; no persistence of validation errors/canonical values beyond request.
	- throttle.enable=true and key available → run throttle; over → +1 soft and add Retry-After; hard → HARD FAIL (skip side effects).
	- Challenge hook: if required (always/auto or cookie policy), verify; success removes the relevant labels from `soft_reasons` (hard failures are unaffected).

<a id="sec-error-handling"></a>
20. ERROR HANDLING
	- Errors stored by field_key; global errors under _global
	- Renderer prints global summary + per-field messages
	- Upload user-facing messages:
	- "This file exceeds the size limit."
	- "Too many files."
	- "This file type isn't allowed."
	- "File upload failed. Please try again."
	- Re-render after errors passes the mode-specific security metadata defined in [Security → Submission Protection for Public Forms (§7.1)](#sec-submission-protection) back to Renderer (hidden: token/instance/timestamp; cookie: `{eid, slot}`).
	- Emit stable error codes (e.g., EFORMS_ERR_TOKEN, EFORMS_ERR_HONEYPOT, EFORMS_ERR_TYPE, EFORMS_ERR_ACCEPT_EMPTY, EFORMS_ERR_ROW_GROUP_UNBALANCED, EFORMS_ERR_SCHEMA_UNKNOWN_KEY, EFORMS_ERR_SCHEMA_ENUM, EFORMS_ERR_SCHEMA_REQUIRED, EFORMS_ERR_SCHEMA_TYPE, EFORMS_ERR_SCHEMA_OBJECT, EFORMS_ERR_UPLOAD_TYPE, EFORMS_ERR_HTML_TOO_LARGE).
	- Large form advisory via logs and optional HTML comment (WP_DEBUG only).
	- "This content is too long." maps to EFORMS_ERR_HTML_TOO_LARGE.
	- "This form was already submitted or has expired - please reload the page." maps to EFORMS_ERR_TOKEN.

<a id="sec-compatibility"></a>
21. COMPATIBILITY AND UPDATES
	- Changing type defaults or rules updates behavior globally via registry
	- Templates remain portable (no callbacks)
	- Minimum versions: PHP >= 8.0; WordPress >= 5.8 (admin notice + deactivate if unmet)
	- Terminology: use allow-list/deny-list consistently.

<a id="sec-assets"></a>
22. ASSETS (CSS & JS)
	- Enqueued only when a form is rendered; version strings via filemtime().
	- forms.js provides js_ok="1" on DOM Ready, submit-lock/disabled state, error-summary focus, and first-invalid focus. Not required unless security.js_hard_mode=true.
	- assets.css_disable=true lets themes opt out
	- On submit failure, focus the first control with an error
	- Focus styling (a11y): do not remove outlines unless visible replacement is provided. For inside-the-box focus: outline: 1px solid #b8b8b8 !important; outline-offset: -1px;
	- html5.client_validation=true: do not suppress native validation UI; skip pre-submit summary focus to avoid double-focus; after server re-render with errors, still focus first invalid control.
	- Only enqueue provider script when the challenge is rendered:
	- Turnstile: https://challenges.cloudflare.com/turnstile/v0/api.js (defer, crossorigin=anonymous)
	- hCaptcha: https://hcaptcha.com/1/api.js (defer)
	- reCAPTCHA v2: https://www.google.com/recaptcha/api.js (defer)
	- Do not load challenge script on initial GET unless required by [Security → Submission Protection for Public Forms (§7.1)](#sec-submission-protection).
	- Secrets hygiene: Render only site_key to HTML. Never expose secret_key or verify tokens in markup/JS. Verify server-side; redact tokens in logs.
	- Keep novalidate logic unchanged.

<a id="sec-implementation-notes"></a>
23. NOTES FOR IMPLEMENTATION
	- instance_id (hidden mode): mint once per token using a cryptographically secure RNG (16–24 bytes), encode as base64url without padding (regex `^[A-Za-z0-9_-]{22,32}$`). Persist in the hidden-token record; do not regenerate on rerender.
	- timestamp (hidden mode): DO NOT persist a separate field. The timestamp rendered into HTML MUST be the record’s `issued_at`. On rerender, reload the record and reuse the same value.
	- Hidden record lifecycle: NEVER rewrite `issued_at`/`expires`/`instance_id` on rerender; only mint a new record on rotation/expiry. If the hidden record is missing at POST, follow `security.submission_token.required` for hard/soft behavior.
	- Use esc_textarea for <textarea> output
	- Enqueue assets only when a form exists on the page
	- Logs dir perms 0700; log files 0600
	- Sanitize class tokens [A-Za-z0-9_-]{1,32} per token; cap total length
	-> Algorithm: split on whitespace; keep tokens matching [A-Za-z0-9_-]{1,32}; truncate longer tokens to 32; de-duplicate preserving first occurrence; join with a single space; cap final attribute at 128 chars; omit class when empty.
	- Option keys: [a-z0-9_-]{1,64}; unique within field
	- Filename policy: see 26.3
	- TemplateValidator sketch: pure-PHP walkers with per-level allowed-key maps; normalize scalars/arrays; emit EFORMS_ERR_SCHEMA_* with path (e.g., fields[3].type)
	- Caching: in-request static memoization only; no cross-request caching.
	- No WordPress nonce usage. Submission token TTL is controlled via security.token_ttl_seconds.
	- Max_input_vars heuristic is conservative; it does not count $_FILES.
	- Keep deny rules (index.html + .htaccess/web.config) in uploads/logs dirs. Perms 0700/0600.
	- Renderer & escaping: canonical values remain unescaped until sink time; do not escape twice or mix escaped/canonical.
	- Helpers:
	- Helpers::nfc(string $v): string — normalize to Unicode NFC; no-op without intl.
	- Helpers::cap_id(string $id, int $max=128): string — length cap with middle truncation + stable 8-char base32 suffix.
	- Helpers::bytes_from_ini(?string $v): int — parses K/M/G; "0"/null/"" -> PHP_INT_MAX; clamps non-negative.
	- Helpers::h2(string $id): string — derive the shared `[0-9a-f]{2}` shard (see `{h2}` directories in [Security → Shared Lifecycle and Storage Contract (§7.1.1)](#sec-shared-lifecycle).
	- Helpers::throttle_key(Request $r): string — derive the throttle key per [Cross-Field Rules (BOUNDED SET) (§10)](#sec-cross-field-rules) honoring `privacy.ip_mode`.
	- Helpers::ncid(string $form_id, string $client_key, int $window_idx, array $canon_post): string — returns `"nc-" . hash('sha256', ...)` with the concatenation defined in [Security → NCIDs, Slots, and Validation Output (§7.1.4)](#sec-ncid).
	- Renderer consolidation:
	- Shared text-control helper centralizes attribute assembly; <input> and <textarea> emitters stay small and focused.
	- Keep group controls (fieldset/legend), selects, and file(s) as dedicated renderers for a11y semantics.
	- Cookie-policy precedence eliminates ambiguity and keeps UX predictable on cookie-blocked browsers without weakening hidden-token path.
	- When cookie_missing_policy='challenge' and verification succeeds, do not rotate the cookie again on the same response (avoid breaking back-button resubmits).
	- Minimal logging via error_log() is a good ops fallback; JSONL is primary structured option.
	- Fail2ban emission isolates raw IP use to a single, explicit channel designed for enforcement.
	- Fail2ban rotation uses the same timestamped rename scheme as JSONL.
	- If logging.fail2ban.file is relative, resolve under uploads.dir (e.g., ${uploads.dir}/f2b/eforms-f2b.log).
	- Uninstall: when install.uninstall.purge_logs=true, also delete Fail2ban file and rotated siblings.
	- Header name compare is case-insensitive. Cap header length at ~1-2 KB before parsing to avoid pathological inputs.
	- Recommend logging.mode="minimal" in setup docs to capture critical failures; provide guidance for switching to "off" once stable.
	- Element ID length cap: cap generated IDs (e.g., `"{form_id}-{field_key}-{instance_id}"` or `"{form_id}-{field_key}-s{slot}"`) at 128 chars via Helpers::cap_id().
	- Permissions fallback: create dirs 0700 (files 0600); on failure, fall back once to 0750/0640 and emit a single warning (when logging enabled).
	- Cookie mode does not require JS.
	- CI scaffolding:
	- Descriptor resolution test: iterate Spec::typeDescriptors(), resolve all handler IDs; assert callable.
	- Schema parity test: generate JSON Schema from TEMPLATE_SPEC (or vice versa) and diff; fail on enum/required/shape drift.
	- Determinism tests: fixed template + inputs → assert identical error ordering, canonical values, rendered attribute set.
	- TTL alignment test: assert `minted_record.expires - minted_record.issued_at == security.token_ttl_seconds` and success tickets honor `security.success_ticket_ttl_seconds`.
	- WP-CLI smoke tests:
	- Command to POST without Origin to confirm hard/missing policy behavior.
	- Command to POST oversized payload to verify RuntimeCap handling.

<a id="sec-email-templates"></a>
24. EMAIL TEMPLATES (REGISTRY)
	- Files: /templates/email/{name}.txt.php and {name}.html.php
	- JSON "email_template": "foo" selects those files ("foo.html.php" when email.html=true); missing/unknown names raise an error
	- Template inputs:
	- form_id, submission_id, submitted_at (UTC ISO-8601)
	- fields (canonical values only, keyed by field key)
	- meta limited to { submitted_at, ip, form_id, submission_id, slot? }
	- uploads summary (attachments per Emailer policy)
	- Token expansion: {{field.key}}, {{submitted_at}}, {{ip}}, {{form_id}}
	- Escaping:
	- text emails: plain text; CR/LF normalized
	- HTML emails: escape per context; no raw user HTML injected
	- Security hardening: template PHP files include ABSPATH guard (defined('ABSPATH') || exit;).

<a id="sec-templates-to-include"></a>
25. TEMPLATES TO INCLUDE
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
26. APPENDICES
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
	- EFORMS_ERR_UPLOAD_TYPE - "This file type isn't allowed."
	- EFORMS_ERR_HTML_TOO_LARGE - "This content is too large."
	- EFORMS_ERR_THROTTLED - "Please wait a moment and try again."
	- EFORMS_ERR_CHALLENGE_FAILED - "Please complete the verification and submit again."
	- EFORMS_CHALLENGE_UNCONFIGURED – "Verification unavailable; please try again."
	- EFORMS_RESERVE - "Reservation outcome (info)."
	- EFORMS_LEDGER_IO - "Ledger I/O problem."
	- EFORMS_FAIL2BAN_IO - "Fail2ban file I/O problem."
	- EFORMS_FINFO_UNAVAILABLE - "File uploads are unsupported on this server."

	2. <a id="sec-accept-token-map"></a>Accept Token -> MIME/Extension Map (canonical, conservative)
	- image -> image/jpeg, image/png, image/gif, image/webp (SVG excluded)
	- pdf -> application/pdf
	- Explicit exclusions by default: image/svg+xml, image/heic, image/heif, image/tiff
	- Default tokens: {image, pdf}. Additional tokens require explicit review and MUST update this table.
	- Applies to both `file` and `files` field types; multi-file inputs reuse these lists, and email attachment policy remains governed by [Email Delivery (§14)](#sec-email).

	3. Filename Policy (Display vs Storage)
	- Start with client name; strip paths; NFC normalize
	- sanitize_file_name(); remove control chars; collapse whitespace/dots
	- enforce single dot before extension; lowercase extension
	- block reserved Windows names (CON, PRN, AUX, NUL, COM1–COM9, LPT1–LPT9)
	- truncate to uploads.original_maxlen; fallback "file.{ext}" if empty
	- transliterate to ASCII when uploads.transliterate=true; else keep UTF-8 and use RFC 5987 filename*
	- de-dupe per email scope: "name.ext", "name (2).ext", ...
	- strip CR/LF from all filename strings before mailer
	- Storage name: {Ymd}/{original_slug}-{sha16}-{seq}.{ext}; never expose full paths

	4. Schema Source of Truth
	- PHP TEMPLATE_SPEC is authoritative at runtime
	- JSON Schema is documentation/CI lint only; enforce parity in CI

<a id="sec-past-decisions"></a>
27. PAST DECISION NOTES
	- Use Origin as the single header check because it's the modern CSRF boundary and far less likely to be stripped than Referer.
	- Hidden tokens defend idempotency/duplicate-submits; CSRF defense derives from Origin.
	- Nonces add complexity/expiry issues and don’t play well with caching.
	- Double-submit cookie patterns rely on JS; not required here.
	- Old/locked-down clients may omit Origin on same-origin POST; defaults (soft + missing=false) tolerate that. origin_mode=hard + origin_missing_hard=true can block those users—document and test before enabling.
	- Standardize on wp_kses_post() for both textarea_html and before_html/after_html to simplify maintenance and leverage WordPress’s maintained allow-list/security updates given internal-only authoring, accepting richer markup and potential sanitizer changes across WP releases, with guardrails of retaining the post-sanitize byte cap for textarea_html and adding a small snapshot test to catch behavior shifts.
	- No PSR-4 loading: The plugin does not use PSR-4 autoloading. We rely on WordPress-style includes to reduce complexity and keep the bootstrap path explicit.
	- Static configuration: Configuration is provided through a single static snapshot (Config::bootstrap()). We chose this model instead of dependency injection to keep coupling low, ensure immutability per request, and stay aligned with WordPress conventions.
	- Additional templates can be shipped outside of spec. And template's content can differ from the spec.
	- Adopt "mode-authoritative" tokens with no cross-mode fallback. POST cannot change modes and mode is never inferred from POST. This keeps cookie policies (including hard/challenge) enforceable and makes behavior deterministic. Token mode isn't inferred from the template (TemplateContext) to keep templates mode-agnostic.
	- No FormManager class; responsibilities split between FormRenderer and SubmitHandler
	- The NCID path is what keeps cookie-mode submissions functional when the browser refuses the anti-duplication cookie yet the site chooses a non-hard policy. Without NCIDs there would be no deterministic identifier to reserve in ${uploads.dir}/…/ledger, so duplicate detection, soft-signal bookkeeping, and downstream success tickets would all break for the very scenarios the spec says must keep working.
	- Slot metadata is what lets multiple cached instances of the same form share a cookie while still emitting unique field IDs, prime pixels, and submission IDs (eid__slot{n}) so dedupe, logging, email templates, and success tickets stay unambiguous.
