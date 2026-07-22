# Same-Document Form Recovery Implementation Plan

Scope: Preserve a visitor's in-progress form after correctable submission failures and ambiguous network outcomes. First repair the existing server-rendered fallback so safe non-file values survive a rerender on every form. Then make JavaScript same-document submission primary only for staged-photo forms: correctable outcomes update error/challenge UI without replacing the document, ambiguous outcomes keep the draft available for retry under the existing token/ledger rules, and accepted or terminal result outcomes navigate to the existing result page. Uploaded photo cards and browser-local previews remain intact during ordinary validation correction.

Source of Truth:
- AI conversation in this thread: ordinary validation failures keep every answer and uploaded photo; the normal enhanced staged-photo path does not replace the document; invalid fields receive inline and summary errors; ambiguous failures keep progress and permit retry through the existing token/ledger model; accepted and terminal results navigate; no draft store, new submission endpoint, frontend framework, terminal-outcome persistence, or pre-finalization preview route is added.
- `docs/overview.md`: current public submission, challenge, result-page, and browser-enhancement behavior.
- `docs/Architecture_Router.md`: Rendering, Submission, Validation, Browser Assets, and Managed Upload ownership.
- `docs/Owner_Index.md`: `FormProtocol`, structured errors, public copy, rendering, submission, and managed-upload seams.
- `docs/contracts/Public_Contracts.md`: final form credential transport, error/result behavior, browser assets, challenge exposure, and staged-upload recovery.
- `docs/contracts/Runtime_Storage.md`: token/ledger ordering, cache safety, and staged recovery deadlines.

Host Contracts:
- `SubmitHandler` remains the sole public POST pipeline orchestrator. Security, normalization, validation, coercion, challenge, ledger, upload finalization, and email ordering do not move into JavaScript or the HTTP adapter.
- `PublicRequestController` owns public request negotiation and maps structured submission results to HTML, redirect, or enhanced JSON responses. It is an adapter over one submission service, not a second state machine.
- `FormProtocol` owns every PHP/JavaScript header and response-field name introduced by enhanced submission.
- `FormRenderer` owns server-rendered controls, safe redisplay, the existing error summary/inline-error markup, and the non-JavaScript fallback.
- `forms.js` owns progressive enhancement, submit locking, error focus, dynamic challenge activation, and the staged-upload runtime. It must not duplicate authoritative validation or upload state.
- `Errors` and `ErrorMessages` remain the structured-error and public-copy owners. Enhanced responses expose only safe public messages.
- `UploadBatchStore` and `UploadBatchEndpoint` remain authoritative for staged upload state. Enhanced submission must not add a customer artifact-read route or mutate manifests directly.
- Successful and email-failure result destinations remain owned by `Success`; enhanced submission does not invent redirect destinations.
- `Success` must provide a side-effect-free result-location method. HTML handling may redirect to that location; JSON handling serializes it. `PublicRequestController` must not construct result URLs itself.

Not Behavior Authority: yes. This plan is a transient execution artifact. Active carriers, code, and tests remain behavior authority.

Retirement Trigger: Retire this plan after implementation is complete, focused and broad verification are recorded in command output and final handoff, live WordPress enhanced submission is exercised or explicitly classified as external-gated, and durable behavior is synchronized into `docs/overview.md`, `docs/Architecture_Router.md`, `docs/Owner_Index.md`, and `docs/contracts/Public_Contracts.md`. Review `docs/contracts/Runtime_Storage.md` for contradictions, but do not edit it unless token, ledger, or staged-storage semantics actually change.

## Non-Goals

- Do not add server-side drafts, database persistence, `localStorage`, IndexedDB, or session-backed customer-value recovery.
- Do not persist staged batch secrets beyond their existing document/request lifecycle.
- Do not add a new REST submission endpoint, page-specific endpoint, or alternate validation pipeline.
- Do not add a pre-finalization photo read/preview route.
- Do not add a client-created idempotency key, terminal-outcome store, success-verification store, request fingerprint store, or customer-content persistence. Existing hidden tokens and ledger markers remain the only submission idempotency mechanism for this slice.
- Do not add React, Vue, HTMX, a DOM-morphing library, or a second browser runtime.
- Do not repopulate file controls, passwords, honeypots, security fields, or malformed values that do not match the field descriptor shape on the server-rendered fallback.
- Do not change email-failure semantics after ledger reservation: submitted values remain undisclosed and no mail retry submission is offered.
- Do not add a user-facing configuration switch. Progressive enhancement applies when the required browser APIs and complete renderer-owned protocol settings are available; otherwise the existing HTML submission remains the fallback.
- Do not promise refresh, browser-crash, cross-tab, cross-device, or multi-day draft recovery.

## Verification Baseline

Baseline date: 2026-07-24.

Automated Harness:
- Canonical PHP suite passed.
- WordPress runtime hidden-mode smoke passed.
- Playwright passed 48 tests; 5 environment-gated live/performance tests skipped with no failures.

Verification Command:

```bash
php tests/integration/test_enhanced_submission.php \
  && php tests/integration/test_accessibility_error_summary.php \
  && php tests/integration/test_form_protocol_contract.php \
  && php tests/integration/test_post_pipeline_ordering.php \
  && php tests/integration/test_staged_submission.php \
  && php tests/integration/test_challenge_rerender_only.php \
  && php tests/unit/test_protocol_seam_guards.php \
  && npm test --prefix tests/e2e \
  && git diff --check
```

Broad Gate after focused green:

```bash
find tests/unit tests/integration tests/smoke -type f -name 'test_*.php' -print0 | sort -z | xargs -0 -n1 php \
  && php tests/wp-runtime/run.php
```

Live Gate when `EFORMS_E2E_STAGED_PAGE_URL` is available:

```bash
EFORMS_E2E_STAGED_PAGE_URL="$EFORMS_E2E_STAGED_PAGE_URL" npm test --prefix tests/e2e -- specs/staged_upload_live.spec.js
```

The live gate is required before production deployment but is not evidence that local automated tasks failed when the external URL is unavailable. Human review remains required before deployment.

## Discovery Snapshot and Contract Drift Triage

- `Validator::validate()` already returns the normalized value map on both valid and invalid results.
- `SubmitHandler::validation_result()` currently discards that map, while `PublicRequestController::failure_response()` and `FormRenderer` already accept a `values` option. Lost non-file answers are therefore a defect in the existing rerender seam, not missing renderer capability.
- `forms.js` intentionally restores staged cards without object URLs after a full navigation. `docs/contracts/Public_Contracts.md` and the current live E2E test encode **preview unavailable** as the expected restored state.
- The approved greenfield behavior supersedes full navigation only for enhanced staged-photo correctable and ambiguous outcomes. The server-rendered path remains an accessibility and capability fallback, where an unavailable browser-local thumbnail remains truthful.
- A fetch failure after the request reaches WordPress is ambiguous: the browser cannot know whether email was delivered, and the staged batch-status endpoint does not expose ledger or email state. The enhanced path must not promise that nothing was sent or claim to inspect ledger retry permission; it keeps the DOM intact, reconciles only upload state, and tells the visitor that the send could not be confirmed.
- `docs/PAST_DECISIONS.md` assigns duplicate-submit idempotency to hidden tokens and records removal of the success-verification system. This plan must not reintroduce a parallel idempotency key or terminal-outcome persistence without a separate product decision and storage contract.
- Before implementation, `P1.T1` updates permanent carriers with the exact enhanced-response contract below. Implementation must not treat this transient plan as the durable response authority.

Authority Decision: the conversation-approved same-document behavior is the new product source; current full-navigation tests and prose become stale only where they claim that JavaScript-enhanced validation must navigate. Token, ledger, challenge, spam-stealth, upload authority, result-page, cache, and no-preview-read invariants remain authoritative.

## Phase Execution Brief — Enhanced Final Submission Seam

Existing Owner Evidence:
- `src/Submission/SubmitHandler.php` already returns structured pipeline results before HTTP rendering.
- `src/Submission/PublicRequestController.php` already selects local rerender, redirect, result page, and raw response behavior.
- `src/FormProtocol.php` already publishes shared PHP/browser protocol settings.
- `src/Rendering/FormRenderer.php` already owns safe escaped values, error summary links, inline errors, challenge markup, and result-safe rendering.
- `assets/forms.js` already owns submit locking, error focus, JS minting, staged credentials, upload freeze/restore, and browser-local previews.

Docs Consulted:
- `agent_docs/Implementation_Plan_Guide.md`
- `agent_docs/ui_surface_preflight.md`
- `agent_docs/Cross_Cutting_Concerns.md`
- `docs/Architecture_Router.md`
- `docs/Owner_Index.md`
- `docs/overview.md`
- `docs/contracts/Public_Contracts.md`
- `docs/contracts/Runtime_Storage.md`

Reuse Decision: extend the existing result, request-controller, protocol, renderer, and browser-runtime owners. Add no new production owner or endpoint.

Boundary Decision: extend existing owners into one submission service with two adapters.
- Keeping the behavior only in `forms.js` is worse because security, challenge, spam-stealth, error copy, and final result decisions must remain server-authoritative.
- Keeping it only in the HTML rerender is worse because replacing the document necessarily destroys the browser draft and object URLs.
- Introducing a new submission service or REST endpoint is worse because `PublicRequestController` already owns the final POST boundary and a second endpoint would duplicate routing, cache, security, and result semantics.

Reuse Target:
- Domain result: `SubmitHandler`.
- HTTP negotiation and safe response shaping: `PublicRequestController`.
- Shared names: `FormProtocol::browser_settings()`.
- HTML fallback: `FormRenderer`.
- Enhanced interaction: `forms.js` using the existing form, error, challenge, spinner, and uploader DOM.
- Result locations: `Success`, using one side-effect-free location method shared by HTML redirects and JSON responses.
- Challenge provider behavior: `Challenge`, with `FormRenderer` projecting public metadata and `forms.js` consuming it.

No-Fallback Rule / Kill List:
- No second submission URL or controller.
- No client copy of validation rules or public error-code mappings.
- No hardcoded enhanced wire names outside `FormProtocol` and its generated browser settings.
- No full-form HTML swap or DOM morph for an enhanced correctable or ambiguous response.
- No uploader teardown/recreation during an enhanced correctable or ambiguous response.
- No customer values, staged credentials, security tokens, provider secrets, filesystem paths, or artifact locators in enhanced JSON.
- No persistence-backed draft recovery, customer artifact preview endpoint, client-created idempotency key, or terminal-outcome store.

New Artifact Budget:
- Production source files: zero new files.
- Tests: at most `tests/integration/test_enhanced_submission.php` and `tests/e2e/specs/enhanced_submission.spec.js`; extend existing live and contract tests for all remaining proof.
- No new stylesheet is expected. Extend `assets/forms.css` only if the existing error-summary, inline-error, challenge, and spinner primitives cannot express the approved states.

LOC Budget:
- Production code: target no more than 350 net added lines across existing owners.
- Tests and fixtures: target no more than 500 net added lines.
- If either budget is exceeded or a new production artifact appears necessary, stop and rejustify the owner boundary in this plan before continuing.

Contract Carrier Sync:
- `docs/contracts/Public_Contracts.md`: exact enhanced request/response shapes, statuses, privacy, challenge, retry, navigation, and fallback behavior.
- `docs/overview.md`: visitor-visible preservation, validation, retry, and success behavior.
- `docs/Architecture_Router.md`: expand the existing Browser Assets and Public POST descriptions; do not add a subsystem.
- `docs/Owner_Index.md`: record the enhanced final-submission seam and its verification hook.
- `docs/contracts/Runtime_Storage.md`: review only. Unchanged token/ledger/staged recovery semantics should not get new prose.

Owner_Index Change: add or tighten one owner row naming `PublicRequestController` as the enhanced HTTP response owner, `FormProtocol` as the wire-name owner, and `forms.js` as the browser consumer. Do not create another canonical owner.

Phase Ownership Charter:
- Canonical Owner: `PublicRequestController` owns response selection; `SubmitHandler` owns `retry_allowed`; `Success` owns result locations; `Challenge` owns provider behavior; `FormProtocol` owns wire names; `forms.js` consumes the enhanced response and owns DOM updates.
- Allowed Seams: `SubmitHandler::handle()` structured results including `retry_allowed`, side-effect-free `Success` location resolution, `Challenge` public metadata projection, `PublicRequestController` response metadata, `FormProtocol::browser_settings()`, existing form DOM, and existing uploader runtime state.
- Kill List: new endpoint, duplicated validator, duplicated error-copy map, response-carried customer values/credentials, HTML form swap, draft store, and customer preview route.

Contract Carriers to Re-evaluate:
- Consumer families: staged-photo forms with JavaScript, cacheable/hidden-token scalar forms that remain on HTML fallback, no-JavaScript hidden-token forms, synchronous upload forms, challenge rerenders, spam-stealth results, success pages, and email-failure pages.
- Legacy assertions: tests that require JavaScript validation failure to navigate or require restored **preview unavailable** cards in the ordinary enhanced path.
- Retained assertions: server fallback rerender, arbitrary-refresh no-recovery, terminal token behavior, no preview read route, exact staged credential binding, result-page redirects, and email-failure no-retry/no-values behavior.

Guard Strategy:
- Extend `tests/unit/test_protocol_seam_guards.php` so enhanced header and response keys originate in `FormProtocol`, and so the browser has no local wire-name fallback.
- Keep server fallback tests positive rather than globally banning rerender code.
- Add E2E negative assertions for no navigation on staged-photo correctable outcomes, no upload retransfers, no preview recovery request, no uploader node replacement after enhanced correctable/ambiguous outcomes, honest copy when the browser cannot confirm whether submission was sent, and no first-party enhanced interception on scalar-only forms.

Seam Guard: `php tests/unit/test_protocol_seam_guards.php`; expected exit `0`; run at `P2.T2`, `P2.T3`, and final closure.

Removal Proof:

```bash
! rg -n "register_rest_route.*(submit|submission)|/eforms/(submit|submissions)" src
```

Expected result: no matches; run at final closure.

## UI Surface Preflight

User job: A visitor needs to correct a form error and resend without re-entering valid answers or losing confidence that already-uploaded photos remain attached.

Task class: new/material interaction behavior inside the existing public form; field layout and visual design remain unchanged.

Existing patterns checked:
- Server-rendered `.eforms-error-summary`, `.eforms-error`, `aria-invalid`, and `aria-describedby`: canonical error hierarchy and accessibility semantics; currently tied to document replacement.
- `forms.js::focusErrors()`: canonical focus behavior; reuse after enhanced correctable responses.
- Existing submit lock/spinner: canonical pending state; extend it so correctable and allowed-retry outcomes restore the original button state.
- Existing staged uploader runtime: canonical photo cards, ordering, browser object URLs, progress, credential writing, and freeze behavior; retain the same runtime instance and add the inverse thaw transition for correctable outcomes.
- Existing `.eforms-challenge` / Turnstile widget: canonical verification surface; enhanced responses activate the same provider and site key without exposing the secret.

Options considered:

Option A — Improved server rerender only
- Pros: smallest change and strong no-JavaScript behavior.
- Cons: still replaces the document and cannot retain browser photo previews or exact as-typed DOM state.
- Reuse: existing `SubmitHandler`, `PublicRequestController`, and `FormRenderer` path.
- Best when: JavaScript is unavailable; retain as fallback.

Option B — Same-document enhanced submission
- Pros: preserves exact DOM state and photo previews, keeps server authority, and needs no draft persistence.
- Cons: requires an explicit response contract plus careful challenge, retry-disposition, and upload-runtime handling.
- Reuse: existing POST pipeline, controller, protocol settings, form/error markup, and `forms.js` runtime.
- Best when: a staged-photo form has JavaScript and required browser APIs available; selected for the initial rollout.

Option C — Persisted/autosaved drafts
- Pros: could survive reload or device loss.
- Cons: adds PII retention, bearer-secret lifecycle, cleanup, consent, storage, and cross-device identity concerns unrelated to validation correction.
- Reuse: little; would introduce a new subsystem.
- Best when: reload/cross-device recovery becomes a separate approved product requirement; deferred.

Information architecture gate:
- Primary question: “What must I fix?” Answered by the existing focusable summary and inline field errors.
- Secondary question: “Was anything lost?” Answered once by **Your progress has been kept.** and by unchanged values/photo cards; do not repeat it under every field.
- Action question: “What can I do next?” Answered by the restored Send button after correctable and allowed-retry outcomes, or the existing reload instruction for a terminal token outcome.
- Uploaded cards continue to show their existing user-facing state. Ordinary enhanced validation must not introduce **Preview unavailable** because the preview DOM is never replaced.
- **Preview unavailable** is used only when a server-authorized recovery restores an uploaded card without its browser-local preview. Arbitrary refresh/crash recovery remains out of scope.
- No protocol, token, batch, server path, diagnostic, or provider facts are shown.

Primitive map:
- Surface shell: existing `form.eforms-form`.
- Error container: existing `.eforms-error-summary[role="alert"]`.
- Inline error: existing `.eforms-error` plus `aria-invalid` / `aria-describedby`.
- Field/error mapping: renderer-owned, protocol-named attributes on the existing control or fieldset plus one stable, initially hidden error mount after the field. JavaScript can find the server field key, target control, and insertion point without reconstructing renderer IDs or field names. Do not add layout wrappers.
- Primary action: existing submit button and `.eforms-spinner`.
- Challenge: existing `.eforms-challenge` and `.cf-turnstile` semantics. `Challenge` owns Turnstile/provider behavior, verification URL, public script URL, and configuration projection; `FormProtocol` owns only shared PHP/JavaScript field, attribute, and JSON key names. `FormRenderer` emits public provider metadata and `forms.js` consumes it.
- Upload state: existing staged uploader mount, grid, cards, live region, and hidden credential writer.
- Runtime/assets: existing `assets/forms.js` and `assets/forms.css`.
- Local CSS/classes/selectors: no new CSS expected; one renderer-owned protocol attribute family is expected for field/error mapping.

UI Reuse Contract Matrix:

| Surface | Canonical shell/helpers | Runtime owners | Forbidden local seams | UI guard |
|---|---|---|---|---|
| Ordinary cacheable/hidden form | Existing form, error summary, inline errors, submit spinner | `FormRenderer`, HTML fallback | Duplicate validation, replacement form, toast-only errors | Safe redisplay PHP/WP cases |
| Staged-photo form | Ordinary form plus existing upload mount/cards/live region | `forms.js`, `UploadBatchEndpoint`, `UploadBatchStore` | Uploader reconstruction, re-upload, customer preview read route | Same-node/object-URL and zero-request assertions |
| Challenge correction | Existing error flow plus `.eforms-challenge` / `.cf-turnstile` | `SubmitHandler`, `FormRenderer`, `forms.js` | Client challenge decision, secret exposure, duplicate widget/script | Challenge-required and corrected-retry cases |
| Native capability fallback | Existing server-rendered form/rerender | `PublicRequestController`, `FormRenderer` | Enhanced-only dependency or persisted draft | PHP/WP runtime rerender cases |

Control container census:
- Container: `form.eforms-form`.
- Existing controls: descriptor-rendered fields, optional challenge, staged uploader, and one submit action.
- Existing grouping: template field order followed by challenge and submit.
- Canonical composition target: preserve current DOM order and nodes; insert/update only canonical error and challenge regions.
- Route-owned inputs: none; browser serializes the existing form controls.
- Forbidden local composition: duplicate form, replacement uploader, second submit action, modal error flow, or page-level terminal shell for a correctable outcome.
- Removal/negative proof: E2E asserts the original field, upload mount, and uploaded card nodes remain connected and identical after correctable and ambiguous responses.

Decision: use same-document enhanced submission with the current server-rendered flow as a capability fallback.

Reuse contract:
- Reuse the existing error summary, inline error, focus, submit lock, challenge, and uploader owners.
- The route may select response format and return safe outcomes; it must not own validation rules, draft persistence, upload state, or arbitrary redirects.
- The browser may update presentation and navigate to a validated same-origin result; it must not reinterpret server validation or infer upload success.

Surface contract:
- Entry point: the existing Send button.
- Pending: disable Send once and show the existing spinner while one enhanced request is active.
- Correctable invalid: keep all form and uploader DOM nodes, show **Please fix the highlighted fields. Your progress has been kept.**, render safe inline errors, focus the summary, make summary field names links to their controls, and re-enable Send.
- Ambiguous network/malformed response: keep all DOM nodes, show **We couldn't confirm whether your request was sent. Your answers and photos are still here. Please try again.**, reconcile the staged batch through its existing authenticated status path, and then follow the recovery table below. The browser never claims to inspect ledger state.
- Failure response: keep all DOM nodes, show the server-owned public message, and restore Send only when `can_retry` is true. When `can_retry` is false, preserve visible progress but block unsafe resubmission and use the existing terminal action/copy.
- Challenge required: preserve the form and uploader, load the approved Turnstile script once if absent, render one widget in a stable challenge mount using only `Challenge`-owned public provider metadata, prevent resubmission until it is ready, reset it after rejection or expiry, focus the error flow, and allow corrected resubmission. If the script fails to load or never becomes ready, keep the server-provided challenge error visible and offer a bounded verification retry or reload action; do not leave the form indefinitely disabled.
- Terminal failure: preserve visible progress but block unsafe resubmission and show the existing public terminal message/action, including reload when the token is unusable.
- Location response: navigate exactly once whenever a structured response contains a validated, server-owned same-origin location. Successful and email-failure result destinations both qualify. A failure without a location remains in the current document. Clearing happens through navigation, not before it.
- Narrow/mobile behavior: unchanged vertical form order; error summary, field errors, photo cards, challenge, and submit remain in source order with no new layout.

Upload and final-submit recovery table:

| Situation | Upload mutations | Final Send |
|---|---|---|
| Valid 422 with `upload_recovery.state=open` | Thaw | Enable |
| Valid 422 with `upload_recovery.state=finalizing_recovery` | Keep frozen | Enable corrected retry under existing ledger recovery |
| Ambiguous request, status `open` | Thaw | Enable |
| Ambiguous request, status `finalizing` | Keep frozen | Permit explicit submission retry under existing ledger recovery |
| Ambiguous status check itself fails | Keep frozen | Offer status-check retry, not blind upload mutation |
| Status 410/expired or terminal/unavailable | Block | Block and show unconfirmed/terminal copy |

`can_retry` applies only to a structured failure response. A valid 422 is intrinsically correctable. An ambiguous network failure provides neither `can_retry` nor `finalizing_recovery`; the batch-status result controls upload mutation only, while final submission retry remains an explicit user action through the existing token/ledger path.

Form layout gate: field inventory, rows, labels, control widths, and responsive order do not change. Validation help may increase vertical height only beneath its owning field. Verify the existing desktop and narrow Playwright fixtures with long labels and one inline error; no horizontal overflow or partial row reordering is allowed.

Not shown:
- Error codes when a safe message is available.
- Token, submission ID, staged credentials, retry internals, upload locators, provider secrets, or debug metadata.
- Draft/autosave status, because no draft is persisted.

Delete / do not build:
- Do not build a draft subsystem, preview endpoint, alternate submit route, duplicate validator, new page shell, modal, toast-only error path, or local stylesheet family.

Verification:
- Rendered integration proof for safe fallback values and accessible error markup.
- Local Playwright proof for same-node preservation, focus, error correction, challenge activation, retry, double-submit prevention, and success navigation.
- Live WordPress staged-upload proof for validation correction with unchanged thumbnails and zero re-upload requests.

## Enhanced Response Delta

`P1.T1` must first copy this behavior into the owning permanent contracts. Wire names are then implemented through `FormProtocol`.

Request selection:
- The browser posts the existing form action and body.
- Enhanced submission is requested only with `X-EForms-Response: json`.
- The first-party browser sends that header only for rendered staged-photo forms with complete protocol settings and required browser APIs. Scalar-only forms and unsupported browsers retain existing HTML/redirect behavior.
- The server JSON adapter is form-type agnostic: any valid eForms POST with the exact header may receive the JSON adapter. The controller must not rediscover template upload capability to reject scalar forms.
- Missing, malformed, or unsupported header values retain existing HTML/redirect behavior.

Response variants:

```text
accepted (HTTP 200)
{"ok": true, "location": string}

correctable (HTTP 422)
{
  "ok": false,
  "errors": {
    "global": [{"code": string, "message": string}],
    "fields": {"<field_key>": [{"code": string, "message": string}]}
  },
  "upload_recovery": {"state": "open" | "finalizing_recovery"} | null,
  "challenge": null | {"provider": "turnstile", "site_key": string}
}

failure (existing HTTP status)
{"ok": false, "error": {"code": string, "message": string}, "can_retry": true | false, "location": string | null}
```

Response invariants:
- All enhanced responses are JSON UTF-8 and private/no-store.
- `errors.fields` contains only validated template field keys in template order. Every entry has a stable public code and resolved safe message. The browser never owns validation rules or error-copy mappings.
- `challenge` is present only when the server requires it for a correctable outcome. Turnstile exposes the public provider and site key but never the secret. `Challenge` owns provider behavior and public script metadata; `FormProtocol` owns only the key names used to carry that metadata.
- `upload_recovery` exposes only the correction lifecycle needed by the existing uploader runtime: `open` means mutations may resume after a valid 422 response or after authenticated status reconciliation; `finalizing_recovery` means mutations stay frozen while corrected resubmission remains allowed.
- `location` is an existing `Success`-owned same-origin destination for accepted, stealth accepted, or terminal result pages. The JSON shape does not reveal which anti-spam path produced it.
- Failure retry safety is server-owned. HTTP status and `Retry-After` may orient copy/timing, but the browser restores Send only when `can_retry` is true.
- `SubmitHandler` owns the internal retry fact as `retry_allowed`. The default is false. A branch sets it true only when that exact failure window is known to permit a meaningful retry. `PublicRequestController` copies it to public `can_retry`; the controller and JavaScript must not derive retry safety from status or error code.
- A response after a durable ledger/email boundary must never offer an unsafe mail retry.
- The body never returns submitted values, upload summaries, batch credentials, security tokens, submission IDs, paths, signatures, provider URLs, or artifact facts.
- The browser validates `location` against `window.location.origin` before navigation; a missing, malformed, cross-origin, or unknown location preserves the DOM and shows the ambiguous-confirmation copy unless the server already returned a safe terminal message.
- If enhanced response emission cannot be completed safely, preserve the current document and show the ambiguous-confirmation copy. Do not fall through into an HTML body swap.

Does this add a named contract surface? Yes: one response-negotiation header, one narrow JSON envelope, one `can_retry` disposition, one renderer-owned field/error mapping attribute family, and shared protocol names for challenge metadata. They are necessary because same-document correction requires the server to communicate authoritative validation, challenge, upload recovery, retry safety, and terminal-navigation outcomes without replacing the form. No new endpoint, config key, draft store, customer artifact route, idempotency key, terminal-outcome store, or public validation method is required.

Non-regression: this response adapter must not weaken security, token/ledger ordering, spam-stealth behavior, cache safety, upload authority, same-origin redirect enforcement, or email-failure finality; it must not expand attacker-controlled persistence or artifact access.

## Operational Change Overlay

- Rollback: revert the enhanced response/controller/browser changes as one release. The repaired HTML fallback remains functional and requires no data migration.
- Blast Radius: HTML value redisplay affects all public eForms; same-document JavaScript submission initially affects only staged-photo forms with JavaScript enabled. Unsupported browsers and scalar-only forms stay on the HTML path.
- Observability: reuse existing submission logs, HTTP status, `Retry-After`, and browser network evidence. Add no customer-content logging or new metrics backend.
- Failure Mode: timeout, fetch rejection, malformed JSON, unknown outcome, or unsafe navigation location keeps the current document and exposes ambiguous-confirmation copy. Terminal token/ledger outcomes stay blocked; email failure navigates to its existing result page and never retries mail.

## Invariant Matrix

| Invariant | Positive proof | Negative proof | Task |
|---|---|---|---|
| Correctable HTML fallback retains safe non-file values | Integration/WP runtime rerender shows text, textarea, select, radio, and checkbox values | File/password/protocol/honeypot and malformed descriptor shapes are absent; email-failure page contains no values | `P1.T2` |
| Enhanced correctable response preserves the live draft and photos | Playwright receives HTTP 422 correctable errors, renders summary/inline errors through renderer-owned mounts, and keeps identical field/uploader/card nodes and object URL | No navigation, re-upload, status request, preview read, form reset, or uploader reconstruction after valid 422 | `P3.T1` |
| Ambiguous/failure handling preserves progress and respects retry safety | Playwright covers network rejection, malformed JSON, 408/429/5xx with `can_retry` true/false, status reconciliation, and successful allowed retry | No duplicate in-flight POST, cleared value, false "nothing sent" copy, unsafe Send restore when `can_retry` is false, or terminal reload state | `P2.T1`, `P2.T2`, `P3.T1` |
| Terminal outcomes cannot create unsafe retries | Controller/browser tests cover token terminal response, email-failure navigation, and `can_retry: false` after durable boundaries | No submit re-enable after terminal token; no values or mail retry after durable ledger boundary | `P2.T1`, `P2.T2`, `P3.T1` |
| Challenge remains server-authoritative | Correctable response exposes stable challenge mount and public metadata; script-load failure keeps the challenge error visible and offers bounded retry/reload; corrected retry succeeds | No secret, duplicate widget/script, challenge bypass, indefinite disabled state, or client-generated challenge decision | `P2.T3`, `P3.T1` |
| Structured location navigates exactly once | Controller returns one `Success`-owned location for accepted and email-failure result destinations; browser navigates after validating same-origin | No pre-result reset, arbitrary/cross-origin navigation, duplicate submission, or current-document terminal page when a safe location is present | `P2.T2`, `P3.T1` |
| Enhanced protocol and privacy remain centralized | Protocol and controller tests assert exact header/union plus private/no-store JSON | Seam guard finds no local wire-name fallback; JSON contains no customer values, credentials, paths, signatures, IDs, or artifact facts | `P2.T2`, `P2.T3` |
| Capability fallback remains usable | No-JavaScript/unsupported API test submits through existing HTML flow with retained safe values | Enhanced listener does not block native submission when required protocol/browser APIs are absent | `P1.T2`, `P3.T1` |

## Phase 1 — Repair and Contract the Fallback

Goal: establish the preservation invariant on the existing path and make the permanent contracts authoritative before adding the enhanced response.

Default Type: `seam-refactor`.

- [ ] P1.T1 Update active carriers with same-document and safe-fallback behavior (Source: AI conversation and active carriers)
  - Artifacts: `docs/overview.md`, `docs/Architecture_Router.md`, `docs/Owner_Index.md`, `docs/contracts/Public_Contracts.md`; review `docs/contracts/Runtime_Storage.md` only for contradictions.
  - Interfaces: the exact request/response delta above, correctable/ambiguous/terminal lifecycle meaning, visible messages, staged-only enhanced activation, capability fallback, `can_retry`, and retained upload/preview behavior.
  - Owner: public submission contract carriers; runtime ownership remains `PublicRequestController` + `FormProtocol` + `forms.js`.
  - Depends On: none.
  - Existing Owner Evidence: inherits the Phase Execution Brief.
  - Docs Consulted: inherits the Phase Execution Brief.
  - Reuse Target: tighten the existing public POST, browser asset, cache, and owner-map sections in place.
  - No-Fallback Rule: do not create a second plan/spec, endpoint contract, or duplicate browser owner.
  - Replacement: staged-photo JavaScript correctable/ambiguous full navigation becomes same-document response handling; HTML rerender remains capability fallback for scalar-only and unsupported forms.
  - Superseded Seams: only prose requiring full navigation for enhanced validation and preview loss on that normal path.
  - Complexity Budget: no new section family unless the existing public POST/browser sections cannot hold the contract; prefer replacing current rerender wording.
  - Removal Proof: stale-reference scan distinguishes enhanced behavior from retained HTML fallback and finds no contract requiring enhanced validation navigation.
  - Done When: permanent carriers define the exact response envelope, visible correctable/ambiguous/terminal behavior, customer-content exclusion and response-privacy rules, same-origin navigation, fallback redisplay, staged-only activation, renderer-owned field/error mapping, Turnstile provider ownership, uploader preservation, `can_retry`, owner mapping, and unchanged security/ledger/upload invariants without contradicting email-failure finality.
  - Verified via: `rg -n "X-EForms-Response|same-document|can_retry|idempot|retryable_failure|terminal_failure|validation.*rerender|preview unavailable" docs/overview.md docs/Architecture_Router.md docs/Owner_Index.md docs/contracts` plus a manual contradiction read of the matched sections.

- [ ] P1.T2 Preserve safe redisplay values on every correctable HTML rerender (Source: updated active carriers from `P1.T1`)
  - Artifacts: `src/Submission/SubmitHandler.php`, `src/Rendering/FormRenderer.php`, `src/Submission/PublicRequestController.php` only if response plumbing needs adjustment, `tests/integration/test_enhanced_submission.php`, `tests/integration/test_post_pipeline_ordering.php`, `tests/integration/test_staged_submission.php`, `tests/integration/test_challenge_rerender_only.php`, `tests/wp-runtime/run.php`.
  - Interfaces: internal structured result `values` for correctable rerender only; HTML field value/checked/selected state; no new public wire fields.
  - Owner: `SubmitHandler` produces safe redisplay state; `FormRenderer` renders it.
  - Depends On: `P1.T1`.
  - Existing Owner Evidence: `Validator` already returns `values`; controller/renderer already consume them.
  - Reuse Target: existing normalized/validated values and descriptor-aware renderer.
  - No-Fallback Rule: no raw `$_POST` echo helper, session draft, or renderer-local field allowlist that duplicates descriptors.
  - Replacement: missing `values` on correctable error results.
  - Superseded Seams: none; the HTML fallback is retained.
  - Complexity Budget: no new production file or public helper; prefer one safe redisplay projection in `SubmitHandler`.
  - Removal Proof: tests prove value retention through the real handler/controller/renderer path and value absence on email-failure/terminal paths.
  - Done When: validation, staged prevalidation, and challenge correction rerenders preserve every structurally safe non-file field; malformed fields alone may be blanked; file/password/protocol/honeypot values remain absent; email failure retains no values; the existing upload credentials remain governed by their exact validated rerender rules.
  - Verified via: `php tests/integration/test_enhanced_submission.php && php tests/integration/test_post_pipeline_ordering.php && php tests/integration/test_staged_submission.php && php tests/integration/test_challenge_rerender_only.php && php tests/wp-runtime/run.php`.

Phase 1 checkpoint: run a failure-branch sweep for invalid shape, required, upload error, challenge, token expiry, duplicate, email failure, and missing renderer value; map every match to `P1.T2` proof or Known Debt before starting Phase 2.

## Phase 2 — Add the Server-Owned Enhanced Response

Goal: expose one private, same-origin JSON adapter over the existing submission results without changing pipeline authority or adding an endpoint.

Default Type: `seam-refactor`.

- [ ] P2.T1 Classify retry safety and extract pure result locations (Source: updated active carriers from `P1.T1`)
  - Artifacts: `src/Submission/SubmitHandler.php`, `src/Submission/Success.php`, existing `Success` tests, `tests/integration/test_enhanced_submission.php`, `tests/integration/test_post_pipeline_ordering.php`, `tests/integration/test_staged_submission.php`, `tests/wp-runtime/run.php`.
  - Interfaces: internal `retry_allowed`; side-effect-free `Success` location resolution.
  - Owner: `SubmitHandler` for server-owned retry disposition; `Success` for result-location resolution.
  - Depends On: Phase 1.
  - Existing Owner Evidence: inherits the Phase Execution Brief.
  - Docs Consulted: inherits the Phase Execution Brief.
  - Reuse Target: existing `SubmitHandler` failure windows, token/ledger ordering, `Errors`/`ErrorMessages`, and current `Success` redirect destinations.
  - No-Fallback Rule: no idempotency key/store, no request fingerprint store, no controller-derived retry decision, no production use of test-only dry-run redirect behavior, and no result URL construction outside `Success`.
  - Replacement: status/error-code inference for retry safety; redirect-only `Success` location access.
  - Superseded Seams: none for HTML redirects; HTML handling calls the pure `Success` location method and then redirects.
  - Complexity Budget: zero new production files; stop if retry classification requires a new persistence mechanism or result-location owner.
  - Candidate Scope: internal `retry_allowed` default false, branch-local true only for exact retry-safe windows, side-effect-free `Success` location method, and focused tests for existing redirect parity.
  - Leftover Checks: every `SubmitHandler` failure exit classified; no status-derived retry inference in controller/browser; no `Success` URL construction in `PublicRequestController`.
  - Done When: every `SubmitHandler` failure exit has an explicit `retry_allowed` value defaulting false; branches set true only when the exact failure window permits meaningful retry; existing HTML redirects still work; JSON-capable callers can obtain the same `Success`-owned locations without side effects.
  - Verified via: `php tests/integration/test_enhanced_submission.php && php tests/integration/test_post_pipeline_ordering.php && php tests/integration/test_staged_submission.php && php tests/wp-runtime/run.php`.

- [ ] P2.T2 Add protocol negotiation and controller JSON shaping (Source: `P2.T1`)
  - Artifacts: `src/FormProtocol.php`, `src/Submission/PublicRequestController.php`, `src/Submission/Success.php`, `tests/integration/test_enhanced_submission.php`, `tests/integration/test_form_protocol_contract.php`, `tests/unit/test_protocol_seam_guards.php`, `tests/wp-runtime/run.php`.
  - Interfaces: `X-EForms-Response: json`; the narrow `{ok, location|errors|error, can_retry, upload_recovery, challenge}` envelope exactly as defined in the active contract; private/no-store JSON; underlying HTTP and `Retry-After` header semantics.
  - Owner: `FormProtocol` for shared names; `PublicRequestController` for negotiation/shaping; `Success` for result locations.
  - Depends On: `P2.T1`.
  - Reuse Target: `SubmitHandler` results including `retry_allowed`, `Errors`/`ErrorMessages`, side-effect-free `Success` destination resolution, existing header emission, and raw captured-response plumbing.
  - No-Fallback Rule: no new endpoint, no second result type owner, no customer-value echo, no HTML body inside JSON, no idempotency key/store, no status-derived retry decision in JavaScript, and no local wire-name map.
  - Replacement: controller-local assumption that every correctable public POST must be rendered as HTML.
  - Superseded Seams: none for native HTML requests; enhanced requests gain one negotiated response path.
  - Complexity Budget: zero new production files; one response adapter inside the existing controller; stop if shaping requires a new service/facade, draft table, or terminal-outcome store.
  - Candidate Scope: enhanced names, controller response selection/body/header emission, safe `location` serialization, protocol contract tests, and existing staged/result integration consumers.
  - Leftover Checks: enhanced literals outside `FormProtocol`; JSON customer values/credentials; new submission route; unsafe redirect destination.
  - Exception Revalidation: HTML local rerender, HTTP redirect, review raw stream, and result-page render modes remain live and must retain their current tests.
  - Done When: exact enhanced requests receive only the contracted JSON envelope regardless of scalar/staged template shape; correctable errors are safe and template-ordered; upload recovery exposes only `open`/`finalizing_recovery`; success, stealth success, and email failure return existing safe navigation destinations from `Success` without leaking classification; failure responses copy `retry_allowed` to `can_retry`; ordinary no-header requests are unchanged.
  - Verified via: `php tests/integration/test_enhanced_submission.php && php tests/integration/test_form_protocol_contract.php && php tests/unit/test_protocol_seam_guards.php && php tests/wp-runtime/run.php`.

- [ ] P2.T3 Add renderer error mounts and Challenge public metadata (Source: `P2.T2`)
  - Artifacts: `src/FormProtocol.php`, `src/Security/Challenge.php`, `src/Rendering/FormRenderer.php`, existing `Challenge` tests, `tests/integration/test_accessibility_error_summary.php`, `tests/integration/test_form_protocol_contract.php`, `tests/unit/test_protocol_seam_guards.php`, `tests/wp-runtime/run.php`.
  - Interfaces: renderer-owned field/error mapping attributes; `Challenge`-owned Turnstile provider/script metadata carried through protocol-owned key names; stable challenge mount.
  - Owner: `FormRenderer` for field/error mounts and challenge mount; `Challenge` for provider behavior and public metadata; `FormProtocol` for shared names.
  - Depends On: `P2.T2`.
  - Reuse Target: existing error summary, inline-error, `aria-invalid`, `aria-describedby`, challenge rendering, and `Challenge` configuration projection.
  - No-Fallback Rule: no layout wrappers, no duplicated provider URL in JavaScript, no client challenge decision, no provider secret exposure, and no local wire-name map.
  - Replacement: JavaScript reconstruction of renderer IDs or provider metadata.
  - Complexity Budget: zero new production files and no CSS unless an existing primitive cannot express required hidden error mounts.
  - Candidate Scope: one protocol-owned field-key/control/error-mount attribute family, one stable initially hidden error mount after each field, and challenge public metadata projection.
  - Leftover Checks: provider URL hardcoded outside `Challenge`; field-name/ID reconstruction in JavaScript; wrapper markup that changes theme layout.
  - Done When: renderer markup exposes protocol-named error mounts without adding layout wrappers; challenge exposes only provider/public key, `Challenge`-owned public metadata, and the stable mount needed for JavaScript recovery behavior.
  - Verified via: `php tests/integration/test_accessibility_error_summary.php && php tests/integration/test_form_protocol_contract.php && php tests/unit/test_protocol_seam_guards.php && php tests/wp-runtime/run.php`.

Phase 2 checkpoint: run the Seam Guard and failure-branch sweep for malformed header, scalar-form JSON adapter, JSON encode failure, invalid error shape, missing challenge config, script metadata projection, 408, 429, pre-ledger 5xx with `can_retry: true`, post-ledger 5xx with `can_retry: false`, every `SubmitHandler` failure exit with `retry_allowed` default-false proof, token/duplicate, stealth success, email failure, side-effect-free success location, unsafe location, and raw review responses.

## Phase 3 — Preserve the Live Browser Draft

Goal: consume enhanced outcomes in the existing form runtime, update only error/challenge/action state, and prove the staged uploader is never reconstructed on correctable failures.

- [ ] P3.T1 Implement same-document submission in `forms.js` and its rendered proof (Source: updated active carriers from `P1.T1`)
  - Type: `shared-ui-runtime`.
  - Artifacts: `assets/forms.js`, `assets/forms.css` only if existing primitives prove insufficient, `src/Rendering/FormRenderer.php` only for rendered attribute/mount adjustments not completed in `P2.T3`, `src/FormProtocol.php` only for protocol-name adjustments not completed in `P2.T2` or `P2.T3`, `tests/e2e/specs/enhanced_submission.spec.js`, `tests/e2e/specs/staged_upload.spec.js`, `tests/e2e/specs/staged_upload_live.spec.js`, `tests/integration/test_accessibility_error_summary.php`.
  - Interfaces: existing staged-photo form submit event; protocol-owned enhanced request/response; renderer-owned field/error/challenge mounts; existing error summary/inline-error/challenge/spinner/uploader DOM; same-origin result navigation.
  - Owner: `forms.js`.
  - Depends On: `P2.T3`.
  - Existing Owner Evidence: existing `focusErrors`, submit lock, mint state, staged upload form state, credential writer, one-way submit freeze, and challenge markup. This task adds the missing inverse thaw transition rather than reconstructing the runtime.
  - Docs Consulted: UI Surface Preflight and updated active carriers from `P1.T1`.
  - Reuse Target: existing form/error/challenge/uploader nodes and runtime; no replacement shell.
  - Composition Target: preserve form source order and current action placement; update only the summary, field error associations, optional challenge region, and submit pending state.
  - Selector Reuse: `.eforms-form`, `.eforms-error-summary`, `.eforms-error`, `.eforms-challenge`, `.cf-turnstile`, `.eforms-spinner`, and existing staged mount/card selectors.
  - Selector Delta: one protocol-owned renderer attribute family for field keys, control targets, and error mounts; no CSS selector dependency on it.
  - Style Delta: none expected. If existing primitives cannot express a required state, stop for explicit approval before adding a selector/style exception.
  - UI Completion Gate: desktop and narrow Playwright runs prove staged-photo correctable, ambiguous, failure with `can_retry` true/false, challenge, terminal, and accepted states; exact original field/uploader/card node identity survives correctable/ambiguous outcomes; old full-navigation behavior is absent only on the enhanced staged-photo path.
  - Consumer Status: `staged-photo-live` — renderer-emitted staged-photo forms with complete protocol settings and required browser APIs use this path. Scalar-only forms remain on the HTML path in the initial rollout.
  - Live Consumer Proof: updated `staged_upload_live.spec.js` against a disposable WordPress page.
  - No-Fallback Rule: no duplicate validation rules, form clone, uploader reconstruction, local wire-name fallback, draft store, or cross-origin navigation.
  - Done When: listener ordering remains deterministic: staged readiness validates the queue and writes credentials first; JS-token mint guard blocks if minting is not ready; enhanced staged-photo handler checks `event.defaultPrevented` and intercepts only a staged-photo form with an otherwise valid event; legacy submit lock runs only when the enhanced handler did not intercept and native navigation will occur. Enhanced submit owns pending/spinner removal for fetch submissions, serializes the existing form once, keeps one request in flight, preserves exact DOM values and photo previews on correctable/ambiguous outcomes, applies valid 422 `upload_recovery.state` directly without a status request, reconciles through authenticated status only after network failure, malformed JSON, or uncertain response, thaws mutations only when authoritative state is `open`, keeps upload mutations frozen but permits explicit corrected retry when authoritative state is `finalizing_recovery`, applies safe field/global errors through renderer-owned mounts, focuses the summary without immediately stealing focus to the first field, activates at most one Turnstile widget from `Challenge`-owned public metadata, handles Turnstile script load/ready failure without leaving the form indefinitely disabled, restores Send when correctable, when ambiguous recovery table permits it, or when a structured failure has `can_retry: true`, blocks terminal retries, and navigates exactly once whenever a structured response contains a validated, server-owned same-origin location. Unsupported capability and scalar-only forms use native HTML submission.
  - Verified via: `npm test --prefix tests/e2e && php tests/integration/test_accessibility_error_summary.php`; then run the Live Gate when its URL is available.

- [ ] P3.T2 Reconcile permanent carriers and close the cross-owner verification gate (Source: all completed implementation tasks)
  - Type: `standard`.
  - Artifacts: changed active carriers from `P1.T1` and affected test descriptions/assertions; no new behavior source.
  - Interfaces: final enhanced response and visitor-visible lifecycle only.
  - Owner: active carrier owners and verification harness.
  - Depends On: `P3.T1`.
  - Done When: every carrier accurately distinguishes staged-photo enhanced same-document handling, scalar/no-JS HTML fallback rerender, and arbitrary refresh; all focused and broad gates pass; skipped/external evidence is classified in the final handoff.
  - Verified via: run the Verification Command, Broad Gate, Seam Guard, Removal Proof, `git diff --check`, and the Live Gate when available.

Phase 3 checkpoint: inspect focused diffstat and new-artifact list. The final shape must remain one POST owner, one wire-name owner, one browser runtime, one HTML fallback, and no persistence or preview subsystem.

## Known Debt & Open Questions

- [ ] Debt: live mobile/browser deployment proof — local Playwright cannot prove the exact production WordPress/theme/challenge integration on Android and iPhone.
  - Type: debt.
  - Owner: release operator.
  - Why Deferred: it requires a deployed disposable page and physical/browser-device review, not additional product behavior.
  - Removal Trigger: before production deployment of the enhanced path.
  - Verification Hook: run the Live Gate, then manually confirm iPhone Safari and Android Chrome invalid correction preserves values, photo thumbnails, focus, challenge, and successful navigation.

- [ ] Open Question: persisted draft recovery — should a future explicit product requirement cover refresh, crash, cross-tab, or cross-device recovery?
  - Type: open-question.
  - Owner: product owner.
  - Why Deferred: solving ordinary validation does not require retaining customer PII or staged bearer credentials beyond the document.
  - Decision Trigger: observed user loss from refresh/crash or an explicit multi-session completion requirement.
  - Decision Options: keep document-only drafts; add tab-scoped recovery with an explicit privacy/TTL contract; add authenticated server drafts as a separate subsystem.
  - Default Until Decided: document-only preservation; arbitrary refresh follows the existing no-batch-recovery contract.
  - Verification Hook: product decision plus an owning active-carrier update before any implementation plan is created.

## Execution Gate Matrix Result

- Load-bearing task card completeness: yes.
- Invariant proof coverage: yes; each invariant has positive and negative proof in the Invariant Matrix.
- Task-type overlay scoping: yes; `seam-refactor` and `shared-ui-runtime` are phase-scoped.
- Verification command readiness: yes.
- Seam guard readiness: yes.
- Seam card completeness: yes; task cards inherit the Phase Execution Brief where stated.
- Owner reduction readiness: yes.
- Boundary decision readiness: yes; extend existing owners.
- Contract-carrier census: yes.
- Shared-owner adoption gate: not triggered; no new shared owner is introduced.
- Baseline failure snapshot: not triggered; the recorded baseline has no failures.
- Contract drift triage resolved: yes; enhanced navigation assertions become stale, HTML fallback assertions remain.
- Formal digest gate: not applicable.
