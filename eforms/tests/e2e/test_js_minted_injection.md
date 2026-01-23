# JS-minted token injection (manual)

Spec: Assets (docs/Canonical_Spec.md#sec-assets)
Spec: JS-minted email-failure recovery (docs/Canonical_Spec.md#sec-js-email-failure)
Spec: JS-minted mode contract (docs/Canonical_Spec.md#sec-js-mint-mode)

## Setup

1. Activate the plugin and render a cacheable form (e.g., `[eform id="contact" cacheable="true"]`).
2. Open browser devtools (Network + Application/Storage tabs).

## Scenario A: Initial mint + injection

1. Load the page with the JS-minted form.
2. Verify a POST to `/eforms/mint` with `Content-Type: application/x-www-form-urlencoded` and body `f={form_id}`.
3. Confirm the hidden inputs `eforms_token`, `instance_id`, and `timestamp` become populated after DOMContentLoaded.
4. Confirm the submit button becomes enabled once minting succeeds.
5. Confirm `sessionStorage` contains `eforms:token:{form_id}` with `token`, `instance_id`, `timestamp`, and `expires`.

## Scenario B: Session reuse

1. Refresh the page in the same tab.
2. Confirm the hidden inputs are populated from session storage without a new `/eforms/mint` request (if the cached token has not expired).

## Scenario C: Email-failure remint

1. Force `Emailer::send()` to fail (e.g., via a test config or by temporarily mocking `wp_mail()` to return false).
2. Submit the form to trigger the email-failure rerender.
3. Confirm the form element has `data-eforms-remint="1"` and the hidden token fields are empty on rerender.
4. Confirm forms.js clears the cached token, calls `/eforms/mint`, injects new values, and removes the `data-eforms-remint` attribute.

## Scenario D: Mint failure UX

1. Block `/eforms/mint` (e.g., devtools request blocking or offline mode).
2. Reload the page.
3. Confirm submission remains blocked and the error summary contains the generic mint failure message.
