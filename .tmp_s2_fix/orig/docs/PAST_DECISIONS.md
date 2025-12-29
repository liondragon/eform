# PAST DECISIONS

Architectural decisions made during Electronic Forms spec development.

## Design Principles

- **Origin-only CSRF**: Use Origin header (not Referer) as CSRF boundary.
- **Hidden tokens for idempotency**: Tokens prevent duplicate submits; CSRF defense is Origin's job.
- **No nonces**: Complexity/expiry issues; incompatible with caching.
- **No double-submit cookies**: Requires JS; not needed here.
- **wp_kses_post() for HTML fields**: Leverage WP's maintained allow-list; snapshot test catches behavior shifts.

## Architecture

- **No PSR-4**: WordPress-style includes keep bootstrap explicit.
- **Static config**: `Config::bootstrap()` provides immutable per-request snapshot.
- **File-backed state (no DB writes)**: Tokens/ledger/throttle live under `wp_upload_dir()`; avoids schema/migrations and keeps runtime dependencies minimal.
- **Mode-authoritative tokens**: No cross-mode fallback; POST cannot change modes.
- **No FormManager**: Split between FormRenderer and SubmitHandler.

## Major Simplifications

### Removed: Cookie-mode, NCID, Slots, `/eforms/prime`

**Was**: 700+ lines covering cookie lifecycle matrices, slot union logic, NCID rerender contracts for edge cases (cookie-disabled users, cached pages, 12 form instances).

**Now**: Single `/eforms/mint` endpoint returns unique tokens per form instance. Requires JS for cacheable pages. Reduces spec ~280 lines.

### Removed: Success Verification System

**Was**: Ticket files, success cookies, `/eforms/success-verify` endpoint, GC—all to prevent users from visiting success URLs directly.

**Now**: Query parameter only. Success messages are idempotent (like GitHub, Stripe, Gmail).

## Design Choices

### Kept: Soft Signal Scoring

Weighted accumulation of spam signals (honeypot/timing/origin/challenge) with threshold. Provides tuning flexibility and visibility into "almost spam" patterns. Hard gates lose this insight.

### Kept: Synchronous Email (v1.0)

Async email deferred to v1.1+. On failure, user sees error immediately and can retry. No new subsystems.

### Applied: Manual GC Scheduling

The plugin MUST NOT schedule WP-Cron. Operators run `wp eforms gc` via system cron (or an equivalent external trigger). This keeps runtime request paths predictable and avoids surprise background scheduling on shared hosting.

### Applied: Email-Failure Retry Marker

On email-failure rerender, renderer includes `eforms_email_retry=1`, and Timing Checks skip `min_fill_time` on the subsequent POST. This is UX-driven to allow immediate retries after a server-side failure; it is not a security boundary because the client can assert it.

### Applied: Throttle + Privacy Clarifications (s2.diff, s22.diff)

- Fixed spec contradiction about minting helpers and throttle checks
- Added `[THROTTLE_SOFT_THRESHOLD]` anchor, form-ID fanout guard, `/eforms/mint` HTTP response codes
- IP keying decoupled from `privacy.ip_mode` (rate limiting uses resolved IP regardless)
- Email failure UX normative (pre-fill + readonly textarea)

**Deferred**: Making throttle mandatory, deleting `cooldown_seconds`/`hard_multiplier` config keys.
