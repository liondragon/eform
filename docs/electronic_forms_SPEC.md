electronic_forms - Spec
================================================================

1. OBJECTIVE
  - Build a dependency-free, lightweight plugin that renders and processes multiple forms from JSON templates with strict DRY principles and a deterministic pipeline.
  - Internal use on 4-5 sites, ~40 submissions/day across forms, USA only. Not publicly marketed/distributed.
  - Targets publicly accessible contact forms without authenticated sessions. Cache-friendly and session-agnostic: WordPress nonces are never used.
  - No admin UI.
  - Focus on simplicity and efficiency; avoid overengineering. Easy to maintain and performant for intended use.
  - Lazy by design: the configuration snapshot is bootstrapped lazily on first access (Renderer/SubmitHandler/Emailer/Security) rather than at plugin load; modules initialize **only when their triggers occur** (see §6.5, §17).
  - No database writes; file-backed one-time token ledger for duplicate-submit prevention (no Redis/queues).
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
      - ships with conservative security defaults and minimal operational overhead; see §17 for the authoritative configuration table and exact default values.
    - Opt-ins (enable only if needed):
      - Throttling, adaptive/always challenge
      - Fail2ban emission
      - Rejected-submission logging → set logging.mode="jsonl" (or "minimal") and logging.level>=1
      - Header logging, PII logging, SMTP debug

3. ARCHITECTURE AND FILE LAYOUT
  - /electronic_forms/
    - eforms.php        // bootstrap + autoloader + shortcode/template tag
    - uninstall.php     // optional purge of uploads/logs (reads flags from Config; see 17)
    - uninstall.php requires __DIR__.'/src/Config.php' and calls Config::bootstrap() so it can read purge flags without relying on WP hooks.
      Hardening: uninstall.php must start with defined('WP_UNINSTALL_PLUGIN') || exit; and guard WP calls:
      if (!function_exists('wp_upload_dir')) { require_once ABSPATH.'wp-admin/includes/file.php'; }.
      If wp_upload_dir() still isn’t available, abort uninstall gracefully.
  - /src/
    - Config.php
    - Helpers.php         // tiny esc/url/id-name/fs utilities
    - Logging.php         // JSONL logger; rotation; masking
    - Email/
      - Emailer.php         // build & send; safe headers; text/plain by default
    - Rendering/
      - Renderer.php       // pure HTML; escape only at sinks
      - FormRenderer.php    // handles GET rendering; enqueues CSS/JS
    - Submission/
      - SubmitHandler.php   // handles POST submissions, PRG
    - Validation/
      - Normalizer.php      // normalization
      - Validator.php       // normalize -> validate -> coerce (deterministic)
      - TemplateValidator.php  // strict JSON structural preflight (unknown keys/enums/combos; accept[] intersection)
    - Security/
      - Security.php        // token, honeypot, min-fill-time, max-form-age
      - Throttle.php        // request throttling
      - Challenge.php       // optional challenge logic
    - Uploads/
      - Uploads.php         // normalize/validate/move uploads; enforce caps/allow-list; GC/retention; name/perms policy
    - No FormManager class; responsibilities split between FormRenderer and SubmitHandler
  - /schema/
    - template.schema.json  // design-time only (editor/CI lint); kept in sync with PHP spec
  - /templates/
    - forms/
      - contact.json        // kebab-case filenames only
    - email/
    - HARDENING: ship index.html and server deny rules (.htaccess, web.config) in this directory; enforce filename allow-list and prevent traversal outside /templates/.
  - /assets/
    - forms.css     // namespaced styles
    - forms.js      // JS marker (js_ok), error-summary/first-invalid focus, submit lock, spinner

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
      - key (slug), type, label?, placeholder?, required (bool), size (1-100; text-like only: text, tel, url, email), autocomplete?, options (for radios/checkboxes/select), class?, max_length?, min?, max?, step?, pattern?, before_html?, after_html?
    - key (slug): required; must match ^[a-z0-9_:-]{1,64}$ (lowercase); [] prohibited to prevent PHP array collisions; reserved keys remain disallowed.
    - autocomplete: exactly one token. "on"/"off" accepted; else must match WHATWG tokens (name, given-name, family-name, email, tel, postal-code, street-address, address-line1, address-line2, organization, …). Invalid tokens are dropped.
    - size: 1-100; honored only for text-like controls (text, tel, url, email).
    - Renderer injects security metadata per the active submission mode. See §7.1 for the canonical hidden-token and cookie-mode contract (required hidden fields, cookie/EID minting, rerender reuse rules, and POST expectations).
    - Form tag classes: <form class="eforms-form eforms-form-{form_id}"> (template id slug)
    - Renderer-generated attributes:
      - id = "{form_id}-{field_key}-{instance_id}" in hidden-mode and "{form_id}-{field_key}-s{slot}" in cookie-mode (slot defaults to 1).
      - name = "{form_id}[{field_key}]" or "{form_id}[{field_key}][]" for multivalue
    - Reserved field keys (templates must not use): form_id, instance_id, submission_id, eforms_token, eforms_hp, eforms_mode, eforms_slot, timestamp, js_ok, ip, submitted_at.
    - include_fields accepts template keys and meta keys:
      - allowed meta keys: ip, submitted_at, form_id, instance_id (hidden-mode only), submission_id, slot (available for email/logs only)
    - Template fragments (before_html / after_html):
      - Sanitized via wp_kses_post (same as textarea_html); sanitized result is canonical.
      - No inline styles. May not cross row_group boundaries.
    - Upload field options: for type=file/files, optional accept[], max_file_bytes, max_files (files only), email_attach (bool). Per-field values override global limits.
    - Attribute emission list (summary): maxlength, min, max, step, minlength, pattern, inputmode, multiple, accept are emitted when applicable from the template/registry traits.
    - Client-side attribute mirroring (UX hints only): Renderer mirrors server limits as HTML attributes—max_length -> maxlength, min/max/step for numeric/date, min_length -> minlength (future). These never relax server rules; server validation is authoritative.
    - Typing/editing aids: Renderer emits inputmode, pattern (hints only), and editing helpers per field type (see 11).
    - Uploads -> HTML attributes: image token => accept="image/jpeg,image/png,image/gif,image/webp" (do not use image/*); pdf => application/pdf.
    - Enter key UX: Renderer sets enterkeyhint="send" on the last text-like control or <textarea> in DOM order. Best-effort only; no effect on validation/submission flow. The required attribute is driven strictly by template required: true|false.

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
            validator_id: string,   // e.g., "email"
            normalizer_id: string,  // e.g., "email"
            renderer_id: string     // e.g., "email"
          },
          constants: { ... },       // per-type constants mirrored to DOM (e.g., spellcheck=false)
          alias_of?: string         // explicit alias target type name when applicable
        }
      - Handler IDs are short tokens scoped to each registry (e.g., "email", "text"). IDs are resolved to callables once during preflight via per-class private registries (see §6).
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

6. CENTRAL REGISTRIES (INTERNAL ONLY)
  - Static registries (no public filters): field_types, validators, normalizers/coercers, renderers.
  - Registries are private to each owning class and exposed only through resolve() helpers.
    - Example:
      - Validator: private const HANDLERS = ['email' => [self::class,'validateEmail'], ...]
      - Normalizer: private const HANDLERS = ['scalar' => [self::class,'normalizeScalar'], ...]
      - Renderer: private const HANDLERS = ['text' => [self::class,'emitInput'], 'textarea' => [...], ...]
      - public static function resolve(string $id): callable { if (!isset(self::HANDLERS[$id])) throw RuntimeException(...); return self::HANDLERS[$id]; }
  - Uploads registry settings: token->mime/ext expansions; image sanity; caps
  - Accept token map (canonical, conservative). For v1 parity, only tokens are image and pdf; do not add unless explicitly required.
  - Upload registry loads on demand when a template with file/files is rendered or posted.
  - Structural registry (TEMPLATE_SPEC) defines allowed keys, required combos, enums (implements additionalProperties:false).
  - Escaping map (per sink) to be used consistently:
    - HTML text -> esc_html
    - HTML attribute -> esc_attr
    - Textarea -> esc_textarea
    - URL (render) -> esc_url
    - URL (storage/transport) -> esc_url_raw
    - JSON/logs -> wp_json_encode
  - Challenge and Throttle modules are loaded only when needed. Initialize the challenge module when (a) challenge.mode != "off", or (b) security.cookie_missing_policy == "challenge", or (c) a POST sets Security::token_validate().require_challenge === true. No classes, hooks, or assets are registered otherwise.
  - Lazy registries vs autoloading (clarification):
	  - Autoloading a class is considered "lazy enough" for static registries: the PHP file is loaded only when the class is first referenced. Merely defining `private const HANDLERS` does not initialize any heavy state.
	  - Derived maps/caches (e.g., resolved descriptor caches) are computed on first use (TemplateValidator preflight / Validator path) and memoized per request.
	  - No global scans or runtime plugin discovery occurs; resolution is O(1) lookups into those const arrays.
7. SECURITY
  1. Submission Protection for Public Forms (hidden vs cookie)
    - Mode selection stays server-owned: `[eform id=\"slug\" cacheable=\"false\"]` (default) renders in hidden-token mode; `cacheable=\"true\"` renders in cookie mode. All markup carries `eforms_mode`, and the renderer never gives the client a way to pick its own mode.
    - Canonical pipeline (render → persist → POST → rerender) shared by renderer, submit handler, and QA:
      1. **Render (GET)**
         - Both modes inject `form_id`, `eforms_mode`, the fixed honeypot `eforms_hp`, and the static hidden `js_ok`. Responses include CSS/JS enqueueing decisions and caching headers per §19. `eforms_mode` is informational only; the persisted record (see below) is the enforcement authority.
         - Hidden-mode additionally emits a CSPRNG `instance_id` (16–24 bytes → base64url → `^[A-Za-z0-9_-]{22,32}$`), a `timestamp` snapshot, and `<input type=\"hidden\" name=\"eforms_token\" value=\"…\">` where the raw UUID matches `/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i/`. The renderer persists that raw token server-side and must reuse the exact `{token, instance_id, timestamp}` trio on every error rerender until a new token is minted. Hidden responses send `Cache-Control: private, no-store`.
         - Cookie-mode renders remain deterministic: they omit `instance_id`, timestamps, and hidden tokens. Multi-instance pages MAY emit a deterministic integer `eforms_slot` (default `1`; slots require a configured allow-list). When slots are configured, each instance emits its own deterministic 1×1 `<img src=\"/eforms/prime?f={form_id}&s={slot}\" aria-hidden=\"true\" alt=\"\" width=\"1\" height=\"1\">` probe; slotless forms continue to send a single `/eforms/prime?f={form_id}` beacon and MUST NOT append `s`. The pixel keeps screen readers from announcing it and layout engines honor the fixed size while `/eforms/prime` returns `204` with `Cache-Control: no-store`. It mints `Set-Cookie: eforms_eid_{form_id}=i-<UUIDv4>; HttpOnly; SameSite=Lax; Path=/; Max-Age=security.token_ttl_seconds; [Secure on HTTPS]` only when the request either lacks a matching cookie or presents one that is expired or has no surviving minted record. `/eforms/prime` MUST load the minted record before skipping `Set-Cookie`; a missing, truncated, or expired record is treated as stale and triggers a fresh mint so implementations never "adopt" a forged or orphaned cookie. When the cookie remains valid and the record loads cleanly, `/eforms/prime` omits `Set-Cookie` and only updates the minted record (unioning the slot into `slots_allowed`). Cookie values must match `/^i-[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i/`. Hidden renders (and unknown IDs) still return 204 but without `Set-Cookie`.
      2. **Persisted records (sole authority for mode + freshness)**
         - Hidden-mode GETs create `${uploads.dir}/eforms-private/tokens/{h2}/{sha256(token)}.json` with `{ mode:\"hidden\", form_id:\"…\", issued_at:<ts>, expires:<ts> }`. The filename already contains the SHA-256; no duplicate value is stored in the JSON.
         - Cookie-mode minting via `/eforms/prime` writes `${uploads.dir}/eforms-private/eid_minted/{form_id}/{h2}/{eid}.json` (no colons) holding `{ mode:\"cookie\", form_id:\"…\", eid:\"i-<UUIDv4>\", issued_at:<ts>, expires:<ts>, slots_allowed:[...], slot:null|int }`. `/eforms/prime` parses the provided `s` query parameter as a base-10 integer, clamps it to `1–255`, and drops the value when parsing fails or when the slot is not in `security.cookie_mode_slots_allowed` (treat it as null so the request neither overrides nor expands policy). Valid slots are persisted as `slot` (null when omitted) and appended to `slots_allowed` idempotently (skip the push when `slot` is null). Slotless deployments MUST NOT emit an `s` parameter so the minted record stays `{ slot:null, slots_allowed:[] }`. CI enforces `expires - issued_at == security.token_ttl_seconds` for the minted JSON payload. `/eforms/prime` MUST update the JSON atomically (write temp + rename or use `flock()` + fsync) so simultaneous probes cannot clobber `slots_allowed`. See §7.1.3 for the POST enforcement path that consumes these records.
         - Sanity regexes (`/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i/` for hidden tokens, `/^i-[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i/` for cookie EIDs) run before disk access to weed out obvious forgeries but never determine the mode. SubmitHandler always loads the persisted record before any ledger I/O; missing/expired/mismatched records trigger `EFORMS_ERR_TOKEN`.
      3. **POST `/eforms/submit`**
        - HTTP contract: Requests MUST use HTTP POST with `Content-Type: application/x-www-form-urlencoded` (any charset) or `multipart/form-data` only. Other methods receive 405 and other media types receive 415; payload caps remain governed by §7.5.
         - After the CSRF/origin gate (§7.4) and method/type checks (§7.5), hidden-mode POSTs must supply `eforms_token` matching the hidden regex. The handler hashes it, loads the hidden record, enforces `{mode:\"hidden\", form_id}` parity, and applies the TTL. Missing/invalid tokens obey `security.submission_token.required` (true → hard fail; false → soft signal) and cookies are ignored.
         - Cookie-mode POSTs omit `eforms_token` and instead read `eforms_eid_{form_id}`. The cookie must match the EID regex before the minted record is consulted. The record must still say `{mode:\"cookie\"}` with the same `{form_id, eid}` and be unexpired; absent cookies route through `security.cookie_missing_policy` (`off`/`soft`/`hard`/`challenge`). Posting a hidden token when the record says cookie (or vice versa) is treated as tampering.
         - Slot enforcement (cookie mode only): parse `eforms_slot` as an integer (default `1`); require it to appear in `security.cookie_mode_slots_allowed` when configured and to match the minted record. Slotless minted records expect `slot:null` with an empty `slots_allowed` and reject any posted slot, whereas bound records require the POSTed slot to appear in `slots_allowed` (and equal the canonical `slot` when present). A mismatch hard-fails with `EFORMS_ERR_TOKEN`. The resulting `submission_id` becomes `${eid}` or `${eid}__slot{slot}` (double underscore keeps filenames Windows-safe).
        - Duplicate suppression uses `${uploads.dir}/eforms-private/ledger/{form_id}/{h2}/{submission_id}.used`. Reserve the sentinel via an exclusive-create call (`fopen('xb')` or equivalent) with 0700 directory / 0600 file perms immediately before side effects (email send, file finalize). Hidden tokens and cookie EIDs (with optional `__slot{n}` suffix) must resolve to colon-free `submission_id` values in both modes to keep filenames portable. Treat `EEXIST` as a duplicate submission; any other filesystem failure while reserving the sentinel also counts as a duplicate and must emit an `EFORMS_LEDGER_IO` log entry for ops review. Honeypot short-circuits burn the same ledger entry.
         - Validation exposes `{ mode:\"hidden\"|\"cookie\", submission_id:\"…\", slot?:int, token_ok:bool, hard_fail:bool, soft_signal:0|1, require_challenge:bool }`. Hidden mode reports the raw token; cookie mode reports the EID (plus slot suffix). Renderer and downstream components (logging, throttling, success tickets) consume this structure.
      4. **Rerender and rotation rules**
         - The mode chosen on the initial render never changes mid-flow. Hidden-mode errors must reuse the original `{token, instance_id, timestamp}`; a new token is minted only after a successful ledger reservation (or when explicitly cleared following fatal errors). Cookie-mode rerenders reuse the minted `{eid, slot}` pair until expiry or success; `/eforms/prime` is the only minting path and no mid-flow rotation occurs.
         - Hard failures present `EFORMS_ERR_TOKEN` (“This form was already submitted or has expired - please reload the page.”). Soft paths retain the same authoritative records so repeated attempts stay deterministic.


  2. Honeypot
    - Runs after CSRF gate; never overrides a CSRF hard fail.
    - Stealth logging: JSONL { code:"EFORMS_ERR_HONEYPOT", severity:"warning", meta:{ stealth:true } }, header X-EForms-Stealth: 1. Do not emit "success" info log.
    - Field: eforms_hp (fixed POST name). Hidden-mode ids incorporate the per-instance suffix; cookie-mode ids are deterministic `"{form_id}-hp-s{slot}"`. Must be empty. Submitted value discarded and never logged.
    - Config: security.honeypot_response: "hard_fail" | "stealth_success" (default stealth_success).
    - Common behavior: treat as spam-certain; short-circuit before validation/coercion/email; delete temp uploads; record throttle signal; attempt ledger reservation to burn the ledger entry for that `submission_id`; no cookie rotation occurs.
    - "stealth_success": mimic success UX (inline PRG cookie + 303, or redirect); do not count as real successes (log stealth:true).
    - "hard_fail": re-render with generic global error (HTTP 200); no field-level hints.

  3. Timing Checks
    - min_fill_time default 4s (soft; configurable). Hidden-mode measures from the original hidden timestamp (reused on re-render). Cookie-mode measures from the minted record’s `issued_at` (prime pixel time) and ignores client timestamps entirely.
    - Max form age:
      - Cookie mode: enforce via minted record `expires`. Expired → treat as missing cookie and apply `security.cookie_missing_policy`.
      - Hidden-mode: posted timestamp is best-effort; over `security.max_form_age_seconds` → +1 soft (never hard on age alone).
    - js_ok flips to "1" on DOM Ready (soft unless `security.js_hard_mode=true`, then HARD FAIL). Cookie-mode markup keeps the field static; only the value toggles via JS.

  4. Headers (Origin policy)
    - Normalize + truncate UA to printable chars; cap length security.ua_maxlen.
    - Origin check: normalize to scheme+host+effective port (80/443 normalized; non-default ports significant). origin_state = same | cross | unknown | missing.
    - Policy (security.origin_mode): off (no signal), soft (default), hard (hard fail on cross/unknown; missing depends on origin_missing_hard).
    - Log only origin_state (no Referrer). Referrer is not consulted.
    - Security::origin_evaluate() returns {state, hard_fail, soft_signal}.
    - Operational guidance: Only enable origin_mode=hard + origin_missing_hard=true after validating your environment (some older agents omit Origin). Provide a tiny WP-CLI smoke test that POSTs without Origin to verify behavior.

  5. POST Size Cap (authoritative)
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
      - Missing/expired hidden record → HARD FAIL when `security.submission_token.required=true`; SOFT signal when false.
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
      - Missing success ticket (cookie only) → suppress banner; log soft signal.
      - Success ticket re-use after verifier burn → HARD FAIL / no banner.
    - Determinism checks:
      - Hidden-mode error rerender reuses original `instance_id`, `timestamp`, and hidden token.
      - Cookie-mode rerender emits identical markup (no new randomness) and reuses the minted `eid` and slot.
      - Renderer id/name attributes stable per descriptor; attr mirror parity holds.
  6. Test/QA Matrix (v4.4 mandatory)
    - Hidden-mode checks:
      - Omit or alter the hidden token with `security.submission_token.required=true` → reject with `EFORMS_ERR_TOKEN` hard fail.
      - Expire or delete the hidden record with `security.submission_token.required=false` → accept submission path but emit a soft signal (no `EFORMS_ERR_TOKEN`).
      - Replay a burned hidden token after ledger reservation exists → hard fail on `EFORMS_ERR_TOKEN`.
    - Cookie-mode checks:
      - Submit with no minted record on disk → hard fail on `EFORMS_ERR_TOKEN`.
      - Present mismatched `form_id`/`eid` metadata or mix in a hidden token → hard fail on `EFORMS_ERR_TOKEN`.
      - Drop the cookie and rely on `security.cookie_missing_policy="soft"` → continue submission flow and log the soft signal; `"hard"` or `"challenge"` must block (hard fail) per policy.
      - Post a slot outside `cookie_mode_slots_allowed` → hard fail on `EFORMS_ERR_TOKEN`.
    - Honeypot checks:
      - Fill `eforms_hp` with `security.honeypot_response="stealth_success"` → mimic success UX, burn the ledger entry, and log `stealth:true` (treated as soft fail for QA).
      - Fill `eforms_hp` with `security.honeypot_response="hard_fail"` → hard fail with the generic global error.
    - Success-ticket checks:
      - Valid ticket + matching cookie → banner renders once, clears state (pass condition).
      - Missing ticket while cookie present → suppress banner and log soft signal.
      - Replay ticket after verifier burns it → hard fail / no banner.
    - Determinism checks:
      - Hidden-mode rerender after validation errors reuses the original `instance_id`, `timestamp`, and hidden token (diff → hard fail).
      - Cookie-mode rerender emits identical markup and reuses the minted `eid` and slot (diff → hard fail).
    - TTL-alignment checks:
      - Minted record JSON stores `expires - issued_at == security.token_ttl_seconds`; drift → hard fail in CI.
      - Hidden record JSON stores `expires - issued_at == security.token_ttl_seconds`; drift → hard fail in CI.
      - Success ticket expiry respects `security.success_ticket_ttl_seconds` and cleans up on expiry; drift → hard fail in CI.

  7. Spam Decision
    - Hard checks first: honeypot_empty and token/Origin hard fails (and hard throttle). Any hard fail stops processing.
    - Soft signals (+1 each unless policy says otherwise): min_fill_ok=false; js_ok!="1" (unless js_hard_mode=true → hard); missing UA; age_ok=false (hidden-token mode advisory); origin_soft_signal; token soft; throttle over-limit soft.
    - cookie_missing_policy='challenge' and verification success clears soft signals (does not override hard failures).
    - Decision: soft_fail_count >= spam.soft_fail_threshold → spam-fail; ==1 → deliver as suspect; ==0 → deliver normal.
    - Accessibility note: js_hard_mode=true blocks non-JS users; keep opt-in.

  8. Redirect Safety
    - wp_safe_redirect; same-origin only (scheme/host/port).

  9. Suspect Handling
    - add headers: X-EForms-Soft-Fails, X-EForms-Suspect; subject tag (configurable)

 10. Throttling (optional; file-based)
    - As previously specified: fixed 60s window, small JSON file, flock; soft over-limit adds +1; hard over-limit = HARD FAIL.
    - Key derivation respects privacy.ip_mode; storage path ${uploads.dir}/throttle/{h2}/{key}.json; GC files >2 days old.

  11. Adaptive challenge (optional; Turnstile preferred)
    - Modes: off | auto (require when soft_fail_count>=1) | always
    - Providers: turnstile | hcaptcha | recaptcha v2. Verify via WP HTTP API (short timeouts). Unconfigured required challenge adds +1 soft and logs EFORMS_CHALLENGE_UNCONFIGURED.
    - Render only on POST re-render when required (or always); never on initial GET unless §7.1 requires challenge.
    - Turnstile → cf-turnstile-response; hCaptcha → h-captcha-response; reCAPTCHA v2 → g-recaptcha-response.

8. VALIDATION & SANITIZATION PIPELINE (DETERMINISTIC)
  0. Structural preflight (stop on error; no field processing)
    - Unknown keys rejected at every level (root/email/success/field/rule).
    - fields[].key must be unique; duplicates → EFORMS_ERR_SCHEMA_DUP_KEY.
    - Enum enforcement (field.type, rule.rule, row_group.mode, row_group.tag).
    - Conditional requirements (redirect mode requires redirect_url; files must have max_files>=1 if present; row_group must omit key).
    - accept[] ∩ global allow-list must be non-empty; else EFORMS_ERR_ACCEPT_EMPTY.
    - Row-group object shape must match spec; mis-shapes → EFORMS_ERR_SCHEMA_OBJECT.
    - Handler resolution: resolve all handler IDs to callables; unknown → deterministic RuntimeException (caught → config error).

  1. Security gate (hard/soft signals; stop on hard failure)

  2. Normalize (lossless)
    - Apply wp_unslash and trim; Helpers::nfc for Unicode NFC (no-op without intl).
    - Flatten $_FILES; shape items as { tmp_name, original_name, size, error, original_name_safe }.
    - Treat UPLOAD_ERR_NO_FILE or empty original_name as "no value".
    - Scalar vs array:
      - Do not reject here. If a single-value field received an array, retain array for Validate to reject deterministically.
    - No rejection allowed in Normalize.

  3. Validate (authoritative; may reject)
    - Check required, length/pattern/range, allow-lists, cross-field rules (see §10).
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

  6. Escape at sinks only (per map in §6)

9. SPECIAL CASE: HTML-BEARING FIELDS
  - textarea_html and template fragments (before_html / after_html)
  - textarea_html: size bound via validation.textarea_html_max_bytes (default 32768 bytes)
  - Sanitize with wp_kses_post; sanitized result is canonical; escape per sink.
  - textarea_html: post-sanitize bound – after wp_kses_post, re-check canonical size; if > max, fail with EFORMS_ERR_HTML_TOO_LARGE (no auto-truncate).

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

11. BUILT-IN FIELD TYPES (DEFAULTS; US-FOCUSED)
  - Spec::descriptorFor($type) exposes a descriptor for each field type:
    - is_multivalue: bool
    - html { tag:"input|textarea|select", type?, multiple?, inputmode?, pattern?, attrs_mirror:[ maxlength?, minlength?, min?, max?, step? ] }
    - validate { required?, pattern?, range?, canonicalize? }
    - handlers { validator_id, normalizer_id, renderer_id }   // short tokens, e.g., "email"
    - constants { ... }    // e.g., email: spellcheck=false, autocapitalize=off
    - alias_of?  // explicit alias target if applicable
  - name / first_name / last_name: aliases of text; trim internal multiples; default autocomplete accordingly.
  - text: length/charset/regex
  - textarea: length/charset/regex
  - textarea_html: see §9; mirror maxlength/minlength when provided.
  - email: type="email", inputmode="email", spellcheck="false", autocapitalize="off"; mirror maxlength/minlength.
  - url: wp_http_validate_url + allowed schemes (http, https). type="url", spellcheck="false", autocapitalize="off".
  - tel_us: NANP; digits-only canonical 10 digits; optional +1 stripped; no extensions. type="tel", inputmode="tel"; mirror maxlength.
  - tel (generic): freeform; trimmed.
  - number / range: native input types; inputmode="decimal"; mirror min/max/step exactly as validated server-side.
  - select / radio: store option key
  - checkbox: single -> bool; group -> array of keys
  - zip_us: type="text", inputmode="numeric", pattern="\\d{5}" (hint only); always set maxlength=5; server enforces ^\d{5}$.
  - zip (generic): freeform
  - file: single upload. Accept tokens map:
    - image → image/jpeg,image/png,image/gif,image/webp
    - pdf   → application/pdf
  - files: multiple upload with max_files; same explicit lists; email attachment policy unchanged (§14).
  - date: mirror min/max and step when provided.
  - For each field, the HTML attributes emitted (inputmode, pattern, multiple, accept, etc.) must match attr_mirror derived from the resolved descriptor.
  - Resolved descriptor cache per request:
    - Include name_tpl and id_prefix to avoid recomputing; reuse in Renderer + Validator.

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

13. SUCCESS BEHAVIOR (PRG)
  - inline: PRG (303) to same URL with `eforms_success={form_id}`. Renderer shows success only in the first instance in source order when multiple same-ID instances exist; suppress in subsequent instances.
  - redirect: `wp_safe_redirect(redirect_url, 303)`; no flag on destination. Cookie-mode deployments SHOULD prefer `success.mode="redirect"` pointing at a non-cached endpoint per v4.4 guidance.
  - Fallback UX: when a redirect target is impossible (e.g., static cached page without a non-cached handoff), continue to use inline success on cached pages as the graceful fallback.
  - PRG status: fixed at 303.
  - Caching: do not disable page caching globally. Only vary/bypass for (a) the short-lived success cookie `eforms_s_{form_id}` and (b) requests containing `eforms_*` query args.
  - Success responses MUST send: `Cache-Control: private, no-store, max-age=0` and SHOULD include `Vary: Cookie` scoped to `eforms_s_{form_id}`.
  - Any request containing `eforms_*` query args MUST send: `Cache-Control: private, no-store, max-age=0`.
  - Namespace internal query args with `eforms_*`.
  - `success.message` is treated as plain text and escaped.
  - Anti-spoofing (inline mode only):
    1. On successful POST, create a one-time success ticket `${uploads.dir}/eforms-private/success/{form_id}/{h2}/{submission_id}.json` (short TTL, e.g., 5 minutes) containing `{ form_id, submission_id, issued_at }`. `submission_id` matches the ledger naming scheme (e.g., `eid__slot2` when slots are enabled) so the ticket filenames remain colon-free and Windows-compatible. Set `eforms_s_{form_id}={submission_id}` (`SameSite=Lax`, HttpOnly=false, `Secure` on HTTPS, `Path`=current request path, `Max-Age≈300`).
    2. Redirect with `?eforms_success={form_id}`.
    3. Cached page loads a lightweight verifier that calls `/eforms/success-verify?f={form_id}&s={submission_id}` (`Cache-Control: no-store`). Render the success banner only when both the query flag and verifier response succeed. A successful verifier response MUST immediately invalidate the ticket so any subsequent verify call for the same `{form_id, submission_id}` pair returns false. Then clear the cookie and strip the query parameter. This prevents replaying old cookie/query combinations on cached pages.
  - Inline success MUST NOT rely solely on a bare `eforms_s_{form_id}=1` cookie; always pair it with the ticket verifier to prevent spoofing. Logs and downstream consumers MUST treat `submission_id` values as colon-free strings and rely on the separate `slot` metadata when disambiguating multi-instance submissions.

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
    - Timestamp (UTC ISO-8601), severity, code, form_id, submission_id, slot? (when provided), request URI (path + only `eforms_*` query), privacy-processed IP, spam signals summary (honeypot, origin_state, soft_fail_count, throttle_state), SMTP failure reason when applicable.
    - Token evaluation mode (meta.mode) when the submission gate runs, to differentiate hidden-token vs cookie flows.
    - Cookie consultation boolean (meta.cookie_consulted): true iff the cookie path was evaluated (cookie-mode); false in hidden-mode. Lets tests assert that cookies were never read when a hidden token was posted.

    - Optional on failure: canonical field names + values only for fields causing rejection when logging.on_failure_canonical=true.
    - Throttle & challenge outcomes at level >=1 (redact provider tokens).
    - At level=2, include a compact descriptor fingerprint for this request: desc_sha1 = sha1(json_encode(resolved descriptors)). Optionally include a compact spam bitset alongside the human list.
  - Minimal mode line format
    - eforms severity=<error|warning|info> code=<EFORMS_*|PHPMailer> form=<form_id> subm=<submission_id> ip=<masked|hash|full|none> uri="<path?eforms_*...>" msg="<short>" meta=<compact JSON>
  - Fail2ban (optional; independent of logging.mode; controlled by logging.fail2ban.*)
    - Emit single-line: eforms[f2b] ts=<unix> code=<EFORMS_ERR_*> ip=<resolved_client_ip> form=<form_id>
    - Uses resolved client IP per §16 (ignores privacy.ip_mode). Rotation/retention similar to JSONL when target=file.
  - Implementation notes:
    - Initialize JSONL/minimal logger only when logging.mode!='off'. Fail2ban emission is independent.

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

17. CONFIGURATION (SUMMARY)
  - Authoritative defaults: the table below defines the canonical config paths and their default values; other sections should reference this summary instead of re-listing values.
  - Bootstrap timing (lazy & idempotent):
		- `Config::bootstrap()` is not run at plugin load. It is invoked lazily on first access from any public entry point that requires configuration:
			- `Config::get()`; `FormRenderer::render()`; `SubmitHandler::handle()`; `Security::token_validate()`; `Emailer::send()`; prime/success endpoints.
		- Implementations SHOULD use a cheap guard, e.g.:
			- `if (!Config::isBootstrapped()) { Config::bootstrap(); }`
		- Within a single request, bootstrapping is idempotent and performed at most once. There is **no cross-request persistence** beyond WordPress options/hooks; this keeps behavior deterministic.
		- Rationale: deferring bootstrap shortens plugin load time, avoids work on pages without forms, and respects the “lazy by default” objective.
		- Special cases: `uninstall.php` invokes `Config::bootstrap()` synchronously to read purge flags; WP-CLI commands that operate standalone MAY call `Config::forceBootstrap()` early.
	- Immutable per-request Config snapshot:
		- `Config::bootstrap()` loads defaults (nested array mirroring §17), applies a single `eforms_config` filter once, validates/clamps types/ranges/enums, then freezes.
	- Access via `Config::get('path.like.this')`.
  - Keys (examples, all below are config paths):
    - security.origin_mode: off | soft | hard (default soft)

security.*
  security.token_ledger.enable (bool; default true)
  security.token_ttl_seconds (int; default 600)
  security.submission_token.required (bool; default true)
  security.origin_mode (off|soft|hard; default soft)
  security.origin_missing_soft (bool; default false)
  security.origin_missing_hard (bool; default false)
  security.min_fill_seconds (int; default 4; clamp 0-60)
  security.max_form_age_seconds (derived from token_ttl_seconds)
  security.js_hard_mode (bool; default false)
  security.max_post_bytes (int; default 25_000_000)
  security.ua_maxlen (int; default 256)
  security.honeypot_response ("hard_fail"|"stealth_success"; default "stealth_success")
  security.cookie_missing_policy ("off"|"soft"|"hard"|"challenge"; default "soft")
  security.cookie_mode_slots_enabled (bool; default false)
  security.cookie_mode_slots_allowed (array<int>; default []; slots normalized to 1-255; honored only when cookie_mode_slots_enabled)
  security.success_ticket_ttl_seconds (int; default 300; clamp 30-3600)

spam.*
  spam.soft_fail_threshold (int; default 2; clamp 0-5)

throttle.*
  throttle.enable (bool; default false)
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
  html5.client_validation (bool; default false)

email.*
  email.policy (strict|autocorrect; default strict)
  email.smtp.timeout_seconds (int; default 10)
  email.smtp.max_retries (int; default 2)
  email.smtp.retry_backoff_seconds (int; default 2)
  email.html (bool; default false)
  email.from_address (validated same-domain email)
  email.from_name (sanitized text)
  email.reply_to_field (field key; optional)
  email.envelope_sender
  email.dkim.domain / selector / private_key_path / pass_phrase (optional; all valid to enable)
  email.disable_send (bool; default false)
  email.staging_redirect_to (string|array; overrides all recipients)
  email.suspect_subject_tag (string; default [SUSPECT])
  email.upload_max_attachments (int; default 5)
  email.debug.enable (bool; default false)
  email.debug.max_bytes (int; default 8192)

logging.*
  logging.mode ("jsonl"|"minimal"|"off"; default "minimal")
  logging.level (0|1|2; default 0)
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
  validation.max_items_per_multivalue (int; default 50)
  validation.textarea_html_max_bytes (int; default 32768)

uploads.*
  uploads.enable (bool; default true)
  uploads.dir (path; defaults to wp_upload_dir()['basedir'].'/eforms-private')
  uploads.allowed_tokens (array; default [image, pdf])
  uploads.allowed_mime (array; conservative; intersect WP allowed)
  uploads.allowed_ext (array; derived, lowercase)
  uploads.max_file_bytes (int; default 5_000_000)
  uploads.max_files (int; default 10)
  uploads.total_field_bytes (int; default 10_000_000)
  uploads.total_request_bytes (int; default 20_000_000)
  uploads.max_email_bytes (int; default 10_000_000)
  uploads.delete_after_send (bool; default true)
  uploads.retention_seconds (int; default 86400)
  uploads.max_image_px (int; default 50_000_000) // width*height guard
  uploads.original_maxlen (int; default 100)
  uploads.transliterate (bool; default true)
  uploads.max_relative_path_chars (int; default 180)
  // sha16 is the first 16 hex chars of file’s SHA-256; full SHA recorded in logs

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

19. REQUEST LIFECYCLE
  1. GET
    - Shortcode `[eform id="slug" cacheable="true|false"]` (`cacheable` defaults to `false`).
    - Template tag `eform_render('slug', ['cacheable' => true|false])` (`cacheable` defaults to `false`).
    - `cacheable=false` forces hidden-mode; `cacheable=true` uses cookie-mode.
    - FormRenderer loads the template and injects the appropriate hidden-token or cookie metadata per §7.1.
    - Registers/enqueues CSS/JS only when rendering
    - Always set method="post". If any upload field present, add enctype="multipart/form-data".
    - Opportunistic GC may run (no WP-Cron).
    - Max-input-vars heuristic: log advisory and (when WP_DEBUG) emit an HTML comment near the form.
    - CDN/cache notes: bypass caching on non-cacheable token pages; /eforms/prime is no-store; do not strip Set-Cookie on 204.
    - Initialize Logging only when logging.mode != "off".
    - Initialize Uploads only when uploads.enable=true and template declares file/files (detected at preflight).
    - html5.client_validation=true → omit novalidate; server validator still runs on POST.
    - Preflight resolves and freezes per-request resolved descriptors; reuse across Renderer and Validator (no re-merge on POST).

  2. POST
    - SubmitHandler orchestrates Security gate -> Normalize -> Validate -> Coerce
    - Mode, hidden-field reuse, and rerender behavior follow the canonical contract in §7.1; lifecycle logic never swaps modes mid-flow.
    - Early enforce RuntimeCap using CONTENT_LENGTH when present; else rely on PHP INI limits and post-facto caps.
    - Error rerenders, ledger reservation timing, and duplicate handling are governed by §7.1; reserve `${uploads.dir}/eforms-private/ledger/{form_id}/{h2}/{submission_id}.used` via an exclusive-create call (`fopen('xb')` or equivalent) with 0700 directory / 0600 file perms immediately before side effects. Both hidden-token and cookie-mode submissions must resolve colon-free `submission_id` values; treat `EEXIST` as a duplicate and log `EFORMS_LEDGER_IO` on any other filesystem failure while also treating the submission as a duplicate. POST lifecycle code simply orchestrates normalization/validation/email/logging around that contract.
    - On success: move stored uploads; send email; log; PRG/redirect; cleanup per retention.
    - Best-effort GC on shutdown; no persistence of validation errors/canonical values beyond request.
    - throttle.enable=true and key available → run throttle; over → +1 soft and add Retry-After; hard → HARD FAIL (skip side effects).
    - Challenge hook: if required (always/auto or cookie policy), verify; success clears soft signals (not hard failures).

20. ERROR HANDLING
  - Errors stored by field_key; global errors under _global
  - Renderer prints global summary + per-field messages
  - Upload user-facing messages:
    - "This file exceeds the size limit."
    - "Too many files."
    - "This file type isn't allowed."
    - "File upload failed. Please try again."
  - Re-render after errors passes the mode-specific security metadata defined in §7.1 back to Renderer (hidden: token/instance/timestamp; cookie: `{eid, slot}`).
  - Emit stable error codes (e.g., EFORMS_ERR_TOKEN, EFORMS_ERR_HONEYPOT, EFORMS_ERR_TYPE, EFORMS_ERR_ACCEPT_EMPTY, EFORMS_ERR_ROW_GROUP_UNBALANCED, EFORMS_ERR_SCHEMA_UNKNOWN_KEY, EFORMS_ERR_SCHEMA_ENUM, EFORMS_ERR_SCHEMA_REQUIRED, EFORMS_ERR_SCHEMA_TYPE, EFORMS_ERR_SCHEMA_OBJECT, EFORMS_ERR_UPLOAD_TYPE, EFORMS_ERR_HTML_TOO_LARGE).
  - Large form advisory via logs and optional HTML comment (WP_DEBUG only).
  - "This content is too long." maps to EFORMS_ERR_HTML_TOO_LARGE.
  - "This form was already submitted or has expired - please reload the page." maps to EFORMS_ERR_TOKEN.

21. COMPATIBILITY AND UPDATES
  - Changing type defaults or rules updates behavior globally via registry
  - Templates remain portable (no callbacks)
  - Minimum versions: PHP >= 8.0; WordPress >= 5.8 (admin notice + deactivate if unmet)
  - Terminology: use allow-list/deny-list consistently.

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
  - Do not load challenge script on initial GET unless required by §7.1.
  - Secrets hygiene: Render only site_key to HTML. Never expose secret_key or verify tokens in markup/JS. Verify server-side; redact tokens in logs.
  - Keep novalidate logic unchanged.

23. NOTES FOR IMPLEMENTATION
  - instance_id: cryptographically secure random (16–24 bytes) encoded per §5.1 (base64url without padding; matches `^[A-Za-z0-9_-]{22,32}$`)
  - timestamp: server epoch seconds at render time
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

25. TEMPLATES TO INCLUDE
  1. forms/quote-request.json
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
  2. forms/contact.json
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
    - EFORMS_ERR_UPLOAD_TYPE - "This file type isn't allowed."
    - EFORMS_ERR_HTML_TOO_LARGE - "This content is too large."
    - EFORMS_ERR_THROTTLED - "Please wait a moment and try again."
    - EFORMS_ERR_CHALLENGE_FAILED - "Please complete the verification and submit again."
    - EFORMS_CHALLENGE_UNCONFIGURED – "Verification unavailable; please try again."
    - EFORMS_RESERVE - "Reservation outcome (info)."
    - EFORMS_LEDGER_IO - "Ledger I/O problem."
    - EFORMS_FAIL2BAN_IO - "Fail2ban file I/O problem."
    - EFORMS_FINFO_UNAVAILABLE - "File uploads are unsupported on this server."

  2. Accept Token -> MIME/Extension Map (canonical, conservative)
    - image -> image/jpeg, image/png, image/gif, image/webp (SVG excluded)
    - pdf -> application/pdf
    - Explicit exclusions by default: image/svg+xml, image/heic, image/heif, image/tiff
    - Policy: token set is intentionally minimal for v1 parity (image, pdf).

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

