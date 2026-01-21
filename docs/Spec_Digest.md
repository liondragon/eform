# Spec Digest
<!-- Created: 2026-01-04 | Spec version: STABLE -->
<!-- WARNING: Immutable. Do not update. Delete and recreate on major spec revision. -->

## Ordering & Temporal Constraints (MUST)
- [ ] Pipeline order: Security gate → Normalize → Validate → Coerce → Challenge verify → Ledger reserve → side effects (email/uploads/logging) → PRG/redirect. Hard failures abort before side effects. → [§Security](../docs/Canonical_Spec.md#sec-security)
- [ ] Challenge verification before ledger reserve; never on initial GET, only on POST rerender. → [§Challenge](../docs/Canonical_Spec.md#sec-challenge)
- [ ] Throttle enforcement before token mint. → [§Security](../docs/Canonical_Spec.md#sec-security)
- [ ] Ledger reserve immediately precedes email, upload moves, logging; treats EEXIST as duplicate. → [§Ledger](../docs/Canonical_Spec.md#sec-ledger-contract)
- [ ] All writes: temp file → atomic rename in same directory. Ledger via exclusive-create (fopen xb). Fail hard if unsupported. → [§Filesystem](../docs/Canonical_Spec.md#sec-filesystem-semantics)
- [ ] Uploads stay in temp until ledger reserve succeeds; then move to private storage. → [§Uploads](../docs/Canonical_Spec.md#sec-uploads)
- [ ] Email fail after ledger reserve → mint fresh token, burn original in ledger, allow immediate retry. → [§Security](../docs/Canonical_Spec.md#sec-security)
- [ ] Honeypot burn only when token valid; never on token failure. → [§Security](../docs/Canonical_Spec.md#sec-security)
- [ ] Challenge render only on POST rerender when required; never on initial GET. → [§Challenge](../docs/Canonical_Spec.md#sec-challenge)
- [ ] Tokens reuse on error rerender; no rotation until success (except email failure case). → [§Security](../docs/Canonical_Spec.md#sec-security)
- [ ] Measure fill time from original issued_at; rerenders preserve timestamp. → [§Security](../docs/Canonical_Spec.md#sec-security)
- [ ] Health check before hidden-token mint; memoized per request; verify atomic ops. → [§Security](../docs/Canonical_Spec.md#sec-security)

## Prohibitions (MUST NOT / NEVER)
- [ ] disabled:true options reject on submit; never accepted as valid value. → [§Template](../docs/Canonical_Spec.md#sec-template-options)
- [ ] FormRenderer rejects unbalanced row groups at preflight; no auto-closing. → [§Template](../docs/Canonical_Spec.md#sec-template-row-groups)
- [ ] Posted eforms_mode ignored for mode selection; always from persisted record. → [§Security](../docs/Canonical_Spec.md#sec-security)
- [ ] /eforms/mint never sets cookies; must emit Cache-Control: no-store. → [§Mint](../docs/Canonical_Spec.md#sec-mint-endpoint)
- [ ] /eforms/mint never emits CORS headers. → [§Mint](../docs/Canonical_Spec.md#sec-mint-endpoint)
- [ ] Same form_id on page twice is config error; fail fast. → [§Public Surfaces](../docs/Canonical_Spec.md#sec-public-surfaces)
- [ ] Honeypot eforms_hp post value must be empty; non-empty triggers spam response. → [§Security](../docs/Canonical_Spec.md#sec-security)
- [ ] Never write ledger on token failure; only when token_ok=true. → [§Ledger](../docs/Canonical_Spec.md#sec-ledger-contract)
- [ ] Form age never triggers hard fail; always soft signal via age_advisory. → [§Security](../docs/Canonical_Spec.md#sec-security)
- [ ] When uploads disabled, ignore all uploads.* in RuntimeCap calculation. → [§Configuration](../docs/Canonical_Spec.md#sec-configuration)
- [ ] Never store $_FILES tmp_name in emails or logs; use only during request. → [§Uploads](../docs/Canonical_Spec.md#sec-uploads)
- [ ] Health check memoized per request; max once even with multiple forms. → [§Security](../docs/Canonical_Spec.md#sec-security)
- [ ] Renderer embeds tokens as-is from Security; never generates or modifies. → [§FormRenderer](../docs/Canonical_Spec.md#sec-form-renderer)
- [ ] instance_id immutable until token rotation. → [§Security](../docs/Canonical_Spec.md#sec-security)
- [ ] Only throttle impl + GC touch throttle files; no external modifications. → [§Throttle](../docs/Canonical_Spec.md#sec-throttle)
- [ ] Clear stat cache before reading cooldown sentinel mtime. → [§Throttle](../docs/Canonical_Spec.md#sec-throttle)
- [ ] Inline success requires non-cacheable form; reject combination at preflight. → [§Success](../docs/Canonical_Spec.md#sec-success)
- [ ] Never implement SMTP, DKIM, retries, or transcript capture; use wp_mail(). → [§Email](../docs/Canonical_Spec.md#sec-email)
- [ ] Never use raw user input in email headers; sanitize CR/LF, collapse control chars. → [§Email](../docs/Canonical_Spec.md#sec-email)
- [ ] Never call exit/die in plugin code. → [§Hooks](../docs/Canonical_Spec.md#sec-hooks)
- [ ] max_post_bytes never exceeds PHP INI limits; clamp to server caps. → [§Configuration](../docs/Canonical_Spec.md#sec-configuration)
- [ ] Never overwrite existing files; treat collisions as hard errors. → [§Filesystem](../docs/Canonical_Spec.md#sec-filesystem-semantics)
- [ ] GC only deletes .used markers older than retention window (not fresh). → [§Ledger](../docs/Canonical_Spec.md#sec-ledger-contract)
- [ ] Never schedule WP-Cron for GC; require manual wp eforms gc. → [§GC](../docs/Canonical_Spec.md#sec-gc)
- [ ] forms.js calls /eforms/mint only for cacheable=true forms; never for hidden-mode. → [§JavaScript](../docs/Canonical_Spec.md#sec-javascript)
- [ ] forms.js fills empty token fields only; never overwrites populated fields. → [§JavaScript](../docs/Canonical_Spec.md#sec-javascript)

## Security & Data Integrity (MUST)
- [ ] All submissions route through single Security gate; no side-channel paths. → [§Security](../docs/Canonical_Spec.md#sec-security)
- [ ] CSRF prevention: valid token + origin policy; no WordPress nonces. → [§Security](../docs/Canonical_Spec.md#sec-security)
- [ ] /eforms/mint hard-fails cross-origin; no override for missing Origin. → [§Mint](../docs/Canonical_Spec.md#sec-mint-endpoint)
- [ ] Token hard-fail always aborts before ledger reserve; no ledger write on token fail. → [§Ledger](../docs/Canonical_Spec.md#sec-ledger-contract)
- [ ] instance_id must be base64url 16–24 bytes; non-conformant = hard fail. → [§Security](../docs/Canonical_Spec.md#sec-security)
- [ ] Regex validate token UUID + instance_id before disk access; reject early. → [§Security](../docs/Canonical_Spec.md#sec-security)
- [ ] Dirs 0700 (owner only), files 0600 (owner read/write); enforce atomically. → [§Filesystem](../docs/Canonical_Spec.md#sec-filesystem-semantics)
- [ ] Render only public site_key; never expose secret_key; verify tokens server-only. → [§Challenge](../docs/Canonical_Spec.md#sec-challenge)
- [ ] Validation deterministic; error order: global → field (struct/type/required/constraint/cross). Collect all errors. → [§Validation](../docs/Canonical_Spec.md#sec-template-validation)
- [ ] Descriptors immutable after preflight; same objects reused by Renderer + Validator. → [§Template](../docs/Canonical_Spec.md#sec-template-context)
- [ ] Security::token_validate() is read-only; no side effects or header mutations. → [§Security](../docs/Canonical_Spec.md#sec-security)
- [ ] Submission IDs never contain colons; UUID format only. → [§Security](../docs/Canonical_Spec.md#sec-security)
- [ ] Email addresses validated via is_email(); no display names or CSV. → [§Email](../docs/Canonical_Spec.md#sec-email)
- [ ] Token records write once; no rewrites on error rerender. → [§Security](../docs/Canonical_Spec.md#sec-security)
- [ ] Mode/form_id mismatch = hard fail; regex validate before IO. → [§Security](../docs/Canonical_Spec.md#sec-security)

## Ops & Environment (MUST)
- [ ] All writes: temp file in same dir → atomic rename. Required for correctness. → [§Filesystem](../docs/Canonical_Spec.md#sec-filesystem-semantics)
- [ ] All tokens/ledger/throttle use h2 sharding (substr(sha256, 0, 2)); dirs 0700, files 0600. → [§Filesystem](../docs/Canonical_Spec.md#sec-filesystem-semantics)
- [ ] Ledger reserve via fopen('xb'); treats EEXIST as duplicate. → [§Ledger](../docs/Canonical_Spec.md#sec-ledger-contract)
- [ ] Atomic write/rename non-negotiable; flock()-dependent features optional. → [§Filesystem](../docs/Canonical_Spec.md#sec-filesystem-semantics)
- [ ] Storage layout: eforms-private/{tokens,ledger/form_id,throttle,logs,f2b}/. → [§Filesystem](../docs/Canonical_Spec.md#sec-filesystem-semantics)
- [ ] Create index.html + .htaccess + web.config in eforms-private/ on first write. → [§Filesystem](../docs/Canonical_Spec.md#sec-filesystem-semantics)
- [ ] Throttle file-based, 60s fixed window; run in Security gate before normalize. → [§Throttle](../docs/Canonical_Spec.md#sec-throttle)
- [ ] flock() required for throttle; disable if hosting unreliable. → [§Throttle](../docs/Canonical_Spec.md#sec-throttle)
- [ ] Cooldown sentinel check before flock; fast reject if sentinel valid. → [§Throttle](../docs/Canonical_Spec.md#sec-throttle)
- [ ] flock() fail → skip throttle, log warning; continue processing. → [§Throttle](../docs/Canonical_Spec.md#sec-throttle)
- [ ] JSONL dirs 0700, files 0600; rotate on size; prune old files. → [§Logging](../docs/Canonical_Spec.md#sec-logging)
- [ ] Fail2ban emission uses raw IP (ignores privacy mode) for enforcement. → [§Logging](../docs/Canonical_Spec.md#sec-logging)
- [ ] GC batch-limited; time or count boxed; prevent cron hangs. → [§GC](../docs/Canonical_Spec.md#sec-gc)
- [ ] Multi-webhead deployments MUST mount `${uploads.dir}` as a shared persistent volume; ephemeral storage unsupported. → [§Compatibility](../docs/Canonical_Spec.md#sec-compatibility)

## Field & Schema Constraints (MUST)
- [ ] Field keys: lowercase, 1–64 chars, [a-z0-9_-] only. → [§Template](../docs/Canonical_Spec.md#sec-template-model-fields)
- [ ] No square brackets in keys; prevents PHP array collision. → [§Template](../docs/Canonical_Spec.md#sec-template-model-fields)
- [ ] Reserved: form_id, instance_id, submission_id, eforms_token, eforms_hp, eforms_mode, timestamp, js_ok, eforms_email_retry, ip, submitted_at. → [§Template](../docs/Canonical_Spec.md#sec-template-model-fields)
- [ ] Email block required; must have: to, subject, email_template, include_fields. → [§Email](../docs/Canonical_Spec.md#sec-email)
- [ ] email.to scalar → normalize to []; each validated via is_email(). → [§Email](../docs/Canonical_Spec.md#sec-email)
- [ ] include_fields entries must exist in fields[] or meta (ip, submitted_at, form_id, instance_id, submission_id). → [§Email](../docs/Canonical_Spec.md#sec-email)
- [ ] Row groups exempt from field count; markup-only. → [§Template](../docs/Canonical_Spec.md#sec-template-row-groups)

## Configuration & Validation (MUST)
- [ ] Runtime never modifies template JSON files; load-only. → [§Template](../docs/Canonical_Spec.md#sec-template-json)
- [ ] Shipped templates require preflight validation; no separate JSON Schema. → [§Template](../docs/Canonical_Spec.md#sec-template-validation)
- [ ] Server selects mode; rendered form carries eforms_mode; client cannot override. → [§Security](../docs/Canonical_Spec.md#sec-security)
- [ ] Honeypot: autocomplete=off, tabindex=-1, aria-hidden=true. → [§Security](../docs/Canonical_Spec.md#sec-security)
- [ ] Cross-field rules bounded: required_if, required_if_any, required_unless, one_of. → [§Validation](../docs/Canonical_Spec.md#sec-template-validation)
- [ ] Schema violations raise deterministic errors; prevent render. → [§Template](../docs/Canonical_Spec.md#sec-template-validation)
- [ ] eforms.config.php optional; must return array; precedence: defaults < drop-in < filter. → [§Configuration](../docs/Canonical_Spec.md#sec-configuration)
- [ ] Config precedence: hard constraints always; soft defaults < drop-in < filter. → [§Configuration](../docs/Canonical_Spec.md#sec-configuration)
- [ ] Unknown config keys rejected; missing keys use defaults then clamp. → [§Configuration](../docs/Canonical_Spec.md#sec-configuration)
- [ ] Numeric config values clamped to [MIN, MAX] per constraints table. → [§Configuration](../docs/Canonical_Spec.md#sec-configuration)
- [ ] `Config::DEFAULTS` in `src/Config.php` is the single source of truth for runtime default values. → [§Configuration](../docs/Canonical_Spec.md#sec-configuration)
- [ ] Machine-readable surfaces (error codes, `/eforms/mint` responses, log schemas) MUST evolve append-only. → [§Configuration](../docs/Canonical_Spec.md#sec-configuration)

## API & Protocol (MUST)
- [ ] POST only; reject GET/PUT/DELETE with 405. → [§Mint](../docs/Canonical_Spec.md#sec-mint-endpoint)
- [ ] POST Content-Type must be form-urlencoded; reject JSON bodies. → [§Mint](../docs/Canonical_Spec.md#sec-mint-endpoint)
- [ ] /eforms/mint always JSON response (success + errors). → [§Mint](../docs/Canonical_Spec.md#sec-mint-endpoint)
- [ ] All security responses: Cache-Control: private, no-store, max-age=0. → [§Cache](../docs/Canonical_Spec.md#sec-cache-safety)
- [ ] Emit nocache_headers() + verify Cache-Control remains. → [§Cache](../docs/Canonical_Spec.md#sec-cache-safety)
- [ ] 429 response MUST include Retry-After: positive seconds (1 minimum). → [§Mint](../docs/Canonical_Spec.md#sec-mint-endpoint)
- [ ] Redirects via wp_safe_redirect; same-origin only. → [§Success](../docs/Canonical_Spec.md#sec-success)
- [ ] PRG success status: 303 See Other; must satisfy cache-safety rules. → [§Success](../docs/Canonical_Spec.md#sec-success)

## Observability (MUST)
- [ ] Every log event MUST include the resolved `request_id` correlation identifier. → [§Logging](../docs/Canonical_Spec.md#sec-logging)
- [ ] Minimal logging mode MUST REDACT all PII regardless of `logging.pii` setting. → [§Logging](../docs/Canonical_Spec.md#sec-logging)
- [ ] Log only `origin_state`; NEVER log the `Referrer` header. → [§Origin Policy](../docs/Canonical_Spec.md#sec-origin-policy)
- [ ] `soft_reasons` is a closed set: producers MUST use only `{min_fill_time, age_advisory, js_missing, origin_soft}`. → [§Security](../docs/Canonical_Spec.md#sec-security)

## File Safety (MUST) — from AGENTS.md §2
- [ ] Never delete files without explicit permission
- [ ] Read before edit

---

Anchor names referenced across multiple sections (look up values in [Canonical_Spec.md](../docs/Canonical_Spec.md#sec-anchors)):

- [TOKEN_TTL_MIN], [TOKEN_TTL_MAX], [LEDGER_GC_GRACE_SECONDS]
- [MIN_FILL_SECONDS_MIN], [MIN_FILL_SECONDS_MAX]
- [MAX_FORM_AGE_MIN], [MAX_FORM_AGE_MAX]
- [MAX_FIELDS_MIN], [MAX_FIELDS_MAX]
- [MAX_OPTIONS_MIN], [MAX_OPTIONS_MAX]
- [MAX_MULTIVALUE_MIN], [MAX_MULTIVALUE_MAX]
- [LOGGING_LEVEL_MIN], [LOGGING_LEVEL_MAX], [RETENTION_DAYS_MIN], [RETENTION_DAYS_MAX]
- [THROTTLE_MAX_PER_MIN_MIN], [THROTTLE_MAX_PER_MIN_MAX], [THROTTLE_COOLDOWN_MIN], [THROTTLE_COOLDOWN_MAX]
- [CHALLENGE_TIMEOUT_MIN], [CHALLENGE_TIMEOUT_MAX]
