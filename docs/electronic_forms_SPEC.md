electronic_forms - Spec
================================================================

1. OBJECTIVE
	- Build a dependency-free, lightweight plugin that renders and processes multiple forms from JSON templates with strict DRY principles and a deterministic pipeline.
	- Internal use on 4-5 sites, ~40 submissions/day across forms, USA only. Not publicly marketed/distributed.
	- Targets publicly accessible contact forms without authenticated sessions. Cache-friendly and session-agnostic: WordPress nonces are never used.
	- No admin UI.
	- Focus on simplicity and efficiency; avoid overengineering. Easy to maintain and performant for intended use.
	- No database writes; file-backed one-time token ledger for duplicate‑submit prevention (no Redis/queues).
	- Clear boundaries: render vs. validate vs. send vs. log vs. upload.
	- Deterministic pipeline and schema parity: big win for testability.
	- Lazy loading of registries/features and config snapshot: keeps coupling down.

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
			- security.origin_mode="soft"; security.js_hard_mode=false
			- Honeypot always enabled; security.honeypot_response="stealth_success"
			- throttle.enable=false
			- challenge.mode="off"
			- logging.mode="minimal"
			- logging.fail2ban.enable=false
			- security.cookie_missing_policy="soft"
		- Opt-ins (enable only if needed):
			- Throttling, adaptive/always challenge
			- Fail2ban emission
			- Rejected-submission logging → set logging.mode="jsonl" (or "minimal") and logging.level>=1
			- Header logging, PII logging, SMTP debug

3. ARCHITECTURE AND FILE LAYOUT
	- /electronic_forms/
		- eforms.php	// bootstrap + autoloader + shortcode/template tag
		- uninstall.php	// optional purge of uploads/logs (reads flags from Config; see 17)
		- uninstall.php requires __DIR__.'/src/Config.php' and calls Config::bootstrap() so it can read purge flags without relying on WP hooks. uninstall hardening: uninstall.php must start with defined('WP_UNINSTALL_PLUGIN') || exit; and guard WP calls: if (!function_exists('wp_upload_dir')) { require_once ABSPATH.'wp-admin/includes/file.php'; }. If wp_upload_dir() still isn’t available, abort uninstall gracefully to avoid fatals.
	- /src/
		- Config.php
		- FormManager.php	// orchestrates GET/POST, PRG, CSS/JS enqueue
		- Renderer.php		// pure HTML; escape only at sinks
		- Validator.php		// normalize -> validate -> coerce (deterministic)
		- TemplateValidator.php	// strict JSON structural preflight (unknown keys/enums/combos; accept[] intersection)
		- Emailer.php		// build & send; safe headers; text/plain by default
		- Security.php		// token, honeypot, min-fill-time, max-form-age
		- Logging.php		// JSONL logger; rotation; masking
		- Helpers.php		// tiny esc/url/id-name/fs utilities
		- Uploads.php		// normalize/validate/move uploads; enforce caps/allow-list; GC/retention; name/perms policy
		- /schema/
			- template.schema.json	// design-time only (editor/CI lint); kept in sync with PHP spec
	- /templates/
		- default.json
		- contact.json		// kebab-case filenames only
		- HARDENING: ship index.html and server deny rules (.htaccess, web.config) in this directory; enforce filename allow-list and prevent traversal outside /templates/.
	- /assets/
		- forms.css		// namespaced styles
		- forms.js // provides: JS marker (js_ok), error-summary/first-invalid focus, submit lock, spinner

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

5. TEMPLATE MODEL
	1. Field Generation and Namespacing
		- Template field keys may include:
			- key (slug), type, label?, placeholder?, required (bool), size (1-100; text-like only), autocomplete?, options (for radios/checkboxes/select), class?, max_length?, before_html?, after_html?
		- key (slug): required; must match ^[a-z0-9_:-]{1,64}$ (lowercase); [] prohibited to prevent PHP array collisions; reserved keys remain disallowed.
		- autocomplete: exactly one token is allowed. The literal "on"/"off" are accepted as-is. All other values must match the WHATWG tokens (e.g., name, given-name, family-name, email, tel, postal-code, street-address, address-line1, address-line2, organization, …). Invalid tokens are dropped.
		- size: 1-100; ignored for non-text types.
		- Hidden per-instance fields (renderer adds): form_id, instance_id, eforms_hp (POST name fixed; randomized id only), timestamp (used for UI/logs and as a best-effort age signal in hidden-token mode; see 7.3), js_ok; and when cacheable="false" also add <input type="hidden" name="eforms_token" value="<UUIDv4>"> (see 7.1). When cacheable="true" no hidden token is rendered (cookie-only). timestamp is set on first render of the instance and preserved across validation re-renders; when re-rendering after POST errors, reuse the posted timestamp
		- Renderer-generated attributes:
			- id = "{form_id}-{field_key}-{instance_id}"
			- name = "{form_id}[{field_key}]" or "{form_id}[{field_key}][]" for multivalue
		- Reserved field keys (templates must not use): form_id, instance_id, eforms_token, eforms_hp, timestamp, js_ok, ip, submitted_at.
		- include_fields accepts template keys and meta keys:
			- allowed meta keys: ip, submitted_at, form_id, instance_id (available for email/logs only)
		- Template fragments (before_html / after_html):
			- Sanitized via wp_kses() allow-list: div, span, p, br, strong, em, h1–h6, ul, ol, li, a.
			- Attributes: class for all listed elements; for <a> allow href and class only.
			- Allowed URL schemes for <a href> are restricted to http, https, and mailto (pass ['http','https','mailto'] as the third wp_kses() arg).
			- No inline styles. May not cross row_group boundaries.
		- For type=file/files fields, optional properties are supported: accept[], max_file_bytes, max_files (files only), and email_attach (bool).
		- Attribute emission list (summary): maxlength, min, max, step, minlength, pattern, inputmode, multiple, and accept are emitted when applicable from the template/registry traits.
		- Client-side attribute mirroring (UX hints only): the Renderer mirrors server limits as HTML attributes - max_length -> maxlength, min/max/step for numeric/date types, and min_length -> minlength (when present in a future template). These attributes never relax server rules; server validation remains authoritative.
		- Typing/editing aids: the Renderer emits inputmode, pattern (hints only), and editing helpers per field type (see 11).
		- Uploads -> HTML attributes: for the image accept token, emit an explicit accept list image/jpeg,image/png,image/gif,image/webp (do not use image/*); for pdf emit application/pdf.
		- Enter key UX: the Renderer sets enterkeyhint="send" on the last text-like input or <textarea> in DOM order. Best-effort only (browser support varies) and has no effect on validation or submission flow. The required attribute is driven only by the template’s required: true|false.
	2. Row Groups (Structured Wrappers)
		- pseudo-field: type=row_group with { mode:"start"|"end", tag:"div"|"section" (default div), class:"..." }
		- no key; no data; supports nesting
		- renderer adds a base wrapper class (e.g., "eforms-row") to each row_group element.
		- Dangling opens are auto-closed at form end to keep the DOM valid and a single _global config error EFORMS_ERR_ROW_GROUP_UNBALANCED is logged/emitted. A stray "end" with an empty stack is ignored and logged.
		- row_group pseudo-fields do not count toward validation.max_fields_per_form.
		- Row-group objects must omit key and allow only {type, mode, tag, class}; enforce additionalProperties:false.
		- Mis-balance reporting: if the row_group stack is mis-balanced at form end, emit a single _global config error (EFORMS_ERR_ROW_GROUP_UNBALANCED) (do not duplicate per-field errors).
	3. Template JSON
		- Location: /templates/
		- Filename allow-list: /^[a-z0-9-]+\.json$/
		- Design-time schema pointer (optional but recommended): use a stable web URL to the schema in your repo (e.g., "${SCHEMA_URL}/template.schema.json") or a local absolute path suitable to the environment. Avoid hard-coded /wp-content/plugins/... paths.
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
			- /src/schema/template.schema.json is CI/docs only; ensure parity with TEMPLATE_SPEC
			- If JSON is malformed or missing keys, it should fail gracefully with a clear "Form configuration error" 	message, not white-screen PHP.
			- Unknown rule values are rejected by the PHP validator.
			- Structural preflight enforces that for file/files fields, accept[] intersect global allow-list is non-empty; otherwise emit EFORMS_ERR_ACCEPT_EMPTY.
			- CI MUST validate /templates/*.json against /src/schema/template.schema.json and assert parity with the PHP TEMPLATE_SPEC to prevent drift.
                        - Enforce email.display_format_tel is one of the allowed enum values; unknown values are dropped at runtime but flagged in preflight.
        7. TemplateContext (internal)
                - TemplateValidator returns a normalized TemplateContext array consumed by Renderer, Validator, and Security.
                - Keys include: has_uploads (bool), descriptors[] (field descriptors from Spec), version, id, email, success, rules, fields (normalized copies).
                - max_input_vars_estimate: int advisory for potential PHP max_input_vars limit.
                - The descriptors array drives attribute mirroring so Renderer and Validator stay perfectly in sync.

6. CENTRAL REGISTRIES (INTERNAL ONLY)
	- Static registries (no public filters): field_types, validators, normalizers/coercers, renderers
	- Registries are instantiated on demand; upload and logging registries load only when their features are enabled (see 19.1).
	- Registries are lightweight maps; only entries referenced by the active template are consulted during render/validate; extraneous POST keys are ignored (see §8)
	- Behavior is registry-driven and parameterized by template values
	- Uploads registry settings: token->mime/ext expansions; image sanity; caps
	- Accept token map (canonical, conservative). For v1 parity, the only shipped tokens are image and pdf; do not add tokens unless explicitly required.
	- Upload registry loads on demand when a template with file/files is rendered or posted.
	- Structural registry (TEMPLATE_SPEC) defines allowed keys, required combos, enums (implements additionalProperties:false)
	- Escaping map (per sink) to be used consistently:
		- HTML text -> esc_html
		- HTML attribute -> esc_attr
		- Textarea -> esc_textarea
		- URL (render) -> esc_url
		- URL (storage/transport) -> esc_url_raw
		- JSON/logs -> wp_json_encode
	- Challenge and Throttle modules are loaded only when needed. Initialize the challenge module when (a) challenge.mode != "off", or (b) security.cookie_missing_policy == "challenge", or (c) a POST sets Security::token_validate().require_challenge === true. No classes, hooks, or assets are registered otherwise.

7. SECURITY
	1. Submission Protection for Public Forms:
		- Hybrid token scheme (shortcode-driven)
			- [eform id="contact" cacheable="true"] -> cookie-based token (static HTML).
			- [eform id="contact" cacheable="false"] -> server-side per-render token (dynamic hidden field).
			- Server decides token type when generating the form. POST handler is agnostic.
			- Token precedence: When a valid hidden eforms_token (cacheable="false") is present, ignore any cookie token. Reject only if neither a valid hidden token nor a valid cookie token is available. (Prevents false failures from stale cookies.)
		- On GET
			- if cacheable="true":
				- Include <img src="/eforms/prime?f={form_id}" aria-hidden="true" alt="" width="1" height="1">.
				- /eforms/prime responds 204 No Content and sets eforms_t_{form_id}=<UUIDv4> with HttpOnly; SameSite=Lax; Path=/; Max-Age=security.token_ttl_seconds; and Cache-Control: no-store; add Secure when is_ssl().
				- Do not set the Domain attribute by default (avoid cross-subdomain scope)
				- Form HTML is static & cacheable (no token in markup).
			- if cacheable="false":
				- Omit the pixel and inject a hidden eforms_token field (UUIDv4). Send Cache-Control: private, no-store on this page to prevent caching of the per-render token.
		- On POST /eforms/submit
			- CSRF Gate:
				- Evaluate per 7.4 (Origin-only policy).
				- In hard mode: cross -> HARD FAIL; unknown -> HARD FAIL; missing -> HARD FAIL only when security.origin_missing_hard=true
				- In soft mode: cross/unknown -> +1 soft; missing -> +1 soft only when security.origin_missing_soft=true.
			- Method/Type: Require POST. Accept only:
				- application/x-www-form-urlencoded (charset param allowed)
				- multipart/form-data (boundary param required).
				- Else -> 405 Method Not Allowed (with Allow: POST) or 415 Unsupported Media Type.
				- POST size enforcement: see §7.5 (RuntimeCap).
			- Token validation:
				- Hidden-token present (cacheable="false")
					- Validate the hidden eforms_token (UUIDv4).
					- If invalid/missing:
						- When security.submission_token.required=true -> HARD FAIL (EFORMS_ERR_TOKEN).
						- When security.submission_token.required=false -> set token_soft=1 and continue to §7.6.
				- Cookie mode (cacheable="true", no hidden token present)
					- Read eforms_t_{form_id} cookie (UUIDv4). If missing/invalid, apply security.cookie_missing_policy (overrides submission_token.required):
						- cookie_missing_policy=hard -> HARD FAIL (EFORMS_ERR_TOKEN).
						- cookie_missing_policy=soft -> set token_soft=1 and continue to §7.6.
						- cookie_missing_policy=challenge -> set token_soft=1 and mark challenge required (even if challenge.mode=off).
							- If verification later succeeds (§7.10), clear all soft signals for this request; hard failures are never overridden.
				- When challenge is required but the provider is unconfigured (missing site/secret keys), do not hard-fail; retain the existing +1 soft signal, log EFORMS_CHALLENGE_UNCONFIGURED, and continue.
				- Precedence rule: If a valid hidden token is present, ignore any cookie token entirely (prevents stale-cookie false negatives).
				- Validation outputs: Security::token_validate() returns { mode:"hidden"|"cookie", token_ok:bool, hard_fail:bool, soft_signal:0|1, require_challenge:bool }. Downstream stages use this object; do not re-parse token state.
				- Cookie rotation: In cookie mode, rotate eforms_t_{form_id} on every POST (first, duplicate, or I/O error). No rotation in hidden-token mode.
				- User message: Map hard failures to EFORMS_ERR_TOKEN (“This form was already submitted or has expired - please reload the page.”).
				- Test matrix (add to CI):
					- hidden + required=true + missing -> HARD
					- hidden + required=false + missing -> soft +1
					- cookie + policy=hard + missing -> HARD
					- cookie + policy=soft + missing -> soft +1
					- cookie + policy=challenge + missing -> soft +1 + challenge; pass -> clears soft, fail/timeout -> remains soft +1
					- cookie + expired (treated as missing) + policy=hard ⇒ HARD
					- cookie + expired (treated as missing) + policy=soft ⇒ soft +1
					- cookie + expired (treated as missing) + policy=challenge ⇒ soft +1 + challenge; pass ⇒ clears soft
	2. Honeypot
		- Ordering: the Honeypot check runs after the CSRF gate (§7.1) and never overrides a CSRF hard fail.
		- Stealth logging: JSONL { code:"EFORMS_ERR_HONEYPOT", severity:"warning", meta:{ stealth:true } }; also set header X-EForms-Stealth: 1. Do not emit a normal "success" info log.
		- Field: eforms_hp (fixed POST name; randomized id). Must be empty. Submitted value is discarded and never logged as content.
		- Config: security.honeypot_response: "hard_fail" | "stealth_success" (default "stealth_success").
		- Common behavior (both modes):
			- Treat as spam-certain and short-circuit the pipeline before validation/coercion/email.
			- Delete any temporary uploads; do not store or attach.
			- Record throttle signal and log { code:"EFORMS_ERR_HONEYPOT", stealth:(security.honeypot_response==="stealth_success") } (no field value).
			- Token handling: attempt ledger reservation to burn the token; in cookie mode, rotate the cookie token in the response.
		- Mode: "stealth_success" (default)
			- No side-effects (no email, no persistent uploads), but mimic a normal success UX:
				- Inline mode: set the short-lived success cookie (eforms_s_{form_id}) and 303 PRG to the same URL; renderer will show the configured success message.
				- Redirect mode: 303 to success.redirect_url as usual.
			- Success metrics/analytics MUST NOT count these as real successes (log flag stealth:true).
		- Mode: "hard_fail"
		- Re-render the form with a generic global error (EFORMS_ERR_HONEYPOT) and HTTP 200; do not expose field-level hints.
	3. Timing Checks
		- min_fill_time default 4s (soft; configurable).
		- min_fill_time is measured from the instance's original timestamp; validation re-renders MUST NOT reset it
		- Max form age enforcement:
			- Cookie (cacheable="true") mode: token age is enforced by the cookie’s Max-Age; when expired/missing, treat as a missing cookie and apply security.cookie_missing_policy (i.e., policy decides hard/soft/challenge).
			- Hidden-token (cacheable="false") mode: posted timestamp is a best-effort age signal; older than security.max_form_age_seconds -> +1 soft (never a hard fail on age alone).
		- js_ok flips to "1" on DOM Ready (soft unless security.js_hard_mode=true, in which case hard fail).
	4. Headers
		- Normalize and truncate User-Agent to printable characters; cap length at security.ua_maxlen
		- Origin check (authoritative): When present, validate the Origin header. Normalize to scheme + host + effective port (80/443 normalized to defaults; non-default ports are significant). Compute origin_state as same | cross | unknown (null/opaque) | missing.
		- Policy (security.origin_mode):
			- off -> no signal (treat as +0).
			- soft (default) -> same -> +0; cross -> +1 soft; unknown (null/opaque) -> +1 soft; missing -> +1 soft only when security.origin_missing_soft=true, otherwise +0.
			- hard -> same -> +0; cross -> HARD FAIL; unknown (null/opaque) -> HARD FAIL; missing -> HARD FAIL only when security.origin_missing_hard=true, otherwise +0.
		- Logging: Log origin_state only (no Referrer). Do not log full header values.
		- Referrer is not consulted; the plugin is Origin-only.
		- Security::origin_evaluate() returns {state: same|cross|unknown|missing, hard_fail: bool, soft_signal: 0|1}. Downstream stages MUST NOT re-parse the Origin header.
	5. POST Size Cap (authoritative)
		- Applies after the Type gate in §7.1 (only application/x-www-form-urlencoded and multipart/form-data are accepted).
		- Definitions:
			- AppCap = security.max_post_bytes (authoritative plugin cap; integer bytes)
			- IniPost = Helpers::bytes_from_ini(ini_get('post_max_size'))
			- IniUpload = Helpers::bytes_from_ini(ini_get('upload_max_filesize'))
		- RuntimeCap (final, authoritative):
			- If uploads.enable = false or Content-Type = application/x-www-form-urlencoded -> RuntimeCap = min(AppCap, IniPost)
			- If uploads.enable = true and Content-Type = multipart/form-data -> RuntimeCap = min(AppCap, IniPost, IniUpload)
			- (Even if no files are posted, multipart/form-data still takes this branch; IniUpload participates in min().)
			- Additionally enforce:
				- uploads.total_request_bytes (request-level), and
				- per-file (uploads.max_file_bytes), per-field totals (uploads.total_field_bytes), and counts (uploads.max_files).
		- Guards and behavior:
			- Early abort: If CONTENT_LENGTH is present and strictly greater than RuntimeCap, abort with a generic message before reading the body.
			- Runtime note: In common PHP SAPIs the request body is parsed before userland; streaming enforcement during read may not be available. When CONTENT_LENGTH is missing/inaccurate, rely on PHP INI limits and post-facto aggregate checks (request/field/file caps) and reject upon detection.
			- Uploads disabled: When uploads.enable = false, never factor any uploads.* values (including IniUpload) into RuntimeCap.
			- Multipart without files: When uploads.enable = true but no file fields are declared/posted, RuntimeCap still follows the multipart branch above; per-file/field caps trivially pass.	
		- Non-normative test matrix (add to CI):
			- uploads=off + urlencoded → RuntimeCap = min(AppCap, IniPost)
			- uploads=on + urlencoded (no files) → RuntimeCap = min(AppCap, IniPost) (no IniUpload)
			- uploads=on + multipart with files → RuntimeCap = min(AppCap, IniPost, IniUpload) + uploads caps
			- Missing/incorrect CONTENT_LENGTH → rely on PHP INI limits; reject post-facto when aggregate caps are computed
		- max_input_vars advisory (non-fatal)
			- Purpose: warn developers when a form is likely to approach PHP’s max_input_vars limit. This is advisory only and never blocks submission.
			- Scope: computed at GET/render time only (no extra work during POST). No admin UI notices are used.
			- Operational notes:
				- PHP max_input_vars applies to $_POST/$_GET only (not $_FILES).
				- Radio groups submit one value at most (+1). Checkbox groups and multi-selects can submit many values.		
			- Threshold:
				- Let M = (int) ini_get('max_input_vars'). If M <= 0, set M = 1000.
				- Trigger an advisory when estimate >= ceil(0.9 * M).
			- Estimation algorithm (render time):
				- Hidden baseline inputs per instance:
				- hidden_base = 5 → form_id, instance_id, eforms_hp, timestamp, js_ok.
				- If cacheable="false" (hidden token rendered): hidden = hidden_base + 1, else hidden = hidden_base.
				- Initialize estimate = hidden.
				- For each field in the template:
					- Single-value controls (text, name/first_name/last_name, email, url, tel/tel_us, zip/zip_us, number, range, date, textarea, textarea_html, select (single), radio (group)): estimate += 1.
					- Checkbox group: estimate += min(options_count, validation.max_options_per_group).
					- Select (multiple): estimate += min(options_count, validation.max_options_per_group).
					- row_group pseudo-fields: +0 (no data).
				- Do not count uploads: $_FILES entries are excluded from max_input_vars.
			- When the threshold is met/exceeded:
				- Log a one-line JSONL advisory: { code:"EFORMS_MAX_INPUT_VARS_NEAR_LIMIT", severity:"warning", meta:{ estimate, max_input_vars: M } }.
				- Emit a developer-visible HTML comment adjacent to the form only when WP_DEBUG is true, e.g.: <!-- eforms: max_input_vars advisory — estimate=942, max_input_vars=1000 -->.
			- Remediation guidance (non-blocking, documented only):
				- Reduce large option sets (especially checkbox groups / multi-selects), split forms, or raise max_input_vars in php.ini/.user.ini.
			- Ignore challenge inputs: The estimate excludes any hidden inputs added by Turnstile/hCaptcha/reCAPTCHA. (Advisory is computed at GET time only; later POST-time challenge fields are intentionally ignored.)
	6. Spam Decision
		- Hard checks first: honeypot_empty and Security::token_validate().hard_fail (includes cookie policy / origin hard-fail). Any hard fail stops processing.
		- Soft signals (each adds 1 unless policy says otherwise):
			- min_fill_ok: false -> +1
			- js_ok not "1" -> +1; when security.js_hard_mode=true, this is a HARD FAIL instead (no soft +1).
			- ua_present: missing/empty UA -> +1
			- age_ok (hidden-token mode): false -> +1 (see §7.3)
			- Note (hidden-token mode): age_ok is advisory only (timestamp is client-supplied); CSRF protection derives from the Origin policy.
			- origin_soft_signal (from §7.4) contributes +1; if §7.4 hard-failed, this stage is never reached.
			- token signal: when Security::token_validate().soft_signal === 1, add +1 (covers hidden-token mode when submission_token.required=false and cookie mode per cookie_missing_policy).
			- When cookie_missing_policy=challenge and verification succeeds (§7.10), set soft_fail_count = 0 (do not override any hard failure).
		- Decision:
			- soft_fail_count >= spam.soft_fail_threshold -> spam-fail
			- soft_fail_count == 1 -> deliver as suspect
			- soft_fail_count == 0 -> deliver normal
		- Accessibility note: security.js_hard_mode=true will block non-JS users, including some assistive technologies. Keep it opt-in and document the trade-off.
		- challenge success clears soft signals, but never overrides hard failures.
	7. Redirect Safety
		- wp_safe_redirect; same-origin only, including scheme/host/port.
	8. Suspect Handling
		- add headers: X-EForms-Soft-Fails, X-EForms-Suspect; subject tag (configurable)
	9. Throttling (optional; file-based)
		- Purpose: dampen spikes from the same IP without DBs or queues.
		- Keying: compute a throttle key from the resolved client IP per §16, then apply privacy settings:
			- privacy.ip_mode=hash → sha256(ip + privacy.ip_salt).
			- privacy.ip_mode=masked|full → hash the masked/full IP the same way.
			- privacy.ip_mode=none → throttling disabled (no key available)
		- Algorithm (fixed 60s window, tiny file):
			- File shape: {"window_start": <unix>, "count": <int>, "cooldown_until": <unix|0>}.
			- On POST: lock file with flock, roll window if now - window_start >= 60, then count++.
			- If count > throttle.per_ip.max_per_minute -> throttle_state=over:
				- set cooldown_until = now + throttle.per_ip.cooldown_seconds.
				- Emit soft signal (throttled=true).
			- If count > throttle.per_ip.max_per_minute * throttle.per_ip.hard_multiplier -> throttle_state=hard:
				- HARD FAIL with generic message (no side effects).
			- While now < cooldown_until: treat as over on every POST.
		- Decision wiring:
			- Add throttle_ok/throttle_state into the spam signal set in §7.6.
			- Over-limit -> +1 soft. Hard over-limit -> hard failure.
		- Storage: ${uploads.dir}/throttle/{h2}/{key}.json (dirs 0700, files 0600); no date partitions. GC files whose mtime is older than 2 days during GET/POST shutdown.
	10. Adaptive challenge (optional; Turnstile preferred)
		- Purpose: only challenge when risk > 0; default off.
		- Modes:
			- off → never render/verify.
			- auto → require challenge only when soft_fail_count >= 1.
			- always → require challenge on every POST.
		- Providers: turnstile | hcaptcha | recaptcha (server-verify via WP HTTP API).
		- Render:
			- On GET: normally do not render.
			- On POST re-render with soft_fail_count >= 1 (and mode=auto) or when mode=always, render the widget placeholder and enqueue the provider script (see §22).
		- Verify (server-side, short timeouts; no external libs):
			- On POST when required, read provider’s response token; call verify endpoint with secret, response, and remoteip.
			- If success -> clear all soft signals for this request (soft_fail_count = 0), but do not override hard fails.
			- If failure or timeout -> add +1 soft and attach global error EFORMS_ERR_CHALLENGE_FAILED.
			- Requirement triggers: verify when challenge.mode="always", or ("auto" and soft_fail_count >= 1), or Security::token_validate().require_challenge === true (from cookie policy).
			- Unconfigured provider fallback: if verification is required but the provider is unconfigured, skip verification, add +1 soft (if not already present), and log EFORMS_CHALLENGE_UNCONFIGURED.
		- Accessibility: rely on provider’s built-in accessibility; always allow retry on re-render.
		- Turnstile → cf-turnstile-response; hCaptcha → h-captcha-response; reCAPTCHA v2 → g-recaptcha-response
		
8. VALIDATION & SANITIZATION PIPELINE (DETERMINISTIC)
	0. Structural preflight (stop on error; no field processing)
		- Unknown keys rejected at every level (root/email/success/field/rule).
		- fields[].key must be unique; duplicates → EFORMS_ERR_SCHEMA_DUP_KEY.
		- Enum enforcement (field.type, rule.rule, row_group.mode, row_group.tag).
		- Conditional requirements (e.g., success.mode="redirect" -> redirect_url required; type="files" -> max_files optional but not < 1; row_group must omit key).
		- accept[] intersect global allow-list must be non-empty; empty -> EFORMS_ERR_ACCEPT_EMPTY.
		- Row-group object shape must match spec; mis-shapes -> EFORMS_ERR_SCHEMA_OBJECT.
	1. Security gate (hard/soft signals; stop on hard failure)
	2. Normalize (lossless; wp_unslash/trim; intl Normalizer NFC if available)
		- Uploads: flatten $_FILES; shape items as { tmp_name, original_name, size, error, original_name_safe }
		- Treat UPLOAD_ERR_NO_FILE or empty original_name as "no value".
		- An item is "present" only when error === UPLOAD_ERR_OK AND size > 0; otherwise it is "no value" (and triggers a validation error if the field is required).
	3. Validate (normalized values)
		- required, max_length, patterns, allow-lists, cross-field rules
		- Options: reject submissions that include a key marked disabled in the options[] for that field.
		- Uploads:
			- per-file/field/request caps; count cap for files
			- MIME/ext/finfo agreement required.
			- application/octet-stream is allowed only when finfo and file extension agree and the accept-token allows it; otherwise treat as unknown and reject. Unknown/zero/ambiguous MIME types are rejected.
			- Optional image sanity via getimagesize for images
			- No SVG; no macro-enabled Office
			- Arrays rejected on single-file fields
		- finfo must not return false. When finfo is false or unknown, treat as unknown and reject. application/octet-stream is allowed only when finfo and extension agree and an accept-token permits it.
		- Only evaluate fields declared in the template; ignore extraneous POST keys. Still reject arrays where a scalar is expected.
		- Client validation (when enabled) is advisory only; the full server pipeline executes for every POST regardless of client state.
	4. Coerce (post-validate)
		- cast/canonicalize; lowercase email domain; collapse whitespace (if enabled)
		- defer file moves until global success; move to private dir; 0600/0700 perms; hashed stored name; compute sha256
	5. Use canonical values only (email/logs)
	6. Escape at sinks only (per map in section 6)

9. SPECIAL CASE: HTML-BEARING FIELDS
	- textarea_html only
	- size bound via validation.textarea_html_max_bytes (default 32768 bytes)
	- sanitize with wp_kses_post; sanitized result is canonical; escape per context at sinks
	- Post-sanitize bound: after wp_kses_post, re-check the canonical value size. If bytes > validation.textarea_html_max_bytes, fail validation with EFORMS_ERR_HTML_TOO_LARGE. Do not auto-truncate to avoid silent data loss.

10. CROSS-FIELD RULES (BOUNDED SET)
	- Supported:
		- required_if: { "rule":"required_if", "field":"other", "equals":"value" }
		- required_if_any: { "rule":"required_if_any", "fields":[...], "equals_any":[...] }
		- required_unless: { "rule":"required_unless", "field":"other", "equals":"value" }
		- matches: { "rule":"matches", "field":"other" }
		- one_of: { "rule":"one_of", "fields":["a","b","c"] }
		- mutually_exclusive: { "rule":"mutually_exclusive", "fields":["a","b"] }
	- Deterministic evaluation order: top-to-bottom
	- additionalProperties:false per rule object
	- Multiple violations reported together

11. BUILT-IN FIELD TYPES (DEFAULTS; US-FOCUSED)
        - Spec::descriptorFor($type) exposes a descriptor for each field type with:
                - is_multivalue: bool
                - html { tag:"input|textarea|select", type?, multiple?, inputmode?, pattern?, attrs_mirror:{ maxlength?, minlength?, min?, max?, step? } }
                - validate { required?, pattern?, range?, canonicalize? }
        - name / first_name / last_name: alias of text; trim internal multiples; default autocomplete accordingly
        - text: length/charset/regex
        - textarea: length/charset/regex
	- textarea_html: see 9. mirror maxlength/minlength when provided.
	- email: type="email", inputmode="email", spellcheck="false", autocapitalize="off"; mirror maxlength/minlength when set.
	- url: wp_http_validate_url + allowed schemes (http, https). type="url", spellcheck="false", autocapitalize="off". (No need for inputmode here; type="url" already pulls the right keyboard.)
	- tel_us: NANP; digits-only canonical 10 digits; optional +1 stripped; no extensions. type="tel", inputmode="tel"; mirror maxlength;
	- tel (generic): freeform; trimmed
	- number / range: keep native input types; add inputmode="decimal" and mirror min/max/step exactly as validated server-side.
	- select / radio: store option key
	- checkbox: single -> bool; group -> array of keys
	- zip_us: type="text", inputmode="numeric", pattern="\\d{5}" (hint only); always set maxlength=5; server enforces ^\d{5}$.
	- zip (generic): freeform
	- file: single upload. Map accept tokens to explicit lists:
		- image → image/jpeg,image/png,image/gif,image/webp
		- pdf   → application/pdf
	- files: multiple upload with max_files; same explicit lists; email attachment policy unchanged (§14).
	- The field.type enum includes all types listed in this section plus the row_group pseudo-field (see 5.2).
	- date: mirror min/max and step when provided.
	- For each field, include the HTML attributes you'll emit (e.g., email -> inputmode=email, spellcheck=false, autocapitalize=off; files -> multiple; tel_us -> inputmode=tel; zip_us -> inputmode=numeric).
	- Cache active descriptors per request: when loading the template, precompute a per-field descriptor (resolved max/min/step, inputmode, pattern, etc.) and reuse it in both Renderer and Validator to avoid double lookups and keep attribute mirroring perfectly in sync.

12. ACCESSIBILITY (A11Y)
	1. Labels
		- Always render a <label> for each control; if missing, derive Title Case label and mark visually hidden
		- label@for matches control id; control id unique
	2. Required Fields
		- native controls: use native required only (no aria-required)
		- custom widgets: aria-required="true"
		- show visual indicator (e.g., "*")
	3. Grouped Controls
		- radio/checkbox groups wrapped in <fieldset> with <legend>
		- link error summary targets to fieldset/legend (or first control); use aria-describedby to include error id
	4. Error Summary (top)
		- role="alert" container appears after submit when errors exist; list links to invalid controls; forms.js focuses summary then first invalid control
		- Do not use role="alert" on each field; if live updates are needed, use aria-live="polite" or role="status" on the field-level error container.
		- Do not set role="alert" globally; only the error summary uses it post-submit.
		- For radio/checkbox groups, error links target the <fieldset>/<legend> container (or first control), and aria-describedby includes the error id.
		- Global summary uses role="alert" only after submit; individual fields use aria-live="polite" if needed.
		- The error summary container must be focusable with tabindex="-1"; forms.js focuses it once after submit when errors exist, then focuses the first invalid control.
	5. Per-field Errors
		- <span id="error-{field_id}" class="eforms-error">...</span>
		- when invalid: aria-invalid="true"; aria-describedby includes error id
	6. Focus Behavior
		- forms.js focuses first invalid after submission
		- Do not set multiple autofocus attributes.
	7. File Inputs
		- follow same patterns as native inputs

13. SUCCESS BEHAVIOR (PRG)
	- inline: PRG (303) to same URL with eforms_success={form_id}; renderer shows success only in the first instance in source order when multiple same-ID instances are present; suppress in subsequent instances.
	- redirect: wp_safe_redirect(redirect_url, 303); no flag on destination
	- PRG status: fixed at 303.
	- Page caching: do not disable page caching globally. Only vary/bypass caching for (a) the short-lived success cookie eforms_s_{form_id} and (b) requests containing eforms_* query args.
	- Success responses MUST send: Cache-Control: private, no-store, max-age=0 and SHOULD include Vary: Cookie scoped to eforms_s_{form_id}.
	- Any request containing eforms_* query args MUST send: Cache-Control: private, no-store, max-age=0.
	- namespace internal query args with eforms_*
	- success.message is treated as plain text and escaped.
	- Anti-spoofing (inline mode only): on successful POST for inline mode, set a short-lived, HttpOnly, SameSite=Lax cookie (e.g., eforms_s_{form_id}) bound to {form_id}:{instance_id}. On subsequent GET, show success only when both the query arg AND a matching cookie are present; then clear the cookie.
	- Cookie TTL is 5 minutes; set HttpOnly, SameSite=Lax, and Secure when is_ssl(); cookie path = current request path.
	- When rendering the success view (cookie + query matched), send no-cache headers (e.g., call nocache_headers()) to prevent cached success for other users.
	- If your cache layer supports it, add Vary: Cookie (or equivalent) for the eforms_s_{form_id} cookie on the success response.
	- If a page cache is present, bypass caching for requests with eforms_s_{form_id} or set "no-store" on the success response if your cache layer respects it.

14. EMAIL DELIVERY
	- DMARC alignment: From: no-reply@{site_domain}
	- From precedence: if email.from_address is a valid same-domain address, use it; otherwise default to no-reply@{site_domain}. Always keep From: on the site domain for DMARC alignment.
	- email.envelope_sender (string; optional; same-domain recommended). If set, PHPMailer->Sender is used; otherwise server default applies. Bounces will target this address.
	- From domain: parse_url(home_url()).host (lowercase; strip www)
	- default content type: text/plain; HTML emails only if email.html=true
	- subjects/headers built programmatically; sanitize for CR/LF; no raw user input in headers
	- Header byte caps (defensive): After collapsing control characters, truncate both the Subject and From Name to ≤255 bytes (UTF-8 safe) before header assembly. No CR/LF or multi-line input is ever accepted from user fields.
	- PHPMailer may fold long headers to comply with RFCs; our pre-cap guarantees we never pass pathological lengths into header assembly.
	- Reject arrays where a scalar is expected in headers/subject fields
	- Additionally, collapse ASCII control characters (0x00-0x1F, 0x7F) in From Name and Subject to a single space before header assembly.
	- Reply-To from a validated email field (config via email.reply_to_field)
	- deliverability: recommend SMTP with SPF/DKIM/DMARC
	- template tokens: {{field.key}}, {{submitted_at}}, {{ip}}, {{form_id}}
	- If an upload field key appears in include_fields, render its value as a comma-separated list of original_name_safe in the email body (attachments are governed separately by email_attach and size/count limits)
	- attachments: only for fields with email_attach=true; enforce uploads.max_email_bytes and email.upload_max_attachments; summarize overflow in body
	- Order of operations: enforce uploads.max_email_bytes and email.upload_max_attachments before invoking PHPMailer->send() to avoid SMTP 552 rejections.
	- staging safety: email.disable_send; email.staging_redirect_to (string|array) to override all recipients; add X-EForms-Env: staging; prefix subject [STAGING]. (EFORMS_STAGING_REDIRECT_TO remains a deprecated alias.)
	- optional DKIM via PHPMailer when email.dkim.* set
	- If any email.dkim.* parameter is missing/invalid, proceed without DKIM and log a warning.
	- PHPMailer debug is enabled only when email.debug.enable=true and logging.level>=1; capture via Debugoutput; strip CR/LF; redact secrets/credentials; when logging.pii=false, also redact full email addresses; truncate to email.debug.max_bytes.
	- PHPMailer Timeout set from email.smtp.timeout_seconds; on transient failures, retry up to email.smtp.max_retries with email.smtp.retry_backoff_seconds backoff.
	- Register wp_mail_failed to log reason and phpmailer_init to apply DKIM and optional debug output.
	- email.policy semantics:
		- strict: RFC-compliant parsing; trim; single @; reject otherwise.
		- autocorrect: do strict parsing, then:
		- trim & collapse internal spaces,
		- lowercase domain,
		- normalize common domain typos in display only (.con→.com, .c0m→.com); canonical stays strict,
		- if correction applied, include [corrected] note in logs (headers never use unverified input).
	- display_format_tel tokens (allowed):
		- "xxx-xxx-xxxx" (default)
		- "(xxx) xxx-xxxx"
		- "xxx.xxx.xxxx"
		- Any other value falls back to default. Formatting affects email body only (not logs/canonical).

15. LOGGING
- Single mental model: mode chooses destination, level chooses severities, pii/headers choose detail, and rotation uses two dials: 'file_max_size' + 'retention_days'.
- Destinations
	- 'logging.mode' (authoritative): '"jsonl" | "minimal" | "off"'.
		- jsonl — structured JSONL files in '${uploads.dir}' with rotation/retention.
		- minimal — one compact line per event via 'error_log()' (or 'wp_debug_backtrace_summary'), no file rotation (server log policy governs retention).
		- off — no logging (except optional Fail2ban line if enabled; see Fail2ban below).
- Severity mapping (backend-agnostic)
	- error — fatal pipeline failures (invalid config, SMTP errors, file/ledger I/O).
	- warning — rejected submissions (spam decisions, validation failures, challenge timeouts).
	- info — successful sends, token rotations, throttling state changes.
- Verbosity
	- 'logging.level': '0|1|2' (default '0')
		- '0' → errors only
		- '1' → errors + warnings (includes *all* rejections & spam decisions)
		- '2' → errors + warnings + info
- PII/headers toggles (unchanged)
	- 'logging.pii' (default 'false') — when 'true', allows full emails/IPs in JSONL only; minimal mode still masks unless explicitly overridden.
	- 'logging.headers' (default 'false') — if 'true', log *normalized* UA/Origin (Origin as scheme+host only; no path/query/fragment).
- Rotation & retention (two-dial)
	- Files live under: 'wp_upload_dir()['basedir'].'/eforms-private' (dirs '0700', files '0600').
	- Rotate current JSONL file when size exceeds 'logging.file_max_size' (bytes).
	- Prune by age: delete JSONL files older than 'logging.retention_days'.
	- Order: on write → rotate if needed → prune by age.
	- Note: 'flock()' might be unreliable on some NFS mounts; prefer local disk or documented NFS settings.
- What to log (all modes, subject to PII/headers)
	- Timestamp (UTC ISO-8601), severity, form_id, instance_id, request URI (path + only 'eforms_*' query), privacy-processed IP (per §16), stable error code + message, spam signals summary (honeypot, origin_state, soft_fail_count, throttle_state), plus email SMTP failure reason when applicable.
	- Optionally, when 'logging.on_failure_canonical=true', include sanitized field names + sanitized values only for fields causing rejection.
	- Throttle & challenge: when 'logging.level >= 1', log throttle decisions and challenge verify outcome as compact meta (redact provider tokens).
- Minimal mode line format
	- eforms severity=<error|warning|info> code=<EFORMS_*|PHPMailer> form=<form_id> inst=<instance_id> ip=<masked|hash|full|none> uri="<path?eforms_*...>" msg="<short message>" meta=<compact JSON>
	- SMTP/PHPMailer: include host, safe error, retries; when 'email.debug.enable=true' and 'logging.level>=1', append a truncated debug tail ('email.debug.max_bytes'), redacting secrets (and full emails when 'logging.pii=false').
- JSONL structure (keys)
	- Required: { ts, severity, code, form_id, instance_id, uri, ip }
	- Optional: msg (short text)
	- Optional groups:
		- spam: { soft_fail_count, origin_state, honeypot, throttle_state }
		- email: { status, retries }
		- meta:  { ... }
	- Notes:
		- code is the stable enum (e.g., "EFORMS_ERR_TOKEN", "PHPMailer").
		- Omit 'event' entirely. If you need a freeform label (e.g., "reserve", "send"), put it under meta.event.
- Fail2ban (optional; unchanged except gating)
	- Config: see §17 'logging.fail2ban.*'.
	- Emit one machine-parsable line on honeypot hits, token hard fails, hard throttle, Origin hard fails, challenge fails/timeouts:
		- 'eforms[f2b] ts=<unix> code=<EFORMS_ERR_*> ip=<resolved_client_ip> form=<form_id>'
	- This line always uses the resolved client IP per §16 and ignores 'privacy.ip_mode'; all other logs honor privacy settings.
	- When logging.fail2ban.target="file": write to logging.fail2ban.file (dirs 0700, file 0600). Apply the same two-dial policy as JSONL:
		- Rotate: before each write, if filesize > logging.fail2ban.file_max_size, rename the current file to <basename>-YYYYMMDD-HHMMSS.log and open a fresh file.
		- Retention: after rotation (and at least once per day), delete rotated files older than logging.fail2ban.retention_days.
		- Order: on write → rotate if needed → prune by age.
		- Concurrency: serialize with flock() for append/rotate; on I/O error, fall back to error_log() for that line and emit a JSONL warning {code:"EFORMS_FAIL2BAN_IO"} when logging.mode!="off".
- Implementation notes
	- Initialize logging only when 'logging.mode != "off"'.
	- Never log textarea/textarea_html bodies or attachments unless explicitly enabled (debug/forensics gates).
	- In minimal mode, suppress normal reserve outcomes ("first" / "duplicate"); only emit EFORMS_LEDGER_IO lines when an actual I/O issue occurs. JSONL mode logs both outcomes as info.
	- Fail2ban emission is independent of logging.mode and controlled solely by logging.fail2ban.*.
	
16. PRIVACY AND IP HANDLING
	- privacy.ip_mode = none | masked | hash | full (default masked)
	- masked: IPv4 last octet(s) redacted; IPv6 last 80 bits zeroed (compressed)
	- hash: sha256(ip + optional salt); store hash only
	- include ip in email.include_fields only when mode != none
	- UA and Origin never included in emails; logging only
	- submitted_at set server-side (UTC ISO-8601) for logs/emails
	- By default, use REMOTE_ADDR. When behind trusted proxies, set:
		- privacy.client_ip_header (string; default "") — e.g., "X-Forwarded-For" or "CF-Connecting-IP".
		- privacy.trusted_proxies (array of CIDR strings; default []).
		- If the request source is in trusted_proxies, parse the first public IP from privacy.client_ip_header. Otherwise fall back to REMOTE_ADDR. Fail2ban and throttling use this resolved IP.
		- “Public IP” = not in: 10/8, 172.16/12, 192.168/16, 127/8, 169.254/16, 100.64/10, ::1/128, fc00::/7, fe80::/10.
		- Header is parsed case-insensitively; value is a comma-separated list. Trim each token; strip brackets/ports; accept only valid IP literals. If no valid public IP is found, fall back to REMOTE_ADDR.
		- When the source is in privacy.trusted_proxies, resolve client IP from the left-most valid public address in privacy.client_ip_header. If the header is missing/malformed, fall back to REMOTE_ADDR
		- If privacy.client_ip_header is empty, never consult headers even when the source is in trusted_proxies.
	- CI tests: 
		- Request from untrusted IP with forged XFF → use REMOTE_ADDR.
		- Request from trusted proxy with X-Forwarded-For: client, proxy → pick client.
		- Same as above but header contains only private IPs → fall back to REMOTE_ADDR.
		
17. CONFIGURATION (SUMMARY)
The plugin uses an immutable, per-request Config snapshot:
	- Config::bootstrap() loads built-in defaults (a nested array mirroring §17), applies a single eforms_config filter once, validates/clamps types/ranges/enums, then freezes.
	- Access via Config::get('path.like.this').
	- Legacy EFORMS_* constants remain supported for backward compatibility but are deprecated; when present they seed/override the default map before filter/validation.
	- All keys below are expressed as config paths, e.g., security.min_fill_seconds. (You may still define EFORMS_* during migration.)
	- security.origin_mode: off | soft | hard (default soft)

security.*
	security.token_ledger.enable (bool; default true) - enable file-backed one-time token invalidation (no DB writes).
	security.token_ttl_seconds (int; default 600) - lifetime for submission tokens (cookie and ledger).
	security.submission_token.required (bool; default true) - if false, cookie token becomes a soft signal only (not recommended).
	security.origin_mode: off | soft | hard (default soft)
	security.origin_missing_soft: bool; default false. When true, treat missing Origin as +1 soft.
	security.origin_missing_hard: bool; default false. When true and origin_mode=hard, missing Origin is a HARD FAIL.
	security.min_fill_seconds (int; default 4; clamp 0-60)
	security.max_form_age_seconds (derived from security.token_ttl_seconds)
	security.js_hard_mode (bool; default false)
	security.max_post_bytes (int; default 25 000 000)
	security.ua_maxlen (int; default 256)
	security.honeypot_response (string; "hard_fail"|"stealth_success"; default "stealth_success")
	security.cookie_missing_policy (string; default "soft") — Controls behavior in cookie mode (cacheable="true") when the cookie token is missing or invalid. Values:
		- "hard": hard fail (EFORMS_ERR_TOKEN).
		- "soft": add +1 soft and continue to §7.6.
		- "challenge": add +1 soft, require a challenge regardless of challenge.mode; if the challenge succeeds, clear all soft signals for this request.
		- Precedence: In cookie mode, cookie_missing_policy overrides submission_token.required. In hidden-token mode, submission_token.required applies.

spam.*
	spam.soft_fail_threshold (int; default 2; clamp 0-5)
	
throttle.*
	throttle.enable (bool; default false; effective only when a throttle key can be computed; with privacy.ip_mode=none throttling is inert.)
	throttle.per_ip.max_per_minute (int; default 5; clamp 1-120)
	throttle.per_ip.cooldown_seconds (int; default 60; clamp 10-600)
	throttle.per_ip.hard_multiplier (float; default 3.0; clamp 1.5-10.0)

challenge.*
	challenge.mode (off|auto|always; default off)
	challenge.provider (turnstile|hcaptcha|recaptcha; default turnstile)
	challenge.turnstile.site_key (string|null; default null)
	challenge.turnstile.secret_key (string|null; default null)
	challenge.hcaptcha.site_key (string|null; default null)
	challenge.hcaptcha.secret_key (string|null; default null)
	challenge.recaptcha.site_key (string|null; default null)
	challenge.recaptcha.secret_key (string|null; default null)
	challenge.http_timeout_seconds (int; default 2; clamp 1-5)


html5.*
	html5.client_validation (bool; default false) - When true, the Renderer omits novalidate on <form> to allow native browser validation UI. The server-side Validator still runs and remains authoritative; browser messages may vary by user agent.

email.*
	email.policy (strict|autocorrect; default strict) (now defined in §14)
	email.smtp.timeout_seconds (int; default 10)
	email.smtp.max_retries (int; default 2)
	email.smtp.retry_backoff_seconds (int; default 2)
	email.html (bool; default false)
	email.from_address (validated same-domain email)
	email.from_name (sanitized text)
	email.reply_to_field (field key; optional)
	email.envelope_sender
	email.dkim.domain / email.dkim.selector / email.dkim.private_key_path / email.dkim.pass_phrase (optional; all must be valid to enable DKIM)
	email.disable_send (bool; default false)
	email.staging_redirect_to (string|array; overrides all recipients in staging) (deprecated alias EFORMS_STAGING_REDIRECT_TO still recognized)
	email.suspect_subject_tag (string; default [SUSPECT])
	email.upload_max_attachments (int; default 5)
	email.debug.enable (bool; default false)
	email.debug.max_bytes (int; default 8192)
	Header safety: collapse ASCII control characters in From Name and Subject to a single space before header assembly (implemented in Emailer; noted here for completeness)

logging.*
	logging.mode ("jsonl"|"minimal"|"off"; default "minimal")
	logging.level (0 errors; 1 +warnings; 2 +info; default 0)
	logging.headers (bool; default false)
	logging.pii (bool; default false)
	logging.on_failure_canonical (bool; default false)
	logging.file_max_size (int bytes; default 5_000_000)
	logging.retention_days (int; default 30)
	logging.fail2ban.enable (bool; default false)
	logging.fail2ban.target ("error_log"|"syslog"|"file"; default "error_log")
	logging.fail2ban.file (string|null; required when target="file")
	logging.fail2ban.file_max_size (int bytes; default uses logging.file_max_size)
	logging.fail2ban.retention_days (int; default uses logging.retention_days)

privacy.*
	privacy.ip_mode (none|masked|hash|full; default masked)
	privacy.ip_salt (string; used when mode=hash)
	privacy.client_ip_header (string; default "")
	privacy.trusted_proxies (array of CIDR; default [])

assets.*
	assets.css_disable (bool; default false)

install.*
	install.min_php (string; default 8.0)
	install.min_wp (string; default 5.8)
	install.uninstall.purge_uploads (bool; default false)
	install.uninstall.purge_logs (bool; default false)
	
validation.*
	validation.max_fields_per_form (int; default 150)
	validation.max_options_per_group (int; default 100)
	validation.max_items_per_multivalue (int; default 50) - applies to type=files count before max_files
	validation.textarea_html_max_bytes (int; default 32768)

uploads.*
	uploads.enable (bool; default true)
	uploads.dir (path; defaults to wp_upload_dir()['basedir'].'/eforms-private')
	uploads.allowed_tokens (array; default [image, pdf])
	uploads.allowed_mime (array; conservative; intersect WP allowed)
	uploads.allowed_ext (array; derived, lowercase)
	uploads.max_file_bytes (int; default 5000000)
	uploads.max_files (int; default 10)
	uploads.total_field_bytes (int; default 10000000)
	uploads.total_request_bytes (int; default 20000000)
	uploads.max_email_bytes (int; default 10000000)
	uploads.delete_after_send (bool; default true)
	uploads.retention_seconds (int; default 86400)
	uploads.max_image_px (int; default 50000000) // width*height guard
	uploads.original_maxlen (int; default 100)
	uploads.transliterate (bool; default true)
	uploads.max_relative_path_chars (int; default 180) - hard cap on the full relative stored path (e.g., Ymd/slug-sha16-seq.ext). If exceeded, shorten original_slug safely to fit.
	sha16 definition: sha16 is the first 16 hex characters of the file's SHA-256; the full SHA-256 is recorded in logs.

18. UPLOADS (IMPLEMENTATION DETAILS)
	- Intersection: field accept[] intersect global allow-list must be non-empty -> else EFORMS_ERR_ACCEPT_EMPTY
	- Stored filename: {Ymd}/{original_slug}-{sha16}-{seq}.{ext} where sha16 = first 16 hex of the file's SHA-256; files 0600, dirs 0700; full SHA-256 recorded in logs.
	- Path collision: increment seq
	- Path length cap: enforce uploads.max_relative_path_chars. When over, truncate original_slug (preserving extension) to fit, ensuring a deterministic result.
	- Email attachments use original_name_safe (RFC 5987 encoding as needed); de-dupe per email scope: name.ext, name (2).ext, ...
	- Delete uploads after successful send unless retention applies; if email send fails after files were stored, clean up per retention policy. On final send failure, delete stored files immediately unless uploads.retention_seconds > 0—then GC per retention.
	- GC: opportunistic on GET and best-effort on POST shutdown only. No WP-Cron scheduling to honor "No DB writes".
	- For the per-form upload bootstrap, compute a has_uploads flag during TemplateValidator preflight and carry it in the context; guard Uploads init on that.

19. REQUEST LIFECYCLE
	1. GET
		- Shortcode [eform id="slug"] or template tag eform_render('slug')
		- FormManager loads template, generates secure instance_id, sets timestamp
		- Registers/enqueues CSS/JS only when rendering
		- Adds hidden fields: form_id, instance_id, eforms_hp, timestamp, js_ok; and when cacheable="false" also eforms_token. No hidden token when cacheable="true" (cookie-only).
		- Always set method="post". If any upload field is present, also add enctype="multipart/form-data".
		- Opportunistic GC may run (no WP-Cron).
		- If the max-input-vars heuristic triggers, write an advisory to logs and emit an HTML comment next to the rendered form only when WP_DEBUG is true.
		- Operational note: ensure your CDN/page cache (a) bypasses caching on non-cacheable form pages (Cache-Control: no-store), (b) treats /eforms/prime as no-store, and (c) does not strip Set-Cookie on 204 responses from /eforms/prime.
		- Initialize Logging only when logging.mode != "off".
		- Initialize Uploads only when uploads.enable=true and the current template declares at least one file/files field (detected at preflight).
		- Registries/services are instantiated lazily; only the registries required for enabled features are loaded (see 6).
		- When html5.client_validation=true, omit novalidate; native UI may prevent submit before our JS runs-our server validator still runs on POST.
		- Resolve handlers/traits only for fields present in the current template instance.
	2. POST
		- Security gate -> Normalize -> Validate -> Coerce
		- Early enforce RuntimeCap using CONTENT_LENGTH when present; otherwise rely on PHP INI limits and post-facto aggregate caps (see §7.5).
		- On errors:
			- If errors occur before token reservation, re-render reusing instance_id, timestamp, and (if hidden) the same eforms_token.
			- If errors occur after token reservation (e.g., SMTP/storage), re-render with a new instance_id and (if hidden) a new eforms_token, preserving canonical field values and displaying a global operational error.
		- Commit reservation (moved from §7.1):
		- When Validate/Coerce have succeeded and immediately before side effects (email send, file finalize), reserve the token by creating the sentinel ${ledger_base}/{h2}/{hash}.used with fopen('xb') (0700/0600 perms for directories/files).
		- If reservation fails with EEXIST → treat as duplicate: halt side effects and show the generic token message (EFORMS_ERR_TOKEN).
		- If reservation encounters other I/O errors → treat as duplicate and log {code:"EFORMS_LEDGER_IO"}; do not crash.
		- Honeypot exception: honeypot hits reserve/burn earlier by design (see §7.2).
		- On success: move stored uploads, send email, log event(s), PRG or redirect, cleanup per retention
		- Best-effort GC on shutdown after POST (no WP-Cron).
		- Stash validation errors and canonical values in-memory for this request only, keyed by instance_id; nothing is persisted.
		- When throttle.enable=true and a throttle key is available, run the throttle check; record throttle_state as a spam signal:
			- over -> +1 soft and include Retry-After: {cooldown_seconds} header.
			- hard -> HARD FAIL with a generic message; skip side effects.
		- Challenge hook:
			- Compute all soft signals as usual.
			- If challenge.mode=always or (challenge.mode=auto and soft_fail_count>=1):
			- On success -> set soft_fail_count=0 and continue; does not override hard failures (token, Origin, hard throttle).
			- While throttle_state="hard" -> hard fail with a generic message regardless of challenge outcome.

20. ERROR HANDLING
	- Errors stored by field_key; global errors under _global
	- Renderer prints global summary + per-field messages
	- Upload user-facing messages:
		- "This file exceeds the size limit."
		- "Too many files."
		- "This file type isn't allowed."
		- "File upload failed. Please try again."
	- When re-rendering after errors, pass the original meta (instance_id, timestamp, hidden token) in the stash/context back to Renderer, so it doesn't call "new" helpers.
	- Config error (fragments/groups): "Form configuration error. Please contact the site owner."
	- Emit stable error codes for logs/support (e.g., EFORMS_ERR_TOKEN, EFORMS_ERR_HONEYPOT, EFORMS_ERR_TYPE, EFORMS_ERR_ACCEPT_EMPTY, EFORMS_ERR_ROW_GROUP_UNBALANCED, EFORMS_ERR_SCHEMA_UNKNOWN_KEY, EFORMS_ERR_SCHEMA_ENUM, EFORMS_ERR_SCHEMA_REQUIRED, EFORMS_ERR_SCHEMA_TYPE, EFORMS_ERR_SCHEMA_OBJECT).
	- Large form advisory: when estimated inputs risk exceeding max_input_vars, write a one-line JSONL advisory and (when WP_DEBUG is true) emit an HTML comment near the form. No wp-admin notices are used.
	- "This content is too long." maps to EFORMS_ERR_HTML_TOO_LARGE.
	- "This form was already submitted or has expired - please reload the page." (maps to EFORMS_ERR_TOKEN)

21. COMPATIBILITY AND UPDATES
	- Changing type defaults or rules updates behavior globally via registry
	- Templates remain portable (no callbacks)
	- Minimum versions: PHP >= 8.0; WordPress >= 5.8 (admin notice + deactivate if unmet)
	- Terminology: the spec and code use allow-list/deny-list consistently (no "whitelist/blacklist").

22. ASSETS (CSS & JS)
	- Enqueued only when a form is rendered; version strings via filemtime().
	- forms.js provides js_ok="1" on DOM Ready, submit-lock/disabled state, error-summary focus, and first-invalid focus. Not required unless security.js_hard_mode=true.
	- assets.css_disable=true lets themes opt out
	- On submit failure, focus the first control with an error
	- Focus styling (accessibility): do not remove outlines unless a visible replacement is provided. For inside-the-box focus, use: outline: 1px solid #b8b8b8 !important; outline-offset: -1px;
	- When html5.client_validation=true: do not suppress or compete with native validation UI. Skip pre-submit summary focus to avoid double-focus; let the browser show its bubbles. After a server-side re-render with errors, still focus the first invalid control.
	- No JS is required for the new HTML attributes; they are emitted by the Renderer as markup-only UX hints.
	- Only enqueue a provider script when the challenge is rendered:
		- Turnstile: https://challenges.cloudflare.com/turnstile/v0/api.js (defer, crossorigin=anonymous).
		- hCaptcha: https://hcaptcha.com/1/api.js (defer).
		- reCAPTCHA (v2): https://www.google.com/recaptcha/api.js (defer).
	- Do not load any challenge script on initial GET unless required (see §7.10).
	- Load timing: Provider scripts are deferred and loaded only when the challenge widget is rendered (i.e., on POST re-render when required by policy, or when challenge.mode="always"). Never load on the initial GET unless required by §7.10.
	- Secrets hygiene: Render only the public site_key to HTML. Never inline or expose secret_key or verify tokens in markup/JS. Server-side verification uses the secret with short timeouts; tokens are redacted in logs.
	- Keep novalidate logic unchanged.

23. NOTES FOR IMPLEMENTATION
	- instance_id: cryptographically secure random (e.g., 16-24 bytes base64url)
	- timestamp: server epoch seconds at render time
	- Use esc_textarea for <textarea> output
	- Enqueue assets only when a form exists on the page
	- Logs dir perms 0700; log files 0600
	- Sanitize class tokens [A-Za-z0-9_-]{1,32} per token; cap total length
		-> Deterministic algorithm: split on whitespace; keep tokens matching [A-Za-z0-9_-]{1,32}; truncate any longer token to 32; de-duplicate while preserving first occurrence order; join with a single space; if none remain, omit the class attribute; cap the final attribute string at 128 characters.
	- Option keys: [a-z0-9_-]{1,64}; unique within field
	- Filename policy: see 26.3
	- TemplateValidator sketch: pure-PHP walkers with per-level allowed-key maps; normalize scalars/arrays; emit EFORMS_ERR_SCHEMA_* with path (e.g., fields[3].type)
	- Caching: in-request static memoization only; no cross-request caching.
	- No WordPress nonce usage. Submission token TTL is controlled via security.token_ttl_seconds.
	- max_input_vars heuristic is intentionally conservative; it does not count $_FILES. Prefer warning early rather than risking dropped POST variables.
	- Place index.html and server deny rules (.htaccess, web.config) in both uploads and logs directories. Keep perms at 0700 (dirs) / 0600 (files).
	- Renderer & escaping: canonical values remain unescaped until sink time; Renderer never escapes twice and never mixes canonical with escaped strings.
	- Helpers::bytes_from_ini(?string $v): int — parses K/M/G suffixes; "0"/null/"" -> PHP_INT_MAX; clamps to non-negative.
	- The cookie-policy precedence removes ambiguity and keeps UX predictable on cookie-blocked browsers without weakening your hidden-token path.
	- When cookie_missing_policy='challenge' and verification succeeds, do not rotate the cookie again on that same response (to avoid defeating back-button resubmits).
	- Minimal logging via error_log() is a good ops fallback on shared hosting; JSONL remains your primary, structured option.
	- Fail2ban emission isolates raw IP use to a single, explicit channel designed for enforcement.
	- Fail2ban file rotation uses the same timestamped rename scheme as JSONL. Rotated files share the same directory/prefix as logging.fail2ban.file.
	- If logging.fail2ban.file is a relative path, resolve it under uploads.dir (e.g., ${uploads.dir}/f2b/eforms-f2b.log).
	- Uninstall: when install.uninstall.purge_logs=true, also delete the Fail2ban file and its rotated siblings.
	- Header name compare is case-insensitive.
	- Cap header length at ~1-2 KB before parsing to avoid pathological inputs.
	- Recommend `logging.mode="minimal"` in setup documentation to capture critical failures. Provide instructions for switching to `off` once the system is stable.
	- Initialize logging only when 'logging.mode != "off"'” could be read as disabling Fail2ban. Maybe clarify: Initialize JSONL/minimal logger only when logging.mode!='off'. Fail2ban emission is independent of logging.mode.
	- Element ID length cap: Cap generated IDs (e.g., "{form_id}-{field_key}-{instance_id}") at 128 characters. If longer, truncate the middle and append a stable 8-char base32 hash suffix to preserve uniqueness deterministically.
	- Permissions fallback: Create logs/uploads dirs with 0700 (files 0600). If strict perms fail, fall back once to 0750/0640 and emit a single warning (when logging is enabled). Keep deny rules regardless.
	- Cookie mode does not require JS
	
24. EMAIL TEMPLATES (REGISTRY)
        - Files live in /templates/email/{name}.txt.php and {name}.html.php
        - JSON "email_template": "foo" selects those files ("foo.html.php" when email.html=true); missing or unknown names raise an error
	- Template inputs:
		- form_id, instance_id, submitted_at (UTC ISO-8601)
		- fields (canonical values only, keyed by field key)
		- meta limited to { submitted_at, ip, form_id, instance_id }
		- uploads summary (attachments per Emailer policy)
	- Token expansion:
		- {{field.key}}, {{submitted_at}}, {{ip}}, {{form_id}}
	- Escaping:
		- text emails: plain text; CR/LF normalized
		- HTML emails: escape per context; no raw user HTML injected
	- Security hardening: template PHP files include ABSPATH guards (defined('ABSPATH') || exit;).

25. TEMPLATES TO INCLUDE
	1. quote_request.json
		{
			"id":"quote_request",
			"version":"1",
			"title":"Quote Request",
			"success":{"mode":"redirect","redirect_url":"/?page_id=15"},
			"email":{
				"to":"office@flooringartists.com",
				"subject":"Quote Request",
				"email_template":"default",
				"include_fields":["name","email","tel_us","zip_us","message","ip"],
				"display_format_tel":"xxx-xxx-xxxx"
			},
			"fields":[
				{"key":"name","type":"name","label":"Your Name","required":true,"placeholder":"Your Name","autocomplete":"name"},
				{"key":"email","type":"email","label":"Email","required":true,"placeholder":"your@email.com","autocomplete":"email"},
				{"type":"row_group","mode":"start","tag":"div","class":"columns_nomargins"},
				{"key":"tel_us","type":"tel_us","label":"Phone","required":true,"placeholder":"Phone","autocomplete":"tel"},
				{"key":"zip_us","type":"zip_us","label":"Zip","required":true,"placeholder":"Project Zip Code","autocomplete":"postal-code"},
				{"type":"row_group","mode":"end"},
				{"key":"message","type":"textarea","label":"Message","required":true}
			],
			"submit_button_text":"Send"
		}
	2. contact.json
		{
			"id":"contact_us",
			"version":"1",
			"title":"Contact Us",
			"success":{"mode":"inline","message":"Thanks! We got your message."},
			"email":{
				"to":"admin@example.com",
				"subject":"Contact Form",
				"email_template":"default",
				"include_fields":["name","email","message"]
			},
			"fields":[
				{"key":"name","type":"name","label":"Your Name","required":true,"before_html":"<h3>Hello,</h3>"},
				{"key":"message","type":"textarea","label":"Message","required":true,"placeholder":"And continue here ..."},
				{"key":"email","type":"email","label":"Email","autocomplete":"email","size":40,"required":true,"placeholder":"you@example.com"}
			],
			"submit_button_text":"Send Your Request"
		}
	3. eforms.css
		- Keep your existing CSS file as-is. Not reproduced here to keep this text plain.

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
		- EFORMS_ERR_HTML_TOO_LARGE - "This content is too long."
		- EFORMS_ERR_THROTTLED - "Please wait a moment and try again."
		- EFORMS_ERR_CHALLENGE_FAILED - "Please complete the verification and submit again."
		- EFORMS_CHALLENGE_UNCONFIGURED – Challenge required but provider keys are missing; treated as soft-signal only.
		- EFORMS_RESERVE - "Reservation outcome (info)."
		- EFORMS_LEDGER_IO - "Ledger I/O problem."
		- EFORMS_FAIL2BAN_IO - "Fail2ban file I/O problem."
		
	2. Accept Token -> MIME/Extension Map (canonical, conservative)
		- image -> image/jpeg, image/png, image/gif, image/webp (SVG excluded)
		- pdf -> application/pdf
		- Explicit exclusions by default: image/svg+xml, image/heic, image/heif, image/tiff
		- Policy: token set is intentionally minimal for v1 parity (image, pdf). Do not add tokens unless there's an explicit product requirement.
	3. Filename Policy (Display vs Storage)
		- Start with client name; strip paths; NFC normalize
		- sanitize_file_name(); remove control chars; collapse whitespace/dots
		- enforce single dot before extension; lowercase extension
		- block reserved Windows names (CON, PRN, AUX, NUL, COM1-COM9, LPT1-LPT9)
		- truncate to uploads.original_maxlen; fallback "file.{ext}" if empty
		- transliterate to ASCII when uploads.transliterate=true; else keep UTF-8 and use RFC 5987 filename*
		- de-dupe per email scope: "name.ext", "name (2).ext", ...
		- strip CR/LF from all filename strings before mailer
		- Storage name: {Ymd}/{original_slug}-{sha16}-{seq}.{ext}; never expose full paths
	4. Schema Source of Truth
		- PHP TEMPLATE_SPEC is authoritative at runtime
		- JSON Schema is documentation/CI lint only; enforce parity in CI

27. OPEN QUESTIONS (FOR FINALIZATION)
	- Tel formatting tokens: defined in 14; applies to tel_us email display only.
	- JSON Schema generation: either auto-generate from TEMPLATE_SPEC or assert parity in CI only (unchanged).
	- Default accept tokens: keep minimal ['image','pdf'] for v1 parity (unchanged).
	- CSRF protection derives from Origin, while tokens are for idempotency/dup-submit prevention—avoid admins thinking tokens defend CSRF.

28. PAST DECISION NOTES
- Use Origin as the single header check because it's the modern CSRF boundary and far less likely to be stripped than Referer. Privacy tools and corporate gateways commonly mangle/strip Referer; they rarely strip Origin.
- We can’t rely on a hidden token when pages are cached, and WordPress nonces bring their own complexity/expiry issues. hash_hmac() and is an overkill—especially if you aren’t embedding extra data (e.g., timestamps) inside the token. Can't rely on double-submit because it requires js.
- Old/locked-down clients may omit Origin on same-origin POST; your defaults (soft + missing=false) tolerate that, but the docs should warn that setting origin_mode=hard + origin_missing_hard=true can block those users.
