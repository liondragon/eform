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
- Managed staged-photo queue, additive selection/drop, three-request concurrency, and verifying/Finishing upload transition
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

## Controlled performance acceptance

Run the equivalent two-fixture workload against isolated baseline, local
authoritative-artifact, and Worker/R2 deployments. The executable WordPress
metrics collector receives phase (`committed|deleted`), `scenario`, fixture
label, `run`, start/finish milliseconds, the private batch ID, and the prior
opaque workload token as arguments. It must print one JSON object with the
matching `scenario`, `build_id`, `deployment_id`, and `composition`; false
`preparation_enabled`; and phase-specific facts. At `committed` it returns a
fresh, run-unique 16–128 character base64url `workload_token` and exact
`logical_item_count` and `capacity_item_count` equal to the requested workload,
plus an exact closed `object_census`. The census keys are
`authoritative_artifacts`, `normalized_masters`, `normalized_previews`,
`validation_records`, `validation_leases`, `preview_caches`, and
`unclassified_objects`. The normalized baseline reports one master and one
preview per logical item; the local candidate reports one authoritative
artifact; and the Worker candidate reports one authoritative artifact plus one
validation record and one released validation lease. Every other role must be
zero, including caches not exercised by this workload. At `deleted` the collector
accepts and returns the token, keeps the exact same deployment identity, returns
both item counts and every census role at zero,
non-negative `request_bytes`, `duration_ms`, `peak_memory_bytes`,
`artifact_body_bytes`, and `full_image_decodes`, plus true `logical_absent`,
`capacity_released`, and `provider_absent`. The collector must derive cleanup
from the exact private batch through the canonical manifest and capacity owner
plus exact local/provider inspection. It must classify every item-owned
artifact-store object into the closed census, including zero-byte Worker fence
records; unknown objects increment `unclassified_objects` instead of being
ignored. Browser responses are not cleanup authority. The harness never emits
the private batch ID or token.

The collector is a deployment-specific privileged adapter and is not shipped in
this repository. Its implementation and human audit remain an open P6 gate. The
repository owns the closed input/output schema and fail-closed validator; the
dedicated `npm run test:performance --prefix tests/e2e` command fails when the
adapter or any required deployment input is absent, while the ordinary browser
suite reports the external acceptance case as skipped.

Committed evidence also returns integer `transfer_completed_at_ms`,
`manifest_committed_at_ms`, and their exact difference `completion_tail_ms`.
Both timestamps must fall inside the measured run and the tail must not exceed
the operator-approved `EFORMS_PERF_MAX_COMPLETION_TAIL_MS`. Deleted evidence
returns a closed `work_graph` with exact per-item counts for browser-to-
WordPress intent, artifact, completion, and delete calls; browser-to-Worker
artifact streams; Worker-to-R2 artifact-body writes (excluding the separately
censused validation-record and lease metadata writes) and Images inspections; WordPress-to-
Worker deletes; and `external_work_under_lock: 0`. The genuine-provider and
lifecycle commands separately carry review and health edges that are outside
this upload/delete workload.

```sh
EFORMS_PERF_BASELINE_URL=https://baseline.example/upload-test/ \
EFORMS_PERF_LOCAL_URL=https://local.example/upload-test/ \
EFORMS_PERF_WORKER_URL=https://worker-backed.example/upload-test/ \
EFORMS_PERF_BASELINE_BUILD_ID=baseline-commit \
EFORMS_PERF_LOCAL_BUILD_ID=candidate-commit \
EFORMS_PERF_WORKER_BUILD_ID=candidate-commit \
EFORMS_PERF_WP_METRICS_COMMAND=/secure/path/read-wordpress-metrics \
EFORMS_PERF_NETWORK_PROFILE=mobile-4g \
EFORMS_PERF_REGION=us-west \
EFORMS_PERF_WARM_COLD=warm \
EFORMS_PERF_MAX_COMPLETION_TAIL_MS=... \
EFORMS_PERF_LATENCY_MS=80 \
EFORMS_PERF_DOWNLOAD_KBPS=9000 \
EFORMS_PERF_UPLOAD_KBPS=3000 \
EFORMS_PERF_ITEM_COUNT=2 \
npm run test:performance --prefix tests/e2e
```

The closed composition names are `baseline-local-normalized`,
`candidate-local-artifact`, and `candidate-worker-r2`. The command runs each
scenario at least five times using one shared candidate build distinct from the
baseline, emits one JSON summary to stdout, rejects build or
deployment identity drift and series above 20% spread, applies the 10% median
threshold, requires authoritative exact cleanup, and proves from server-side
facts that Worker artifact bodies and full-image decodes bypass WordPress.
Browser DELETE responses remain diagnostic only. Keep the collector evidence
and emitted result in the access-controlled release record; the sanitized
request graph and retry count include attempts that fail before an HTTP
response. Do not commit either artifact.
