# eForms browser E2E checks

These checks automate the critical JS behaviors from:

- `test_js_minted_injection.md`
- `test_mixed_mode_page.md`

They are test-only tooling and do not affect plugin runtime dependencies.

## Scope

- JS-minted token injection (configured mint endpoint call + hidden-field injection)
- SessionStorage reuse on reload for ordinary JS forms, plus fresh remint for staged JS forms whose document-scoped batch secret is unavailable after reload
- Mixed-mode page behavior (only JS form mints; hidden form remains server-token mode)
- Mint-failure UX isolation (JS form blocked with deterministic error; hidden form still usable)
- Managed staged-photo queue, additive selection/drop, three-transfer/four-pipeline admission, and Processing transition
- Stable-ID retry, response-loss reconciliation, serialized removal/Clear all, timer expiry, terminal and finalizing races
- One-at-a-time review-preview admission with explicit retry and authoritative download fallback
- Final-POST credential boundary, blocking/retryable validation-rerender restoration, preview-failure isolation, teardown, accessibility, and responsive geometry
- Live WordPress multipart upload, validation rerender/restoration, and successful resubmission without re-upload

## Prerequisites

- Node.js 20+
- A running WordPress instance with this plugin activated for the JS-mint and mixed-mode checks
- Public test pages for those live checks:
  - JS-only page: `[eform id="contact" cacheable="true"]`
  - Mixed-mode page:
    - `[eform id="contact" cacheable="false"]`
    - `[eform id="quote-request" cacheable="true"]`
- Permalink rules flushed so the mint endpoint resolves

The focused staged-upload specifications are self-contained browser tests with mocked HTTP responses. The live staged-upload scenario requires a WordPress page containing `[eform id="upload-test" cacheable="false"]` and uses real JPEG/PNG bytes against the registered endpoint.

## Install

```sh
npm ci --prefix tests/e2e
npm run --prefix tests/e2e install:browsers
```

## Run

```sh
EFORMS_E2E_BASE_URL="http://127.0.0.1:8080" \
EFORMS_E2E_JS_PAGE_URL="http://127.0.0.1:8080/?p=10" \
EFORMS_E2E_MIXED_PAGE_URL="http://127.0.0.1:8080/?p=11" \
EFORMS_E2E_STAGED_PAGE_URL="http://127.0.0.1:8080/?p=12" \
npm test --prefix tests/e2e
```
