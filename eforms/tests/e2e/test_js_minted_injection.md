# JS-minted token injection (manual)

Spec: Assets (docs/Canonical_Spec.md#sec-assets)
Spec: JS-minted mode contract (docs/Canonical_Spec.md#sec-js-mint-mode)

## Setup

1. Activate the plugin and render a cacheable form (e.g., `[eform id="contact" cacheable="true"]`).
2. Open browser devtools (Network + Application/Storage tabs).

## Scenario A: Initial mint + injection

1. Load the page with the JS-minted form.
2. Verify a POST to the configured mint endpoint with `Content-Type: application/x-www-form-urlencoded` and body `f={form_id}`.
3. Confirm the hidden inputs `eforms_token`, `instance_id`, and `timestamp` become populated after DOMContentLoaded.
4. Confirm the submit button becomes enabled once minting succeeds.
5. Confirm `sessionStorage` contains `eforms:token:{form_id}` with `token`, `instance_id`, `timestamp`, and `expires`.

## Scenario B: Session reuse

1. Refresh the page in the same tab.
2. Confirm the hidden inputs are populated from session storage without a new mint request (if the cached token has not expired).

## Scenario C: Email-failure result page

1. Force `Emailer::send()` to fail (e.g., via a test config or by temporarily mocking `wp_mail()` to return false).
2. Submit the form to trigger the email-failure PRG.
3. Confirm the POST returns a 303 to a URL with `eforms_result=email_failure` and `eforms_form={form_id}`.
4. Confirm the follow-up page uses the theme header/footer and does not render a form, submitted values, or copy textarea.

## Scenario D: Mint failure UX

1. Block the configured mint endpoint (e.g., devtools request blocking or offline mode).
2. Reload the page.
3. Confirm submission remains blocked and the error summary contains the generic mint failure message.
