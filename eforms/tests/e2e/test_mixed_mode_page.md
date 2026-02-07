# Mixed-mode page behavior (manual)

Spec: Assets (docs/Canonical_Spec.md#sec-assets)
Spec: Submission protection (docs/Canonical_Spec.md#sec-submission-protection)
Spec: Security invariants (docs/Canonical_Spec.md#sec-security-invariants)

## Setup

1. Activate the plugin and render two different forms on one page:
   - Hidden-mode: `[eform id="contact" cacheable="false"]`
   - JS-minted: `[eform id="quote-request" cacheable="true"]`
2. Open browser devtools (Network tab + Elements inspector).

## Scenario A: Initial render metadata

1. Load the page.
2. In the Elements inspector, confirm the hidden-mode form includes:
   - `data-eforms-mode="hidden"`
   - `name="eforms_mode" value="hidden"`
   - Non-empty `eforms_token`, `instance_id`, and `timestamp` values in the HTML.
3. Confirm the JS-minted form includes:
   - `data-eforms-mode="js"`
   - `name="eforms_mode" value="js"`
   - Empty `eforms_token`, `instance_id`, and `timestamp` values in the HTML.

## Scenario B: Mint calls

1. Reload the page and watch the Network tab.
2. Confirm only one POST to `/eforms/mint` occurs, and it corresponds to the JS-minted form id.
3. Confirm no `/eforms/mint` request fires for the hidden-mode form.

## Scenario C: Independent handling

1. Block `/eforms/mint` (devtools request blocking) and reload the page.
2. Confirm the JS-minted form shows the generic mint error and remains blocked.
3. Confirm the hidden-mode form remains renderable with its prefilled security fields.
