# eForms browser E2E checks

These checks automate the critical JS behaviors from:

- `test_js_minted_injection.md`
- `test_mixed_mode_page.md`

They are test-only tooling and do not affect plugin runtime dependencies.

## Scope

- JS-minted token injection (configured mint endpoint call + hidden-field injection)
- SessionStorage reuse on reload (no mint request while cached token is valid)
- Mixed-mode page behavior (only JS form mints; hidden form remains server-token mode)
- Mint-failure UX isolation (JS form blocked with deterministic error; hidden form still usable)

## Prerequisites

- Node.js 20+
- A running WordPress instance with this plugin activated
- Public test pages:
  - JS-only page: `[eform id="contact" cacheable="true"]`
  - Mixed-mode page:
    - `[eform id="contact" cacheable="false"]`
    - `[eform id="quote-request" cacheable="true"]`
- Permalink rules flushed so the mint endpoint resolves

## Install

```sh
npm install --prefix eforms/tests/e2e
npm run --prefix eforms/tests/e2e install:browsers
```

## Run

```sh
EFORMS_E2E_BASE_URL="http://127.0.0.1:8080" \
EFORMS_E2E_JS_PAGE_URL="http://127.0.0.1:8080/?p=10" \
EFORMS_E2E_MIXED_PAGE_URL="http://127.0.0.1:8080/?p=11" \
npm test --prefix eforms/tests/e2e
```
