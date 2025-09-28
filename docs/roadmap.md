# Product Roadmap

## Phase 1: `Config::get()` snapshot + lazy bootstrap.

**Goal:** Every public entry point primes the frozen configuration via `Config::get()`; helpers defensively re-check it. Establishing lazy bootstrap up front prevents downstream components from solving bootstrapping piecemeal and guarantees a consistent snapshot for all security and lifecycle decisions.

**Delivers**

- `Config::get()` (idempotent, per-request snapshot) + `bootstrap()` called exactly-once per request.
- Snapshot covers `security.*`, `spam.*`, `challenge.*`, `email.*`, `logging.*`, `privacy.*`, `throttle.*`, `validation.*`, `uploads.*`, `assets.*`, `install.*`, defaults (authoritative table in [Configuration: Domains, Constraints, and Defaults (§17)](#sec-configuration)).
- Shared storage rules: `{h2}` sharding via `Helpers::h2()`, dirs `0700`, files `0600`.
- Defensive `Config::get()` calls inside helpers (normative lazy backstop).
- CI/lint hooks to assert all entry points (`Renderer`, `SubmitHandler`, `/eforms/prime`, `/eforms/success-verify`, challenge verifiers, `Emailer`) call `Config::get()` up front.
- `uninstall.php` implements and exercises the guard/Config bootstrap/purge-flag contract defined in [Architecture → /electronic_forms/ layout (§3)](electronic_forms_SPEC.md#sec-architecture), including operational toggles for uploads/log retention.

**Acceptance**

- Multiple `Config::get()` calls in a request are safe; first triggers bootstrap only.
- Unit tests: snapshot immutability across components; default resolution; missing keys handled per spec.
- Uninstall integration tests assert the `defined('WP_UNINSTALL_PLUGIN')` guard, require the Config bootstrap, and respect purge-flag decisions per [Architecture → /electronic_forms/ layout (§3)](electronic_forms_SPEC.md#sec-architecture).

---

## Phase 2: Security helpers (`mint_hidden_record`, `mint_cookie_record`, `token_validate`)

**Goal:** Make helpers the single source of truth for identifiers, TTL, persistence, and policy evaluation. Keep them header-agnostic (except explicit inputs), pure (no side effects except documented writes), and aligned to generated matrices.

**Delivers**

- `Security::mint_hidden_record(form_id)`
  - Persist `tokens/{h2}/{sha256(token)}.json` `{mode:"hidden", form_id, instance_id, issued_at, expires}`.
  - Never rewrite on rerender; base64url `instance_id` (16–24 bytes).
- `Security::mint_cookie_record(form_id, slot?)` (header-agnostic)
  - Miss/expired → mint `eid_minted/{form_id}/{h2}/{eid}.json` with `{mode:"cookie", form_id, eid, issued_at, expires, slots_allowed:[], slot:null}`.
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
- Error semantics: IO bubbles as hard failures; no header emission here.

**Acceptance**

- Golden tests mirroring matrix rows (hard/soft/off/challenge).
- Hidden-mode NCID fallback when allowed; no rotation before success.
- Regex guards for tokens/EIDs run before disk.
- Changing YAML regenerates matrices and breaks tests until helper behavior matches.

---

## Phase 3: Template preflight & schema tooling

**Goal:** Make template validation deterministic before runtime so deployments catch schema mismatches, missing row groups, and envelope violations during CI instead of in production flows.

**Delivers**

- `TemplateValidator` preflight covering field definitions, row-group constraints, and envelope rules.
- Manifest/schema source of truth for template metadata referenced by Renderer, SubmitHandler, and challenge flows; runtime uses the preflighted manifest only.
- CLI/CI wiring that fails builds when templates drift from the canonical schema or omit required rows/fields.
- Ship default template assets in `/templates/forms/` and `/templates/email/` so deployments have ready-to-use form and email examples.
- Developer ergonomics: actionable diagnostics, anchor links back to spec sections, fixtures for regression tests.

**Acceptance**

- Golden fixtures for representative templates (hidden, cookie, NCID, uploads) pass preflight.
- Schema drift or missing sections produce stable error codes/messages.
- Renderer/SubmitHandler rely exclusively on the validated manifest (no ad-hoc template parsing at runtime).

---

## Phase 4: Uploads subsystem (policy, finfo, retention)

**Goal:** Implement the uploads pipeline end-to-end so token minting, MIME sniffing, retention, and garbage collection (GC) enforcement match the normative uploads spec before POST orchestration depends on it.

**Delivers**

- Accept-token generation/verification consistent with uploads matrices and `uploads.*` config (size caps, ttl, allowed forms).
- `finfo`/MIME validation and extension allow-list prior to disk persistence; reject on mismatch.
- Storage layout honoring `{h2}` sharding, `0700/0600` permissions, retention windows, and GC cron hooks.
  - `finfo`, extension, and accept-token metadata must agree before persistence (uploads tri-agreement).
- Upload-specific logging and throttling hooks surfaced to the validation pipeline.

**Acceptance**

- Fixtures covering allowed/blocked MIME types, oversize payloads, expired tokens, and retention expiry.
- Garbage-collection tooling deletes expired assets without touching active submissions.
- Upload POST paths integrate with `Security::token_validate()` outputs without bypassing snapshot/config rules.
- Reject when any of finfo/extension/accept-token disagree; log `EFORMS_ERR_UPLOAD_TYPE`.

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

## Phase 6: `/eforms/prime` endpoint (single source of positive `Set-Cookie`)

**Goal:** Own mint/refresh and the positive `Set-Cookie` decision using the **unexpired match** rule; keep helpers header-agnostic.

**Delivers**

- Calls `Config::get()` then `mint_cookie_record(form_id, slot?)`.
- Load/update record; **Set-Cookie decision**:
  - **Send** positive `Set-Cookie` when request **lacks an unexpired match** (mint/remint; expired/missing record; cookie omitted/malformed/mismatched).
  - **Skip** only when an identical, unexpired cookie is present (same Name/Value/Path/SameSite/Secure).
- Set-Cookie attrs (normative): `Path=/`, `Secure` (HTTPS only), `HttpOnly`, `SameSite=Lax`, `Max-Age` = TTL on mint, or remaining lifetime on reissue.
- Response: `204` + `Cache-Control: no-store`.
- No header emission elsewhere except deletion per matrices (rerender/PRG).

**Acceptance**

- Matrix-conformant behavior for cookie-less hits.
- Cookie-less hit reissues positive `Set-Cookie`.
- Identical, unexpired cookie ⇒ skip header emission.
- Reissue uses remaining-lifetime (`record.expires - now`) for `Max-Age`.
- Renderer never emits Set-Cookie; `/eforms/prime` not called synchronously on GET.
- Never rewrite `issued_at/expires` on hit; only slot unioning is persisted here later (Phase 10).
- Tests for attribute equality & remaining-lifetime logic.

---

## Phase 7: Renderer → SubmitHandler → challenge → success (PRG)

**Goal:** Implement the canonical lifecycle: render → persist → POST → challenge/NCID rerender → normalization/validation → ledger → success, strictly following generated tables and invariants.

**Delivers**

- **Renderer (GET)**
	- Hidden-mode: embed payload from `mint_hidden_record()`.
	- Cookie-mode: deterministic markup + prime pixel `/eforms/prime?f={form_id}[&s={slot}]`; **renderer never emits Set-Cookie**.
	- Never call `/eforms/prime` synchronously; priming via pixel on follow-up nav.
- Provide the WordPress shortcode/template tag entry points required by the request lifecycle, bootstrap them through the frozen configuration snapshot, and document caching guidance (including `Vary: Cookie` scoped to `eforms_s_{form_id}`) alongside renderer bootstrap behaviors.
- **SubmitHandler (POST)**
	- Orchestrates: Security gate → Normalize → Validate → Coerce → Ledger → Side effects.
	- **Ledger reservation runs immediately before side effects.**
	- Cookie handling and NCID transitions per matrices; **no mid-flow mode swaps**.
	- Error rerenders reuse persisted record; follow NCID rerender contract (delete + re-prime) where required.
	- Throttling & redirect-safety & suspect handling (per §§9–11).
	- Spam decision flow enforced per [Validation & Sanitization Pipeline → Spam Decision (§8)](#sec-spam-decision): hard-fail checks fire before soft-fail scoring, `soft_reasons` are deduplicated before counting, and suspect signals wire `X-EForms-Soft-Fails`/`X-EForms-Suspect` headers plus subject tagging from the `spam.*` config (`spam.soft_fail_threshold`, header/subject toggles) alongside throttle/challenge paths. Challenge clears only the documented labels (e.g., removing the `"cookie_missing"` soft reason on success) before recomputing.
	- Honeypot modes, minimum fill timing, max-form-age soft enforcement, and JS gating (`js_ok` plus `security.js_hard_mode`) run through dedicated helpers with golden tests so anti-abuse behavior is explicit rather than implied.
- **Challenge**
	- Implement `challenge.mode` states (`off`/`auto`/`always`) and align `require_challenge` evaluation with [Adaptive challenge (§12)](#sec-adaptive-challenge).
	- Provider selection supports `turnstile`, `hcaptcha`, and `recaptcha` with server-side verification via the WP HTTP API, request parameter mapping, and timeout handling per [Adaptive challenge (§12)](#sec-adaptive-challenge).
	- Honor lazy-load boundaries: render widgets only during POST rerenders or verification passes and defer configuration reads until after the snapshot is primed (see [Adaptive challenge (§12)](#sec-adaptive-challenge) and [Lazy-load lifecycle (components & triggers) (§6.1)](#sec-lazy-load-matrix)).
	- Wire verification hooks so rerenders consume provider responses, update `require_challenge`, and flow into the NCID rerender contract without rotating hidden tokens before success (see [Adaptive challenge (§12)](#sec-adaptive-challenge)).
	- Soft-fail when providers are misconfigured or unreachable by setting `challenge_unconfigured`, clearing `require_challenge`, and continuing via the soft-cookie path as required by [Adaptive challenge (§12)](#sec-adaptive-challenge).
	- Triggered only when `require_challenge=true`; rerenders & success follow generated NCID rerender contract; hidden token never rotates before success.
- **Success (PRG)**
	- Always `303`, `Cache-Control: private, no-store, max-age=0`.
	- **Inline**: success ticket persisted; set `eforms_s_{form_id}`; follow-up GET calls `/eforms/success-verify?eforms_submission={submission_id}` while `?eforms_success={form_id}` flag is present; verifier clears ticket & cookie, strips query.
	- **Redirect**: `wp_safe_redirect(…, 303)`.
	- **PRG deletion row**: PRG responses **delete** `eforms_eid_{form_id}` (all success handoffs) so the follow-up GET re-primes. No positive header in PRG.
- Success and rerender responses advertise caching guidance via `Vary: Cookie` scoped to `eforms_s_{form_id}` per the request lifecycle contract so intermediaries respect user-specific outcomes.

**Acceptance**

- Matrix-driven integration tests: GET rows, POST rows, rerender rows, success handoff.
- NCID-only completions enforce redirect/verifier requirement; inline forbidden when `is_ncid=true`.
- Verifier-only success path (no redirect) clears ticket/cookie and strips query params.
- Verifier MUST invalidate the ticket on first success and clear `eforms_s_{form_id}`.
- PRG deletion row covered for both cookie-mode and NCID/challenge completions.
- Origin check enforced; Referer not required.
- Acceptance suite covers challenge provider outcomes (success, failure, unconfigured, provider error) for Turnstile, hCaptcha, and reCAPTCHA per [Adaptive challenge (§12)](#sec-adaptive-challenge).
- Spam decision tests cover `soft_fail_count` = 0/1/≥`spam.soft_fail_threshold`, assert `X-EForms-Soft-Fails`/`X-EForms-Suspect` headers and subject tagging per `spam.*`, and ensure challenge success removes only the documented labels from `soft_reasons` before recomputing outcomes.
- Anti-abuse coverage asserts honeypot triggers, minimum-fill timing thresholds, `max_form_age` soft enforcement, and JS gating (`js_ok`, `security.js_hard_mode`) across GET/POST/success paths.
- Success/PRG responses set `Vary: Cookie` and surface the documented shortcode/template tag caching guidance in fixtures mirroring the request lifecycle.

---

## Phase 8: Emailer (fatal-on-send-failure semantics)

**Goal:** Deliver mail reliably and make send failures fatal in a user-friendly, deterministic way that preserves dedupe correctness.

**Delivers**

- SMTP/PHPMailer integration; DKIM optional; strict header sanitation; plain text default; HTML allowed via config.
- Attachments policy; enforce size/count caps before send.
- Staging safety (`email.disable_send`, `email.staging_redirect_to`, `X-EForms-Env: staging`, `[STAGING]` subject).
- **Failure semantics (normative):**
  - If `send()` returns false/throws: abort success PRG, surface `_global` error **“We couldn't send your message. Please try again later.”**, respond **HTTP 500**, log at `error`, **do not** mutate cookie/hidden records, **do not** emit any Set-Cookie, keep original identifier for rerender, **rollback ledger reservation** so user can retry.
  - Error code: `EFORMS_ERR_EMAIL_SEND`.
- Success logs at `info`.

**Acceptance**

- Transport failure tests: retries/backoff (per config), error surfaced, 500 status, ledger unreserved, no cookie changes.
- No Set-Cookie (positive or deletion) emitted on email-failure rerender.

---

## Phase 9: Logging, Privacy, Error handling, Assets, Throttling & Validation pipeline

**Goal:** Cross-cutting correctness, observability, and user experience consistent with §§8–11, 14–16, 20, 22.

**Delivers**

- **Logging (§15)**
  - Modes: `jsonl` / `minimal` / `off`; rotation/retention; `logging.level` (0/1/2); `logging.pii`, `logging.headers`.
  - Required fields: timestamp, severity, code, `form_id`, `submission_id`, `slot?`, `uri (eforms_*)`, privacy-processed IP, spam signals summary, SMTP failure reason.
- **Request correlation id** `request_id` (filter → headers → UUIDv4); included in all events; email-failure logs MUST include it.
  - Optional Fail2ban emission.
- **Privacy & IP (§16)**: `none|masked|hash|full`; trusted proxy handling; consistent email/log presentation.
- **Validation pipeline (§8)**: normalize → validate → coerce; consistent across modes; stable error codes.
- Runtime size-cap enforcement (`RuntimeCap`) clamps POST bodies per `security.max_post_bytes`, PHP INI (`post_max_size`, `upload_max_filesize`), and `uploads.*` overrides; guards `CONTENT_LENGTH` and coordinates with upload slot validation (see [POST Size Cap (§6)](#sec-post-size-cap)).
- **Redirect safety (§9)**, **Suspect handling (§10)**, **Throttling (§11)** with headers (e.g., `Retry-After`) & soft/hard outcomes.
- **Error handling (§20)**: `_global` + per-field; stable codes; NCID/hidden metadata returned for rerenders.
- **Assets (§22)**: enqueue only when rendering; JS usability helpers; accessibility focus guidance.

**Acceptance**

- Snapshot of logs in each mode; PII redaction verified.
- Throttle thresholds; suspect flags; redirect allow-list.
- `request_id` asserted in JSONL and minimal outputs (meta blob).
- A11y tests for focus/error summary.
- RuntimeCap fixtures simulate oversized payloads, boundary values, and upload interactions to confirm clamps, validation errors, and `CONTENT_LENGTH` guards align with the authoritative spec calculations.

---

## Phase 10: Slots (unioning & enforcement)

**Goal:** Add slot semantics **after** core cookie flow is stable; keep unioning isolated to `/eforms/prime` and validation to POST.

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

## Cross-phase Guarantees & Matrices Conformance

- **Canonical sources:**
- Cookie policy outcomes (§7.1.3.2), Cookie-mode lifecycle (§7.1.3.3), Cookie header actions (§7.1.3.5), NCID rerender lifecycle (§7.1.4.2).
- Implementation and tests must treat generated tables as higher authority than narrative.
- **Matrices conformance harness:** CI tests load generated tables and assert behavior so spec/YAML changes fail when implementations drift.
- **Header boundary:** Only `/eforms/prime` emits positive `Set-Cookie`; deletion headers occur on rerender & PRG rows as specified; no positive header in PRG.
- **No rotation before success:** Identifiers (hidden/cookie/NCID) remain pinned until the success path triggers documented rotations.
- **Security invariants:** Regex guards before disk; tamper paths hard-fail; error rerenders reuse persisted records; NCID fallbacks preserve dedupe semantics; enforce origin-only CSRF boundary; audit cookie attributes (Path=/, SameSite=Lax, Secure on HTTPS, HttpOnly).
