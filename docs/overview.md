# electronic_forms - Overview

## What & Who

**electronic_forms** is a dependency-free WordPress plugin for rendering and processing contact forms without admin UI, sessions, or external libraries. It provides a deterministic, testable pipeline for public contact-style forms while offering strong spam resistance and duplicate-submission protection.

**Promise:** Delivers strong spam resistance and duplicate-submission prevention for both cached and non-cached pages without requiring a database or external dependencies.

**Target Operators:** Solo developers and small ops teams managing a handful of WordPress sites with modest daily form volume. Operators are comfortable with JSON templates and WP-CLI, prefer lightweight solutions over admin dashboards, and value cache-friendliness and operational simplicity.

**Environment:** WordPress 5.8+ / PHP 8.0+ with writable uploads directory. Works on single-server setups out of the box; multi-webhead deployments require shared persistent storage for `${uploads.dir}` that all servers can access.

## Core Concepts

- **Deterministic Pipeline:** Strict flow governs every submission: Render → Security Gate → Normalize → Validate → Coerce → (Challenge if needed) → Commit → Success. Given the same inputs, produces identical outputs. Error ordering is deterministic (global errors first, then field errors in template order). All errors collected (no short-circuit).

- **Token Modes:** Dual-mode system balances cacheability with security.
  - **Hidden-Token Mode** (`cacheable="false"`, default): Server-minted tokens embedded in form. *Tradeoff: Simpler (no JS required) but prevents CDN caching.*
  - **JS-Minted Mode** (`cacheable="true"`): JavaScript fetches token via `/eforms/mint` on page load. *Tradeoff: Enables long-lived caching but requires JS-capable clients.*
  - Both modes share ledger-based deduplication; never use cookies or WordPress nonces.

- **Ledger:** File-based duplicate prevention ensuring each token can only be used once. On successful submission, token marked as consumed—prevents accidental double-submissions (back button, refresh, retry) and replay attacks.
  - **Operator sees:** "This form was already submitted or has expired - please reload the page"

- **Storage Hierarchy:** All runtime artifacts under `${uploads.dir}/eforms-private/` in protected directories:
  - `tokens/` — Minted token records (hidden and JS modes)
  - `ledger/{form_id}/` — One-time-use markers preventing duplicates
  - `uploads/` — Private file storage for submitted attachments
  - `throttle/` — Rate-limit tracking (when enabled)
  - `logs/` — Structured JSONL logs (when enabled)
  - `f2b/` — Fail2ban emission (when enabled)
  - Storage uses sharded subdirectories to handle scale efficiently.

- **Lazy Loading:** Components initialize only when needed: configuration on first use, security during token operations, uploads when file fields present, email after validation, challenge when required, throttling when enabled.

## Template Structure

Templates are JSON files in `/templates/forms/{form_id}.json`. Operators define form structure, validation, and email delivery here.

### Template Shape

```json
{
  "id": "contact",
  "version": "1.0",
  "title": "Contact Form",
  "fields": [ /* field descriptors */ ],
  "rules": [ /* cross-field validation rules */ ],
  "email": {
    "to": "admin@example.com",
    "subject": "New Contact - {{field.name}}",
    "email_template": "default",
    "include_fields": ["name", "email", "message", "ip", "submitted_at"],
    "display_format_tel": "xxx-xxx-xxxx"
  },
  "success": {
    "mode": "inline",
    "message": "Thank you! We'll be in touch soon."
  },
  "submit_button_text": "Send Message"
}
```

### Field Options (Selects, Radios, Checkboxes)

Fields with choices use an `options` array with explicit keys:

```json
{
  "key": "contact_method",
  "type": "radio",
  "label": "Preferred Contact",
  "required": true,
  "options": [
    { "key": "email", "label": "Email" },
    { "key": "phone", "label": "Phone" },
    { "key": "mail", "label": "Postal Mail", "disabled": true }
  ]
}
```

- Submitted value equals the option `key` (not label)
- Disabled options cannot be submitted; selecting one produces a validation error
- Checkbox groups work identically; submitted value is an array of selected keys

### Row Groups (Layout Structure)

Use `row_group` pseudo-fields to wrap fields in structural containers (no submission data):

```json
{ "type": "row_group", "mode": "start", "tag": "div", "class": "two-column" },
{ "key": "first_name", "type": "name", "label": "First Name" },
{ "key": "last_name", "type": "name", "label": "Last Name" },
{ "type": "row_group", "mode": "end" }
```

- Row groups must be balanced (every `start` must have a matching `end`)
- Unbalanced groups produce a configuration error: `EFORMS_ERR_ROW_GROUP_UNBALANCED`
- Groups can be nested; tag can be `div` (default) or `section`

### Cross-Field Rules

Templates may include a `rules` array for validation that depends on multiple fields:

| Rule | Example | Meaning |
|------|---------|---------|
| `required_if` | `{"rule":"required_if", "target":"state", "field":"country", "equals":"US"}` | State required when country is US |
| `required_if_any` | `{"rule":"required_if_any", "target":"discount_code", "fields":["type","membership"], "equals_any":["partner","gold"]}` | Discount code required if any field matches |
| `required_unless` | `{"rule":"required_unless", "target":"email", "field":"phone", "equals":"provided"}` | Email required unless phone provided |
| `matches` | `{"rule":"matches", "target":"confirm_email", "field":"email"}` | Confirm must equal email |
| `one_of` | `{"rule":"one_of", "fields":["email","phone","fax"]}` | At least one contact method required |
| `mutually_exclusive` | `{"rule":"mutually_exclusive", "fields":["credit_card","paypal"]}` | Cannot provide both |

- Rules are evaluated in order; multiple violations reported together
- `target` receives the error message when the rule triggers

### Telephone Display Format

The `email.display_format_tel` token controls how `tel_us` values appear in email summaries:

| Token | Output |
|-------|--------|
| `xxx-xxx-xxxx` (default) | `555-123-4567` |
| `(xxx) xxx-xxxx` | `(555) 123-4567` |
| `xxx.xxx.xxxx` | `555.123.4567` |

## Configuration

Configuration is layered: code defaults < drop-in file (`${WP_CONTENT_DIR}/eforms.config.php`) < WordPress filter (`eforms_config`). Values are clamped to safe ranges at startup.

### Key Knobs (Narrative)

The most frequently tuned knobs with operator-facing tradeoffs:

| Knob | Effect | Tradeoff / Notes |
|------|--------|------------------|
| `security.token_ttl_seconds` | Controls how long a form token remains valid (clamped 1–86400s) | Shorter = tighter security but may frustrate slow users; longer = better UX but extends replay window |
| `security.min_fill_seconds` | Minimum fill time (clamped 0–60s) | Adds `min_fill_time` soft signal if submitted too fast; helps catch bots |
| `security.origin_mode` | `off` \| `soft` (log only) \| `hard` (fail) for Origin header checks | `hard` mode blocks cross-origin requests; test before enabling in production |
| `spam.soft_fail_threshold` | Number of soft signals needed to trigger spam rejection | Lower (1-2) = stricter; higher (3-4) = more permissive. Clamped ≥1 |
| `challenge.mode` | `off` \| `auto` (challenge suspects) \| `always_post` (challenge everyone) | `auto` only challenges when soft signals present; requires Turnstile keys |
| `throttle.enable` | Toggles file-based per-IP rate limiting | Requires reliable file-locking support; verify with your host before enabling |
| `logging.mode` | `off` \| `minimal` (error_log) \| `jsonl` (structured files) | JSONL recommended for forensics; minimal for lightweight ops |
| `logging.level` | `0` (errors) \| `1` (+warnings) \| `2` (+info) | Level 2 logs successful submissions and spam decisions |
| `privacy.ip_mode` | `none` \| `masked` \| `hash` \| `full` | Controls IP presentation in logs/emails; enforcement (throttle/Fail2ban) uses raw IPs |



## Command / Interface Reference

### Essential

- **Shortcode:** `[eform id="slug" cacheable="false"]` — Renders a form; `cacheable` selects token mode
- **Template tag:** `eform_render('slug', ['cacheable' => true])` — PHP equivalent
- **REST endpoint:** `POST /eforms/mint` — Mints JS-mode tokens for cacheable pages (enforces origin policy and throttling; returns JSON `{token, instance_id, timestamp, expires}`)

### Operational

- **WP-CLI GC:** `wp eforms gc` — Garbage-collects expired tokens, ledger markers, uploads, throttle state, and logs
  - Operators MUST schedule via system cron (plugin does not use WP-Cron)
  - Supports `--dry-run` to preview candidates
  - Processes limited batches to prevent timeouts
  - **Operator sees:** Dry-run lists counts/bytes; normal mode emits GC summary at `info` level

### Safety

- **Health Check:** System runs FS health checks on render/post; failures surface as `EFORMS_ERR_STORAGE_UNAVAILABLE`
- **Uninstall:** `uninstall.php` respects `install.uninstall.purge_*` flags in config to optionally wipe data

### Configuration

- **Drop-in file:** `${WP_CONTENT_DIR}/eforms.config.php` — Optional override source; must return an array
- **Filter:** `eforms_config` — Optional runtime override hook (receives merged config, returns final config)
- **Filter:** `eforms_request_id` — Optional request correlation override for logs

## Lifecycle / Pipeline

### High-Level Flow (6 Stages)

1. **Render (GET):** Template load → Preflight → Token mint (hidden or JS-deferred) → Markup emission → Asset enqueue
2. **Mint (Cacheable Only):** JS calls `/eforms/mint` → Origin/throttle check → Token generation → Injection
3. **Submission (POST):** Security gate → Normalize → Validate → Coerce
4. **Challenge (Conditional):** Verify Turnstile when required (auto + soft signals, or always_post)
5. **Commit:** Ledger reservation → Upload moves → Email send → Log
6. **Success:** PRG redirect (inline or external) → Cleanup

### Detailed Stage Breakdowns

#### 1. Render (GET) — Initial Page Load

**Hidden-mode flow:**
1. Renderer reads form template from `/templates/forms/{form_id}.json`
2. Validates template structure and configuration
3. Checks storage availability (one check per request)
4. Checks rate limits (when enabled; on fail: inline error, no token minted)
5. Generates security token with unique instance ID and timestamp
6. Renders form HTML with validation hints and accessibility markup
7. Loads required CSS/JS assets

**JS-mode flow (cacheable):**
1. Same template validation
2. Renderer leaves security fields empty (JavaScript will populate them)
3. Renders form HTML marked as cacheable
4. Loads required CSS/JS assets

**Operator sees:**
- Form rendered with visible fields, error summary container, submit button
- On storage/throttle failure (hidden-mode): Inline per-form error (HTTP 200, no token minted)
- On preflight failure: "Form configuration error" (no white screen)

#### 2. Mint (Cacheable Pages Only) — JS-Driven Token Fetch

1. **DOMContentLoaded:** `forms.js` detects empty `eforms_token` fields
2. **Mint call:** `POST /eforms/mint` with form-encoded body `f={form_id}`
   - Origin check: Hard-fail (403) if `origin_state != "same"`
   - Throttle check: When enabled, enforce per-IP rate limit (429 on exceed)
   - Token generation: Mint `{token, instance_id, timestamp}` and persist record
3. **Injection:** JS populates hidden fields
4. **Caching:** Token cached in `sessionStorage` (reused on back/refresh until expiry)
5. **Submit unlock:** Submission enabled after successful injection

**Operator sees:**
- Transparent to end-user (no visible interaction)
- On mint failure: Submission blocked; generic error in form error summary
- On throttle: "Please wait a moment and try again" (with `Retry-After` header)

#### 3. Submission (POST) — Security → Validation

**Security Gate (hard failures stop here):**
1. **Token validation:** Verify against persisted record; check TTL, mode, form_id match
2. **Honeypot:** Check `eforms_hp` is empty
3. **Timing checks:** Add soft signals for `min_fill_time` (too fast), `age_advisory` (too old)
4. **Origin policy:** Evaluate Origin header per `security.origin_mode`
5. **JS check:** Add `js_missing` soft signal when `js_ok != "1"`
6. **Throttle:** When enabled, enforce per-IP rate limit
7. **Spam decision:** If `soft_fail_count >= spam.soft_fail_threshold` → spam-fail

**Normalize → Validate → Coerce:**
1. **Normalize:** Clean submitted data (remove slashes, trim whitespace, normalize Unicode)
2. **Validate:** Check required fields, length limits, patterns, file types, cross-field rules
3. **Coerce:** Standardize formats (lowercase emails, canonicalize phone numbers, collapse whitespace)

**Operator sees:**
- On hard fail (honeypot): Per config (`stealth_success` mimics success; `hard_fail` shows generic error)
- On throttle: HTTP 429 "Please wait..." + `Retry-After` header
- On spam-fail: Stealth success or generic error; logged with `spam_decision=fail` + `soft_reasons`
- On validation errors: Rerender with per-field messages + error summary (first invalid focused)
- **Signals collected:** `soft_reasons` array (`min_fill_time`, `age_advisory`, `js_missing`, `origin_soft`)

#### 4. Challenge Verification (Optional; When Triggered)

**Trigger conditions:**
- `challenge.mode="always_post"` — Every submission
- `challenge.mode="auto"` — When `soft_reasons` is non-empty (timing anomalies, origin mismatches)
- Provider response present — User completed challenge on previous rerender

**Flow:**
1. **Render (POST only):** Challenge widget emitted only on POST rerenders (never initial GET)
2. **Provider script load:** Turnstile API script enqueued (deferred, crossorigin=anonymous)
3. **User interaction:** User completes CAPTCHA
4. **Verification:** POST to Turnstile API with `challenge.secret_key` and response token (timeout clamped 1–5s)
5. **Decision:** Success removes relevant labels from `soft_reasons`; failure rerenders with `EFORMS_ERR_CHALLENGE_FAILED`

**Operator sees:**
- Turnstile iframe rendered below submit button
- Spinner during verification
- On failure: "Please complete the verification and submit again"
- On unconfigured: "Verification unavailable; please try again" (`EFORMS_CHALLENGE_UNCONFIGURED`)

#### 5. Commit — Ledger + Side Effects

1. **Ledger reservation:** Mark submission token as used (prevents duplicate submissions)
   - If already marked: treat as duplicate submission
   - On storage errors: hard fail with system error message
2. **Upload moves:** Move uploaded files to private storage with unique names
3. **Email send:** Render email template, attach files (respecting size limits), send via WordPress
4. **Log:** Record event (when logging enabled)

**Operator sees:**
- On duplicate: "This form was already submitted or has expired - please reload the page"
- On email failure (after ledger reserved): HTTP 500 + `_global` error + fresh token minted + read-only submission summary for manual copy
- On success: PRG redirect (next stage)
- **Signals:** Ledger marker created, email sent, JSONL log entry (if enabled)

#### 6. Success — PRG Redirect + Cleanup

**Inline mode:**
1. PRG redirect (303) to same URL with `?eforms_success={form_id}`
2. On follow-up GET, renderer displays success banner (using `success.message` from template)
3. Temp uploads deleted (unless `uploads.retention_seconds > 0`)

**Redirect mode:**
1. Redirect to configured external URL (same-origin only, HTTP 303)
2. Destination renders its own success UX
3. Cleanup per retention policy

**Operator sees:**
- Inline mode: Success banner above form (idempotent; can revisit/bookmark)
- Redirect mode: Custom success page
- Email-failure recovery: Preserved input in read-only textarea for manual copy

## Operational Behavior & Safety

### Spam Defense Layers

**Hard Gates (immediate rejection):**
- **Honeypot:** `eforms_hp` field filled → `stealth_success` or `hard_fail` per config
  - *Operator sees: Stealth mimics success (logged with `stealth=true`); hard fail shows generic error*
- **Invalid Token:** Missing/expired/invalid token record or mode/form_id mismatch
  - *Operator sees: "Security check failed" or "This form was already submitted or has expired"*
- **Throttle:** Rate limit exceeded (fixed 60s window)
  - *Operator sees: HTTP 429 "Please wait a moment and try again" + `Retry-After` header*
- **Origin Policy (hard mode):** Cross-origin or unknown Origin header when `security.origin_mode=hard`
  - *Operator sees: "Security check failed" (generic)*

**Soft Scoring (accumulates signals):**
- `min_fill_time`: Submitted faster than `security.min_fill_seconds`
- `age_advisory`: Token older than `security.max_form_age_seconds`
- `origin_soft`: Origin header missing/mismatched (in soft mode)
- `js_missing`: JS proof-of-work missing

**Spam Decision:**
- If `soft_fail_count >= spam.soft_fail_threshold` → reject (stealth or hard per `honeypot_response`)
- *Operator sees: In logs (level ≥1): `spam_decision=fail` with triggering `soft_reasons`. User sees stealth success or generic error.*

### Failure Modes Reference

| Failure | Behavior | HTTP Status | Observable |
|---------|----------|-------------|-----------|
| Token missing/expired/invalid | Hard fail | 200 (rerender) | "This form was already submitted or has expired - please reload the page" |
| Duplicate submission | Hard fail | 200 (rerender) | Same token error message |
| Honeypot triggered | Stealth success or hard fail | 200 | Stealth mimics success; hard fail shows generic error |
| Validation errors | Rerender with errors | 200 | Per-field messages + error summary (first invalid focused) |
| Throttle exceeded | Hard fail | 429 | "Please wait a moment and try again" + `Retry-After` header |
| Email send failure (after ledger) | Hard fail + fresh token | 500 | `_global` error + read-only submission summary + new token for retry |
| Challenge verification failure | Rerender with challenge | 200 | "Please complete the verification and submit again" |
| Storage unavailable | Hard fail | 200 (inline) or 500 | "Form configuration error: server storage is unavailable" |
| Mint failure (JS mode) | Client-side error | - | Submission blocked; generic error in form |
| Duplicate form ID on page | Configuration error | 200 | Second instance shows "Form configuration error: duplicate form id" |
| Challenge unconfigured | Configuration error | 200 (rerender) | "Verification unavailable; please try again" |
| Row groups unbalanced | Configuration error | 200 | "Form configuration error: group wrappers are unbalanced" |

### Client-Side Behavior (`forms.js`)

The plugin includes `forms.js` for client-side enhancements. It is required for cacheable pages (JS-minted mode) and optional otherwise.

**What it does:**
- Sets `js_ok="1"` marker on DOMContentLoaded (used for `js_missing` soft signal)
- For JS-minted forms: calls `/eforms/mint` to fetch token, injects into hidden fields, enables submit
- Caches tokens in `sessionStorage` by `form_id` (reused on back/refresh until expiry)
- Disables submit button + shows spinner during submission
- Focuses error summary, then first invalid field after server rerenders with errors

**Mixed-mode pages:** Each form handled independently. Hidden-mode forms work without JS; JS-minted forms require it.

**Mint failure:** Submission stays blocked; generic error appears in form's error summary area.

**Email-failure remint:** When form has `data-eforms-remint="1"` attribute (added after email failure), forms.js clears cached token, calls `/eforms/mint` fresh, reinjects, and re-enables submit.

### Observability

**Logging Modes:**
- **Off:** No logging (except optional Fail2ban emission)
- **Minimal:** Compact lines via `error_log()` with severity, code, form/submission IDs, masked IP, URI, metadata blob
  - Format: `eforms severity={error|warning|info} code={EFORMS_*} form={form_id} subm={submission_id} ip={masked|hash|full|none} uri="{path?eforms_*...}" msg="{short}" meta={compact JSON}`
- **JSONL:** Structured files under `${uploads.dir}/eforms-private/logs/` with rotation/retention
  - Each event includes `request_id`, severity, code, form/submission IDs, spam signals, challenge/throttle outcomes
  - Rotation: When file exceeds internal size cap
  - Retention: Prune files older than `logging.retention_days`

**Severity Levels:**
- **0 (errors):** Fatal pipeline failures (token errors, ledger IO, email send failures, storage unavailable)
- **1 (+warnings):** Rejections, validation errors, challenge timeouts, config issues, drop-in failures
- **2 (+info):** Successful sends, token rotations, throttle state changes, GC summaries

**Request Correlation:**
- Every log event includes `request_id` resolved from: filter → headers (`X-Eforms-Request-Id`, `X-Request-Id`, `X-Correlation-Id`) → auto-generated unique ID
- Operators can trace a submission across logs/emails via this ID

**Fail2ban Emission (independent of `logging.mode`):**
- When `logging.fail2ban.file` is non-empty, emit single-line raw-IP logs
- Format: `eforms[f2b] ts={unix} code={EFORMS_ERR_*} ip={resolved_client_ip} form={form_id}`
- Uses full IP regardless of `privacy.ip_mode` (intentional for enforcement)
- *Privacy notice: Operators enabling Fail2ban accept that raw IPs appear in this log even when `privacy.ip_mode` is masked/hash/none*

### Maintenance

**Garbage Collection (manual; via WP-CLI `wp eforms gc`):**
- **What:** Expired token records, ledger `.used` markers (conservative TTL + grace window), stale throttle files, old uploads (per retention), rotated logs
- **When:** Operators schedule via system cron (plugin does not use WP-Cron)
- **How:** Batch processing (time-boxed or count-boxed) to prevent timeouts; locked via `gc.lock` to prevent overlaps
- **Operator sees:** Dry-run lists candidates; normal mode emits counts/bytes at `info` level

**No rotation/cleanup tasks beyond GC scheduling:**
- JSONL logs rotate automatically when file exceeds internal size cap
- Fail2ban logs rotate identically
- Uploads cleaned after email send (unless `uploads.retention_seconds > 0`)

### Setup Behavior

**Installation (observable phases):**
1. **Activate plugin:** No admin UI; forms remain inactive until templates exist
2. **Create templates:** Add JSON files to `/templates/forms/*.json`
3. **Optional config:** Create drop-in at `${WP_CONTENT_DIR}/eforms.config.php` or use filter
4. **Render forms:** Add shortcode or template tag to pages

**Operator sees during setup:**
- Missing template → "Form configuration error" inline (HTTP 200; no white screen)
- Storage health-check failure (GET hidden-mode mint) → Inline per-form error (HTTP 200)
- Challenge mode enabled but credentials missing → `EFORMS_CHALLENGE_UNCONFIGURED` error
- Invalid drop-in config → Warning log (when enabled); continue with defaults

**Required inputs (to render a working form):**
- Valid JSON template with `id`, `fields[]`, `email{}`, `success{}`
- Writable `${uploads.dir}` (verified by health check)
- When `challenge.mode != "off"`: Valid Turnstile `site_key`/`secret_key`

### Removal/Cleanup

**Uninstall behavior:**
- Controlled by uninstall configuration flags (e.g., `install.uninstall.purge_logs`)
- When purge flags enabled, `uninstall.php` deletes runtime artifacts under `${uploads.dir}/eforms-private/`
- **Operator sees:** Directories removed; no orphaned files

**What persists after uninstall (when purge disabled):**
- Token records, ledger markers, uploads, logs remain on disk
- Templates remain in `/templates/`
- Drop-in config remains in `wp-content/`

## Example User Journey

**Scenario:** Solo developer adds a contact form to a cacheable About page.

1. **Configure:**
   - Create `/templates/forms/contact.json` with fields (`name`, `email`, `message`), validation rules, email delivery target, and `success.mode="inline"`
   - Add shortcode to page: `[eform id="contact" cacheable="true"]`
   - *Operator sees: JSON file in templates directory*

2. **Initial page load (GET):**
   - User visits `/about/` (cached by CDN)
   - Form renderer outputs form HTML with empty security fields
   - `forms.js` loads on DOMContentLoaded, calls `POST /eforms/mint`, injects token, caches in `sessionStorage`
   - *Operator sees: Form rendered; submit button enabled (transparent to user)*

3. **User fills form:**
   - Enters name, email, message
   - Clicks submit
   - *Operator sees: Submit button disabled + spinner (via `forms.js`)*

4. **Submission (POST):**
   - Security gate validates token, checks honeypot, evaluates timing/origin (no soft signals; all pass)
   - Normalize → Validate → Coerce (all pass)
   - Ledger reservation succeeds (new submission)
   - Email sent to site admin via `wp_mail()`
   - *Operator sees: In JSONL log (if enabled): `severity=info`, `code=EFORMS_SUCCESS`, `spam_decision=pass`*

5. **Success (PRG):**
   - 303 redirect to `/about/?eforms_success=contact`
   - Follow-up GET displays success banner: "Thank you! We'll be in touch soon."
   - *Operator sees: Success banner above form; form hidden on this view per template config*

## Appendix: Complete Configuration Reference

Exhaustive knob coverage organized by domain (for spec generation and advanced configuration):

| Domain | Key | Type | Effect | Constraints |
|--------|-----|------|--------|-------------|
| **Security** | `security.origin_mode` | enum | Origin header policy | `{off, soft, hard}` |
| | `security.origin_missing_hard` | bool | Treat missing Origin as hard-fail when `origin_mode=hard` | - |
| | `security.honeypot_response` | enum | Observable response when honeypot triggers | `{stealth_success, hard_fail}` |
| | `security.min_fill_seconds` | int | Minimum fill time before submission accepted | Clamped 0–60 |
| | `security.token_ttl_seconds` | int | Token lifetime | Clamped 1–86400 |
| | `security.max_form_age_seconds` | int | Advisory age limit (soft signal) | Clamped 1–86400; defaults to `token_ttl_seconds` |
| | `security.max_post_bytes` | int | POST size cap | Never exceeds PHP INI limits |
| | `security.js_hard_mode` | bool | Hard-fail when `js_ok` marker missing | Blocks non-JS users; keep opt-in |
| **Spam** | `spam.soft_fail_threshold` | int | Soft signal count to trigger spam rejection | Clamped ≥1 |
| **Challenge** | `challenge.mode` | enum | When to require CAPTCHA | `{off, auto, always_post}` (legacy `always` accepted) |
| | `challenge.provider` | enum | Provider (v1) | `{turnstile}` (hcaptcha/recaptcha reserved) |
| | `challenge.site_key` | string | Turnstile site key | Required when `mode != off` |
| | `challenge.secret_key` | string | Turnstile secret key | Required when `mode != off` |
| | `challenge.http_timeout_seconds` | int | API timeout | Clamped 1–5 |
| **Throttle** | `throttle.enable` | bool | Enable per-IP rate limiting | Requires file-locking support |
| | `throttle.per_ip.max_per_minute` | int | Max requests per 60s window | Clamped 1–120 |
| | `throttle.per_ip.cooldown_seconds` | int | Penalty duration after limit hit | Clamped 0–600 (0=disabled) |
| **Logging** | `logging.mode` | enum | Logging sink | `{off, minimal, jsonl}` |
| | `logging.level` | int | Severity filter | `0` (errors) \| `1` (+warnings) \| `2` (+info) |
| | `logging.headers` | bool | Log normalized UA/Origin | Disabled by default for privacy |
| | `logging.pii` | bool | Allow full IPs/emails in JSONL | Minimal always redacts |
| | `logging.retention_days` | int | Log retention | Clamped 1–365 |
| | `logging.fail2ban.target` | enum | Fail2ban emission | `{file}` (v1 only) |
| | `logging.fail2ban.file` | string | Fail2ban log path | When non-empty, enables emission |
| | `logging.fail2ban.retention_days` | int | Fail2ban log retention | Clamped 1–365; defaults to `logging.retention_days` |
| **Privacy** | `privacy.ip_mode` | enum | IP presentation | `{none, masked, hash, full}` |
| | `privacy.client_ip_header` | string | Proxy header name | e.g., `X-Forwarded-For` |
| | `privacy.trusted_proxies` | array | CIDR list | Only trust when `REMOTE_ADDR` matches |
| **Email** | `email.from_address` | string | From address (same-domain only) | Defaults to `no-reply@{site_domain}` |
| | `email.reply_to_address` | string | Reply-To address | Takes precedence over `reply_to_field` |
| | `email.reply_to_field` | string | Reply-To field key | Ignored when `reply_to_address` set |
| | `email.html` | bool | Send HTML emails | `false` = text/plain only |
| **HTML5** | `html5.client_validation` | bool | Browser native validation | `true` = omit `novalidate`; `false` = suppress native UI |
| **Validation** | `validation.max_fields_per_form` | int | Field count cap | Clamped 1–1000 |
| | `validation.max_options_per_group` | int | Option count cap | Clamped 1–1000 |
| | `validation.max_items_per_multivalue` | int | Multivalue count cap | Clamped 1–1000 |
| **Uploads** | `uploads.enable` | bool | Master upload switch | - |
| | `uploads.max_file_bytes` | int | Per-file size cap | Never exceeds PHP INI |
| | `uploads.max_files` | int | Max files per request | - |
| | `uploads.total_request_bytes` | int | Total upload cap per request | - |
| | `uploads.max_email_bytes` | int | Attachment total cap | Prevents SMTP 552 |
| | `uploads.retention_seconds` | int | Upload retention after email send | 0 = delete immediately |
| **Assets** | `assets.css_disable` | bool | Opt out of plugin CSS | Lets themes override |

