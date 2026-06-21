# Public Contracts

This file lists stable public and machine-readable surfaces. Operator narrative belongs in `docs/overview.md`; owner routing belongs in `docs/Architecture_Router.md` and `docs/Owner_Index.md`.

## Public Surfaces

| Surface | Owner | Contract |
| --- | --- | --- |
| `[eform id="..." cacheable="..."]` | `FormRenderer` via shortcode bootstrap | Renders one form. `cacheable=false` uses hidden-token mode; `cacheable=true` uses JS-minted mode. |
| `eform_render($slug, $opts)` | `FormRenderer` via template tag bootstrap | PHP equivalent of the shortcode. |
| `POST /eforms/mint` | `src/Security/MintEndpoint.php` | Mints JS-mode tokens for cacheable forms. |
| `window.eformsSettings.mintEndpoint` | `FormRenderer` and `assets/forms.js` | Browser config consumed by JS-minted forms. |
| `${WP_CONTENT_DIR}/eforms.config.php` | `src/Config.php` | Optional deployment override file returning an array. |
| `eforms_config` | `src/Config.php` | Optional final config filter. |
| `eforms_request_id` | logging/request-id owner | Optional request correlation override. |
| `wp eforms gc` | `src/Gc/GcRunner.php` and CLI adapter | Prunes expired runtime artifacts. |
| `wp eforms spam-smoke` | `src/Diagnostics/SpamSmokeDiagnostic.php` and CLI/admin adapters | Runs focused spam-path smoke checks. |
| `wp eforms doctor` | `src/Diagnostics/RuntimeHealthDiagnostic.php` and CLI/admin adapters | Runs active host/runtime readiness checks. |
| Settings -> eForms | `src/Admin/SettingsAdmin.php` and `src/Admin/SettingsFields.php` | Curated admin config surface with effective values, sources, help, and diagnostic actions. |
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

## Error And Result Contracts

- Public error codes are append-only and owned by the error-code/message owners.
- Token, duplicate, and expired-submission failures share the public message: "This form was already submitted or has expired - please reload the page."
- Email-send failure after ledger reservation redirects to the plugin-owned email-failure result page. The ledger remains burned; runtime must not preserve submitted field values, mint a retry token, render a submitted-content summary, or rerender the form as a retry path.
- Suspicious but delivered emails may add a generic subject tag and `X-EForms-Soft-Reasons` with safe deduplicated soft-reason labels.

## Browser Asset Contract

- Assets enqueue only when a form is rendered.
- `forms.js` owns `js_ok`, submit lock, JS token minting, server-error summary focus, and first-invalid focus.
- JS-minted forms must block submission until token minting succeeds.
- JS must not overwrite non-empty `eforms_token`, `instance_id`, or `timestamp` fields.
- Mixed-mode pages call `/eforms/mint` only for JS-minted forms and never for hidden-token forms.
- The Turnstile provider script is enqueued only when a challenge is rendered. Only the site key may reach browser markup; secret keys stay server-side.

