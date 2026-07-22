# Public Contracts

This file lists stable public and machine-readable surfaces. Operator narrative belongs in `docs/overview.md`; owner routing belongs in `docs/Architecture_Router.md` and `docs/Owner_Index.md`.

## Public Surfaces

| Surface | Owner | Contract |
| --- | --- | --- |
| `[eform id="..." cacheable="..."]` | `FormRenderer` via shortcode bootstrap | Renders one form. `cacheable=false` uses hidden-token mode; `cacheable=true` uses JS-minted mode. |
| `eform_render($slug, $opts)` | `FormRenderer` via template tag bootstrap | PHP equivalent of the shortcode. |
| `POST /eforms/mint` | `src/Security/MintEndpoint.php` | Mints JS-mode tokens for cacheable forms. |
| `/eforms/upload-batches` route family | `src/Uploads/UploadBatchEndpoint.php` | Creates and operates one field-bound staged upload batch. |
| Managed review query route | `src/Uploads/ReviewController.php` | Serves one time-limited signed gallery and its controlled files through the WordPress home query route. |
| `window.eformsSettings.mintEndpoint` | `FormRenderer` and `assets/forms.js` | Browser config consumed by JS-minted forms. |
| `${WP_CONTENT_DIR}/eforms.config.php` | `src/Config.php` | Optional deployment override file returning an array. |
| `eforms_config` | `src/Config.php` | Optional final config filter. |
| `eforms_request_id` | logging/request-id owner | Optional request correlation override. |
| `wp eforms gc` | `src/Gc/GcRunner.php` and CLI adapter | Prunes expired runtime artifacts. |
| `wp eforms spam-smoke` | `src/Diagnostics/SpamSmokeDiagnostic.php` and CLI/admin adapters | Runs focused spam-path smoke checks. |
| `wp eforms doctor` | `src/Diagnostics/RuntimeHealthDiagnostic.php` and CLI/admin adapters | Runs active host/runtime readiness checks. |
| Settings -> eForms | `src/Admin/SettingsAdmin.php` and `src/Admin/SettingsFields.php` | Curated admin config surface with grouped controls, setting help, external-control status, and diagnostic actions. |
| Tools -> eForms Declined | `src/Admin/DeclinedReviewAdmin.php` and `src/DeclinedReviewLog.php` | Declined-submission review and maintenance surface. |

## `/eforms/mint`

- Method: `POST` only. Other methods return `405` and `Allow: POST`.
- Body: `application/x-www-form-urlencoded` with `f={form_id}`. JSON bodies are rejected.
- Origin: missing, unknown, or cross-origin requests fail with `403 EFORMS_ERR_ORIGIN_FORBIDDEN`; the endpoint must not emit CORS allow headers.
- Success response: JSON `{ token, instance_id, timestamp, expires }`, where `timestamp` is the token record issue time.
- Error responses are JSON with an `error` code and `Cache-Control: no-store, max-age=0`.
- Rate limits return `429` with `Retry-After`.
- Filesystem mint failures return `500 EFORMS_ERR_MINT_FAILED`.

## Config Contract

- Defaults live in `Config::DEFAULTS`; docs do not duplicate default literals.
- Precedence is code defaults < `eforms_admin_config` < drop-in file < `eforms_config` filter.
- `eforms_admin_config` is a sparse admin override only. It must not store submissions, declined-review records, templates, raw config text, or per-submission state.
- Settings -> eForms may write only the curated allowlist owned by `SettingsFields`.
- Drop-in/filter-controlled values render as externally controlled in wp-admin and are excluded from admin mutation.
- Stored admin secrets are never rendered raw. Blank secret submissions preserve existing stored admin secrets; explicit clear controls remove only the stored admin override.
- Config keys, error codes, `/eforms/mint` JSON fields, and log schemas evolve append-only unless the user explicitly approves a breaking contract change.
- `spam.content_filter.mode` accepts `off`, `suspect`, or `reject`. `spam.content_filter.blocked_terms` is a bounded newline list of plain words or phrases; it is not regex.

## Managed Upload API

- `POST /eforms/upload-batches` creates or recovers the one batch bound to the validated, unused form token, form ID, instance ID, field key, and effective policy fingerprint. Creation and ledger reservation share the ledger lock from the final unused check through batch mutation; a durable marker therefore makes create terminal with `410 EFORMS_ERR_TOKEN`, so a completed token cannot recreate its renamed batch even under concurrent finalization. The browser generates a 32-byte secret with Web Crypto and sends its base64url value in `X-EForms-Batch-Secret`; the server stores only its SHA-256 digest.
- Staged create/upload requests return `503 EFORMS_ERR_STORAGE_UNAVAILABLE` before managed-state mutation when the required existing throttle is disabled. Route identities come only from REST URL parameters; JSON, form, or query values cannot override `batch_id` or `upload_id`.
- `GET /eforms/upload-batches/{batch_id}` returns the batch state and committed item summaries. `POST /eforms/upload-batches/{batch_id}/items/{upload_id}` accepts one multipart image. `DELETE` on that item is idempotent. `GET /eforms/upload-batches/{batch_id}/items/{upload_id}/preview` returns only the authenticated JPEG preview.
- Every batch endpoint requires `X-EForms-Batch-Secret`. Batch credentials are never accepted from a URL or request body. Create also requires the ordinary validated form-token fields and the field key; item upload requires the stable browser-generated `upload_id` in the path and an ordinal in the multipart body.
- Create, upload, and delete require an explicit same-origin `Origin` header. Credential-authenticated status and preview GETs accept a same-origin or missing `Origin` header because browsers commonly omit it for same-origin reads; cross or malformed origins still return `403 EFORMS_ERR_ORIGIN_FORBIDDEN`, and no endpoint emits CORS allow headers.
- The browser reuses one `upload_id` and ordinal for every retry, and active items have unique ordinals within their batch. Repeating create with the same binding and secret, repeating upload with the same ID, ordinal, and content, and repeating delete return the existing result. For a committed `upload_id`, the server checks ordinal, upload errors, and the actual field-size bound before hashing raw content; an oversized retry returns `413 EFORMS_ERR_UPLOAD_TYPE`, while a different content hash or ordinal returns generic `409 EFORMS_ERR_TOKEN` without replacing state or decoding the retry as an image. The same binding with a different secret also returns that generic conflict; a different upload ID that claims an active ordinal returns `400 EFORMS_ERR_UPLOAD_TYPE` without replacing state. Distinct upload IDs are accepted only while bounded deletion history remains available, so every accepted item retains a removable state; exhaustion is a policy rejection and an absent delete remains successful.
- While the staged path exists, status exposes only `open` or `finalizing`. Upload and delete require `open`; preview requires `open`. Once finalization begins, all batch mutations and previews fail closed.
- After aggregate rename, after cleanup expiry, when the staged path is absent, or when a route observes the path/manifest removal window of either transition, every batch-ID route returns the same no-store `410 EFORMS_ERR_TOKEN` before credential validation. This result means terminal/unavailable only and never confirms a submission. There is no tombstone, batch index, reverse lookup, directory scan, or submission lookup.
- Malformed or policy-rejected images return `400 EFORMS_ERR_UPLOAD_TYPE`; request or item bodies above a disclosed bound return `413 EFORMS_ERR_UPLOAD_TYPE`; authentication/binding failures and conflicts use `EFORMS_ERR_TOKEN`; capacity or private-storage failures use `EFORMS_ERR_STORAGE_UNAVAILABLE`; rate limits use `429 EFORMS_ERR_THROTTLED` with `Retry-After`. Error bodies contain only `{error}` plus safe field/item context and never contain credentials, paths, signatures, image metadata, or customer values.
- Create and upload call the existing origin and per-IP throttle gate exactly once after method dispatch and before Content-Type, credential, payload, reservation, hash, or image validation. Malformed cross-origin requests therefore receive the origin rejection, and same-origin Content-Type failures consume a throttle attempt. A 429 performs no decode or managed-state mutation.

### Batch identifier fixture

The batch ID is the unpadded base64url encoding of a full 32-byte HMAC-SHA256 result. Its key is the validated raw form-token byte string. Its message is the concatenation `u32be(byte_length(part)) || part` for this ordered UTF-8 list: `eforms-upload-batch-id`, `1`, form ID, instance ID, lowercase hexadecimal SHA-256 token digest, field key, policy fingerprint. No Unicode normalization, delimiter joining, truncation, random ID, index, or server secret participates.

The canonical fixture uses raw token `token-fixture-01`, form `virtual-quote`, instance `instance-fixture-1`, field `project_photos`, and policy JSON `{"accept":["image"],"max_file_bytes":20971520,"max_files":24,"max_total_bytes":314572800,"upload_mode":"staged"}`. Its policy fingerprint is `ccbc2b0a90b861608c23efd497b782355ef0bd305cc60427e77039088cf72532` and batch ID is `5e1cTCrFgHMC_zzjhfT9FgKTLbR6HxAo2oWCvsrYu9U`.

## Final Form Credential Transport

- Immediately before an ordinary final form POST, `forms.js` emits `eforms_upload_batches[{field_key}][batch_id]` and `eforms_upload_batches[{field_key}][batch_secret]`. `FormProtocol` owns the root, child names, and endpoint header name.
- Submission strips the entire `eforms_upload_batches` root before customer-value normalization, persistence, logging, or email. Validation/challenge rerenders re-emit a field entry only after validating its exact binding, secret, and cleanup deadline and confirming under the ledger lock that the token remains unused. An interrupted pre-ledger `finalizing` claim is reopened while `accept_until` remains live; after that deadline, an exact recoverable claim remains `finalizing` while its credentials are re-emitted so a corrected retry can resume before `delete_after`. A durable ledger marker keeps the claim terminal and its credentials hidden.
- Fresh renders and arbitrary refreshes emit no batch credentials. Credentials never appear in URLs, logs, email, public errors, persisted customer values, or gallery payloads.

## Managed Review Links

- Every permalink mode uses the WordPress home query route. `GET /?eforms_review={submission_id}&expires={unix}&signature={signature}` renders one gallery; adding owner-defined `eforms_review_upload={upload_id}` and `eforms_review_variant={preview|master}` streams one manifest-owned JPEG derivative. Both variants use `.jpg` response filenames, and the gallery labels the master action **High-resolution**. The staged browser API uses its registered WordPress REST routes and adds no rewrite aliases. A review bearer query on any path other than the exact home path is privately rejected rather than becoming an alias. Every method on a matched review query is dispatched through `ReviewController`; non-GET requests receive the same private, no-store, noindex unavailable response. Responses never disclose filesystem paths.
- The signature is unpadded full-length base64url HMAC-SHA256 keyed by `wp_salt('auth')`. Its message uses the batch-ID length-prefix encoding over ordered UTF-8 parts: domain `eforms-managed-review`, version `1`, action `gallery|file`, submission ID, upload ID or empty string, variant or empty string, and decimal expiry. Signature comparison is constant-time. WordPress salt rotation intentionally invalidates outstanding links.
- Access ends at the earlier of the signed `expires` value and the manifest `gallery_expires_at`. Modified, expired, foreign-upload, invalid-variant, and path-like identifiers return the same generic not-found response. Possession of a forwarded link grants its bearer the scoped access until expiry.

## Error And Result Contracts

- Public error codes are append-only and owned by the error-code/message owners.
- Token, duplicate, and expired-submission failures share the public message: "This form was already submitted or has expired - please reload the page."
- Email-send failure after ledger reservation redirects to the plugin-owned email-failure result page. The ledger remains burned; runtime must not preserve submitted field values, mint a retry token, render a submitted-content summary, or rerender the form as a retry path.
- A finalized staged submission completes gallery lookup, header validation, and template rendering before persisting `email_attempted_at` immediately before calling `wp_mail()`. Matching recovery may resume only before that marker; after it exists, no retry path invokes mail again. Staged images are represented in email by one signed review link and are never attachments.
- Turnstile Siteverify receives the stable submission UUID as `idempotency_key`, allowing the same pre-email submission recovery to retry provider verification without consuming the single-use response twice.
- Suspicious but delivered emails may add a generic subject tag and `X-EForms-Soft-Reasons` with safe deduplicated soft-reason labels.
- Content-filter suspect decisions are separate from soft-signal threshold math. Delivered suspect emails may add a generic subject tag and safe content-filter metadata such as `X-EForms-Content-Reasons`; public responses and normal logs must not expose raw blocked terms or submitted content.

## Browser Asset Contract

- `forms.js` enqueues only when a form is rendered. The shared plugin CSS may also enqueue for signed managed review galleries, and `assets.css_disable=true` disables that CSS on both surfaces.
- `forms.js` owns `js_ok`, submit lock, JS token minting, server-error summary focus, and first-invalid focus.
- `forms.js` also owns every staged uploader queue. Item states are `queued`, `uploading`, `processing`, `uploaded`, `failed`, `removing`, and `removed`; upload progress reaching 100% enters `processing` until the server response commits the item.
- Any selected item in `queued`, `uploading`, `processing`, `failed`, or `removing` blocks final submission. Validation-rerender restoration is also a blocking phase: picker mutation and submission remain disabled until one complete authenticated status snapshot has been validated and applied. Network failures, `408`, `429`, `5xx`, and malformed status responses retain the batch credentials and expose a restore retry; only a definitive terminal response or expiry makes the runtime unavailable. A restored item confirmed by batch status remains `uploaded` and submittable if its presentation-only preview fetch fails. The runtime reconciles ambiguous upload and delete outcomes through authenticated status, preserves and restores the actual per-form submit label, freezes mutations when final submission begins, clears/disables staged file inputs, and writes only the protocol-owned hidden credentials. Destroying or removing a form discards any late batch-create response and never starts an item body afterward.
- A server-rendered staged picker is disabled and unnamed with a `<noscript>` explanation. JavaScript enables it only as picker transport; there is no synchronous multipart fallback. A created open batch retains its credential transport even after every item is removed, so a validation rerender can restore the same batch; arbitrary refresh does not restore a batch.
- Managed browser fields require the complete renderer-emitted `FormProtocol` upload settings. Each managed mount carries the exact capped picker ID emitted by the renderer; browser association never reconstructs or infers that ID from a form or field suffix. Missing or partial protocol settings leave the staged picker disabled; browser code has no local fallback copy of shared header, field, parameter, or data-attribute names.
- Ordinary JS-minted forms may reuse a valid token from `sessionStorage`. A staged JS form on a fresh render has no recoverable batch secret, so it removes any cached token and mints a new token before enabling uploads. A validation rerender carrying complete server-validated batch credentials keeps its rendered token and restores that exact batch instead.
- Managed fields expose empty, uploading, processing, uploaded, failed, removing, blocked, and expired states with live-region announcements and progressbar semantics. An initial create with expired form credentials returns terminal `410 EFORMS_ERR_TOKEN`; after a terminal batch response, the field rerenders every card without mutation controls, blocks their handlers plus upload/submission, and instructs the visitor to reload. Local `accept_until` expiry also blocks upload/submission; open-batch status and cleanup remain available only until `delete_after`.
- Plugin CSS owns managed-field structure. Themes may set only `--eforms-upload-accent`, `--eforms-upload-track`, `--eforms-upload-border`, `--eforms-upload-card-bg`, and `--eforms-upload-error`.
- JS-minted forms must block submission until token minting succeeds.
- JS must not overwrite non-empty `eforms_token`, `instance_id`, or `timestamp` fields.
- Mixed-mode pages call `/eforms/mint` only for JS-minted forms and never for hidden-token forms.
- The Turnstile provider script is enqueued only when a challenge is rendered. Only the site key may reach browser markup; secret keys stay server-side.
