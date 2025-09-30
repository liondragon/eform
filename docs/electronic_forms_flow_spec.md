# Specification: Electronic Forms Flow Builder

## Goal
Deliver a dependency-free WordPress plugin that renders, validates, and submits electronic contact forms from JSON templates while maintaining the deterministic pipeline, anti-spam protections, and lazy-loading architecture defined in the canonical spec.

## User Stories
- As a site integrator, I want to register forms via JSON templates so that marketing teams can launch forms without coding PHP.
- As a site integrator, I want the renderer to output semantic, accessible markup so that forms meet WCAG AA expectations by default.
- As a security reviewer, I want duplicate submission prevention and challenge modes with the canonical lazy-loaded provider escalation so that bot and replay traffic is mitigated without permanently embedding CAPTCHAs.
- As an operations engineer, I want deterministic logging and email delivery so that submissions can be audited reliably.
- As a developer, I want a lazy-loaded configuration snapshot so that performance stays consistent across low-traffic WordPress deployments.

## Core Requirements

### Functional Requirements
- Load configuration from `templates/*.json` with schema parity between renderer, validator, and sender.
- Render single-page forms with shared templates for before/after HTML snippets, field definitions, and success routing.
- Normalize, validate, and coerce inputs using deterministic helper contracts; reject submissions that fail schema or anti-spam checks.
- Generate and verify one-time submission tokens using the file-backed ledger for replay protection.
- Support challenge helpers (honeypot, timing, question/answer) referenced by matrix metadata and escalate to provider-backed challenges (Turnstile, hCaptcha, reCAPTCHA) using the canonical lazy-load lifecycle when `require_challenge=true`.
- Honor both hidden-token and cookie anti-duplication modes defined in the canonical matrices; expose `/eforms/prime` as the only endpoint that mints or refreshes the cookie identifier and keep success flows deletion-only.
- Deliver submission payloads via transactional email with configurable subject, recipients, and body merge fields.
- Persist submission summaries to rotating log files with predictable naming and retention rules.
- Handle success responses via inline Turbo-like PRG redirect or success verification endpoint without storing server sessions.
- Enqueue minimal CSS/JS assets scoped to forms while avoiding third-party libraries.
- Provide JSON-driven opt-ins for optional features (uploads, logging verbosity, additional spam filters) without altering the default contract.

### Non-Functional Requirements
- Maintain deterministic behavior across PHP 8.2+ environments with no database migrations.
- Avoid introducing external runtime dependencies (no Redis, queues, or SaaS anti-spam services).
- Keep request handling idempotent; repeated POSTs with identical payloads either succeed once or surface a duplicate warning.
- Preserve strict separation between rendering, submission handling, security helpers, emailer, logging modules, and the lazy-loaded challenge bridge.
- Lazy-load challenge provider assets/scripts so they initialize only on rerender or verification flows that require them.
- Adhere to WordPress coding standards for hooks, enqueueing, and file organization.
- Sustain sub-100ms server processing for typical form submissions under light traffic.
- Ensure accessibility with labeled inputs, aria messaging, and keyboard navigability.
- Provide fully documented JSON schema and helper contracts for downstream automation.

## Visual Design
- Render forms using existing Tailwind-like utility classes defined in plugin assets for spacing, typography, and buttons.
- Use inline validation messaging beneath fields with color tokens that satisfy contrast requirements.
- Keep layouts fluid and single-column, respecting theme breakpoints without custom media queries.
- Maintain unobtrusive success and error banners with iconography consistent with existing assets.

## Reusable Components

### New Components Required
- **Configuration Loader**: Lazy `Config::get()` snapshot that seeds registries for forms, challenges, transports, and fields.
- **Renderer**: Template-driven HTML generator that respects before/after HTML fragments and shared partials.
- **Validator**: Field registry enforcing normalization, coercion, and validation rules per schema metadata.
- **Submit Handler**: Pipeline that orchestrates anti-spam checks, token verification, email delivery, and logging.
- **Security Helpers**: Honeypot, delay, and Q/A challenge modules described in security matrices.
- **Challenge Provider Bridge**: Lazy-loaded adapter that renders provider widgets only when `require_challenge=true` and verifies provider responses via the canonical helper contract (Turnstile, hCaptcha, reCAPTCHA).
- **Transport Layer**: Email sending abstraction with pluggable adapters and deterministic formatting.
- **Logging Facility**: File-backed writer with rotation, redaction, and audit trail guarantees.
- **CLI/Script Utilities**: Spec-lint and config validation scripts under `scripts/` to support template authors.
- **Template Authoring Guide**: Companion documentation explaining schema fields, helper references, and deployment toggles.
- **Automation Hooks**: Optional CLI command to regenerate configuration snapshots or validate template integrity pre-deploy.
- **Extended Analytics Hook**: Opt-in callback for forwarding sanitized submission metadata to observability tools without PII leakage.

## Technical Approach

### Configuration & Storage
- Store canonical templates in version-controlled JSON files; no runtime editing or WordPress admin UI.
- Cache the parsed configuration snapshot in memory per request using lazy singleton access, invalidated when templates change.
- Maintain the file-backed submission token ledger and log directory under `wp-content/uploads/eforms/`, ensuring atomic writes.

### Request Lifecycle
- Use WordPress rewrite rules to route `GET /eforms/render` and `POST /eforms/submit` through the plugin bootstrap.
- Provide the `/eforms/prime` controller for cookie-mode priming and `/eforms/success-verify` for verification redirects, both of which call `Config::get()` before delegating to helpers.
- Invoke `Config::get()` at each entry point, then dispatch to renderer or submit handler with deterministic dependency injection.
- Apply middleware ordering: normalization → validation → security challenges → token verification → email/log dispatch → response builder.
- Trigger provider-backed challenges only after `Security::token_validate()` returns `require_challenge=true`, rendering widgets on rerender or verification paths per the lazy-load matrix.
- Maintain NCID pinning on fallback/challenge rerenders, sending the deletion header on rerender/verification responses and relying on the follow-up prime pixel to reprovision the same persisted record.
- Keep `/eforms/prime` responsible for emitting the positive `Set-Cookie` header only when the request lacks an unexpired match; success, rerender, and verification responses issue deletion headers and rely on the follow-up prime call to reprovision the identifier.
- Return JSON or HTML responses based on request context, keeping all responses cache-safe and never storing session state.

### Submission Modes & Shortcode Integration {#sec-submission-modes}
- Hidden-token mode (default) runs end-to-end without priming, embedding the anti-duplication token as a hidden input during the initial `[eform id="{form_id}"]` render and expecting the shortcode to hydrate it on every rerender or validation failure.
- Cookie mode is explicitly requested via shortcode attributes (for example `[eform id="{form_id}" mode="cookie"]`) or template metadata and requires the render pipeline to output the deterministic `/eforms/prime` pixel so browsers fetch or refresh the cookie identifier before any submission attempt.
- Shortcode handlers must expose both modes so integrators can choose deterministic hidden tokens for static marketing pages or cookie pinning for flows that need replay detection across multiple browser tabs or asynchronous resumptions.
- Both modes share the same renderer, validator, and submit handler contracts; the shortcode simply wires the mode selection into the request lifecycle so the correct priming (hidden field vs. pixel fetch) executes without diverging from the canonical anti-duplication matrices.

### Frontend Behavior
- Serve a minimal JavaScript snippet to manage inline errors, progressive enhancement of success banners, and optional challenge timers.
- Lazy-load challenge widgets and verification fetches only when server responses flag `require_challenge=true`, ensuring initial GET renders remain provider-free.
- Embed deterministic prime pixels (`/eforms/prime?f={form_id}[&s={slot}]`) on cookie-mode renders, rerenders, redirects, and challenge continuations so the browser reissues the anti-duplication cookie before the next POST.
- Avoid client-side templating; rely on server-rendered HTML with unobtrusive enhancements only.
- Support async submission via fetch when available, falling back to classic form POST without breaking accessibility.

### Testing & Validation
- Unit tests for configuration parser, validator helpers, challenge helpers, and transport adapters.
- Integration tests that simulate end-to-end submissions with success, validation failure, spam rejection, and duplicate detection paths.
- Static analysis via PHPStan and coding standard checks configured in `composer.json`.
- Spec-lint automation ensuring narrative, helper contracts, and matrices remain in sync.
- Manual QA checklist covering accessibility, anti-spam bypass attempts, and logging verification.

## Out of Scope
- Multi-step or multi-page form flows.
- Authenticated dashboards, user accounts, or saved drafts.
- Additional anti-spam SaaS integrations or CAPTCHA providers beyond the canonical Turnstile, hCaptcha, and reCAPTCHA bridge.
- Database persistence beyond file-based tokens and logs.
- Visual form builders or WordPress admin configuration pages.
- Drag-and-drop field ordering or WYSIWYG template editing.
- Localization/i18n beyond static strings provided in templates.

## Success Criteria
- Forms render and submit correctly using only JSON-defined templates across supported sites.
- Duplicate submissions are prevented via ledger-backed tokens without harming legitimate users.
- Security helpers and validation matrices align with canonical spec outcomes.
- Submission emails and logs match deterministic formatting required for audits.
- All new documentation passes spec-lint and maintains anchor/canonicality rules.
- Plugin operates within performance budget on commodity WordPress hosting with no new dependencies.
