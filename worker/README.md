# eForms media Worker

This package owns the public edge boundary for production artifact uploads,
asynchronous validation, bounded gallery status, and accepted-only review
delivery. Ingress verifies one WordPress-signed item capability, streams bytes
to a write-once R2 key, HEADs the exact stored version, awaits validation-Queue
publication, and returns a signed Stored receipt containing immutable storage
facts only. A Queue consumer inspects that exact version through Cloudflare
Images and conditionally creates one immutable accepted or rejected terminal
result. A separate short-lived review grant can stream or preview an artifact
only while its exact accepted result still matches. No Worker path can mutate a
WordPress batch or submission.

`src/protocol.js` owns Worker-side version `3` envelope verification and signing.
`src/managed-artifact-key.js` owns the Worker peer of the canonical
`artifacts/{h2(batch_id)}/{batch_id}/{ordinal}-{intent_id}.{ext}` R2 key. The
extension comes from the signed declared MIME and every item in one batch
shares the same namespace; finalization never copies or renames an R2 object.
`src/validation-result.js` owns terminal-result key derivation, strict JSON
shape validation, conditional creation, and exact matching for Queue, status,
review, and cleanup handlers. A result key is
`{object_key}.validation-{sha256_hex(object_version)}.json`; version `1` result
bodies and version `1` Queue jobs use the exact fields in
`docs/contracts/Public_Contracts.md` and reject unknown fields.
`src/anchors.js` is generated from the Worker-owned subset of
`src/Anchors.php`; `worker/scripts/sync-anchors.php --check` keeps those fixed
bounds synchronized without creating a second authority. Upload, status,
object, and health envelopes apply the shared clock-skew rule only where the
public contract permits it; `upload_until`, `accept_until`,
`validation_until`, and review-grant expiry close at equality.
`src/media.js` owns Queue-only exact-version inspection plus bounded APNG and animated
WebP rejection; `src/heif.js` enforces the matching bounded still-image HEIF
container policy without decoding pixels. `src/index.js` owns the `/v1/upload`
CORS/upload boundary, Queue `batch()` consumer, signed `/v1/gallery-status`,
signed `/v1/health` binding check, signed `/v1/object` exact result/artifact
inspect/delete operations used by restore, GC, and uninstall, and signed
`/v1/review` accepted-only preview/download. The PHP peer is
`src/Uploads/WorkerProtocol.php`; `tests/fixtures/worker_protocol.json` carries
language-neutral claims and payload/signature digests.

Ingress verifies origin, envelope, environment, storage identity, validation
contract, deadline ordering, rate, content type, and declared length before R2
access. It conditionally creates the deterministic key and never overwrites.
An existing object is recoverable only when its key, size, immutable version,
ETag, and custom metadata exactly match the signed identity and policy; the
retry body is non-authoritative and is cancelled. Ingress HEADs the exact winner
and calls `VALIDATION_QUEUE.send()` with one strict idempotent job. It returns a
Stored receipt only after `send()` resolves while `upload_until` remains open.
R2 success plus Queue failure returns a retryable error and no receipt; a later
exact retry may republish the job while the boundary remains open.

Queue delivery re-HEADs the exact object before inspection and again before
result creation. A supported exact job that produces accepted media or a
permanent media-policy rejection conditionally writes the first valid result;
a result is authoritative only when its validation completion and R2
server-recorded upload time are both strictly before `validation_until`;
a late result is deletion-attempted and permanently acknowledged;
a matching existing winner acknowledges, and a contradictory valid winner
alerts then acknowledges without overwrite. Transient R2/Images failures retry
only before `validation_until`. Unsupported validation versions acknowledge
without provider work. Missing
objects before that boundary retry; missing objects at or after it acknowledge
without inspection or result creation. Malformed jobs and permanent
identity/policy conflicts alert and acknowledge without creating a result.
Provider exceptions never become rejected results. Queue exhaustion follows the
configured retry policy into the deployment-owned DLQ.

WordPress registration and submission never wait for Queue validation. Gallery
status derives `accepted`, `pending`, or `unavailable` for one exact finalized
manifest snapshot and never authorizes bytes. Review/download independently
require the exact accepted result and exact R2 HEAD/read. Cleanup runs only after
the WordPress-owned Queue-consumer and in-flight capability drains, deletes the
terminal result before the artifact, confirms both absent, and returns a signed
result before WordPress releases managed capacity. The `artifacts/` lifecycle
rule remains a late whole-namespace crash/residue backstop.

## Bindings

The deployment must provide:

- `ARTIFACTS`: a private R2 bucket binding;
- `IMAGES`: a Cloudflare Images binding;
- `VALIDATION_QUEUE`: a producer binding to the deployment-owned validation
  Queue; the same Worker deployment owns its `batch()` consumer;
- `UPLOAD_RATE_LIMITER`: the native intent-keyed rate-limit binding declared in
  `wrangler.jsonc`; its fixed limit and period mirror `src/Anchors.php` and are
  checked by `npm test`;
- `EFORMS_WORKER_URL`: the exact public Worker HTTPS origin used by WordPress
  to derive the storage identity;
- `EFORMS_SITE_ORIGIN`: the exact WordPress origin, with no path;
- `EFORMS_WORKER_ENVIRONMENT_ID`: the same deployment identity used by
  WordPress;
- `EFORMS_VALIDATION_CONTRACT_VERSION`: the active validation contract the
  Worker accepts for new grants;
- `EFORMS_WORKER_ACTIVE_KEY_ID` and `EFORMS_WORKER_ACTIVE_KEY_B64`: the active
  integration key ID and unpadded base64url secret; and
- optionally, matching secondary key ID/secret bindings during rotation.

Keep all key material out of committed files. The key byte bound is owned by
`WORKER_INTEGRATION_KEY_BYTES` in `src/Anchors.php` and copied into the generated
Worker anchor module. Only the active key signs; the active and optional
secondary keys verify.

`wrangler.jsonc` declares the code entrypoint, R2, Images, limiter, Queue
producer/consumer, and DLQ bindings. Queue batch size, timeout, concurrency,
retry, and DLQ settings are fixed by `src/Anchors.php` and synchronized through
the existing anchor check rather than becoming operator configuration. Confirm
the bucket, Queue, and DLQ names and inject the site-specific text/secret
bindings in the deployment environment before deploying. WordPress uses
`WorkerClient` for bounded signed data-plane calls; it never receives Cloudflare
management credentials.

## Verification

Run:

```sh
npm test --prefix worker
php tests/unit/test_worker_protocol.php
php tests/unit/test_worker_client.php
php tests/unit/test_worker_deployment_preflight.php
php tests/unit/test_r2_lifecycle_verifier.php
```

The offline tests use in-memory R2, Queue, and Images fakes. They cover upload,
Queue acceptance/failure, duplicate delivery, result races, status, accepted-only
review, and drained deletion without provider credentials. A configured test Worker
and bucket can run the genuine-provider lane:

```sh
EFORMS_CF_INTEGRATION=1 \
EFORMS_WORKER_URL=https://worker.example \
EFORMS_SITE_ORIGIN=https://forms.example \
EFORMS_WORKER_ENVIRONMENT_ID=integration \
EFORMS_VALIDATION_CONTRACT_VERSION=managed-image-v1 \
EFORMS_WORKER_ACTIVE_KEY_ID=key-integration \
EFORMS_WORKER_ACTIVE_KEY_B64=... \
npm run test:integration --prefix worker
```

It uploads, publishes, genuinely consumes and inspects, waits for the terminal
result, previews, downloads, and deletes disposable
JPEG, PNG, WebP, HEIC, and HEIF-alias objects. It also exercises the maximum
accepted byte boundary, malformed/animated rejection, discarded-response retry,
wrong-origin/environment/validation-version rejection, Queue publication and consumption,
wrong-version preview/deletion failure, and recovery with exact result/artifact
cleanup. Never point it at production customer data.
Discarding an already-received response proves idempotent application retry; it
does not prove transport-level response loss. Phase 6 still requires controlled
proxy/network-fault evidence for that separate failure mode.

Before activation, run `php worker/scripts/deployment-preflight.php` against the
deployment source to confirm the producer binding, the consumer targeting that
same Queue, the Anchor-owned retry/batch/concurrency settings, the configured
validation contract, and the declared DLQ. Also confirm the DLQ is observable
by the operator in Cloudflare. This
deployment check is required because signed health proves only non-mutating
producer/runtime dependencies without publishing a synthetic customer-like
message. Then read the actual lifecycle rule with a separate
management-token process:

```sh
EFORMS_CF_ACCOUNT_ID=... \
EFORMS_CF_BUCKET_NAME=... \
EFORMS_CF_API_TOKEN=... \
php worker/scripts/verify-r2-lifecycle.php
```

The command performs one read-only Cloudflare API request and never exposes the
token to WordPress or the Worker. Production activation still requires both
configured checks and human review.

After activation, the operator release evidence must include category-level
Worker operation coverage: transfer, registration, validation, review readiness,
retry, DLQ, cleanup, and residue. Treat a missing category as an operations
warning to close before release acceptance. Do not put object keys, signed
envelopes, customer values, filenames, secret material, or provider dumps into
the release record.

## Operational acceptance

Use disposable non-customer objects and a non-production bucket for every
pre-activation drill. The genuine-provider lane, lifecycle verifier, control-
plane restore drill, and disposable WordPress uninstall drill are separate
gates because no one credential or runtime should own all of them:

```sh
EFORMS_CF_INTEGRATION=1 \
EFORMS_WORKER_URL=https://worker.example \
EFORMS_SITE_ORIGIN=https://forms.example \
EFORMS_WORKER_ENVIRONMENT_ID=integration \
EFORMS_VALIDATION_CONTRACT_VERSION=managed-image-v1 \
EFORMS_WORKER_ACTIVE_KEY_ID=key-integration \
EFORMS_WORKER_ACTIVE_KEY_B64=... \
npm run test:integration --prefix worker

EFORMS_CF_ACCOUNT_ID=... \
EFORMS_CF_BUCKET_NAME=... \
EFORMS_CF_API_TOKEN=... \
php worker/scripts/verify-r2-lifecycle.php

EFORMS_REMOTE_RESTORE_INTEGRATION=1 \
EFORMS_WORKER_URL=https://worker.example \
EFORMS_SITE_ORIGIN=https://forms.example \
EFORMS_WORKER_ENVIRONMENT_ID=integration \
EFORMS_VALIDATION_CONTRACT_VERSION=managed-image-v1 \
EFORMS_WORKER_ACTIVE_KEY_ID=key-integration \
EFORMS_WORKER_ACTIVE_KEY_B64=... \
php tests/integration/test_remote_restore_drill.php
EFORMS_WP_PATH=/path/to/disposable-wordpress php tests/wp-runtime/uninstall-drain.php
```

Use the paired local WordPress runtime only for browser/provider smoke and
doctor-style checks. A symlinked eForms checkout in that runtime is not a valid
target for the uninstall-drain proof, because the proof creates and deletes
fixture plugins and requires a disposable WordPress install with the sentinel.

The first restore command is a deterministic control-plane drill with a signed
test double; it does not contact R2. The explicitly gated variant uploads
disposable objects for the finalized, open-intent, delete-pending, and
interrupted-purge states before backup. It restores their manifest/capacity
authority, proves wrong-version rejection, exercises genuine inspect and
authorized preview/download, and requires every restored cleanup path to remove
an object that was actually present. Both variants restore conservative
capacity and the remote-purge checkpoint. Both commands fail closed; only the
gated variant supplies provider-backed restore evidence.

Back up retained open and finalized manifests, including validation contract
and deadline facts plus delete-pending
tombstones, managed-capacity state, remote-purge state, and the deployment
configuration that identifies the Worker, bucket, Queue, and DLQ. Back up integration keys
through the deployment secret system, never with the runtime tree. A restore is
accepted only after the exact stored object version and any matching terminal
result can be inspected, accepted-only operator review can be authorized,
conservative accounting is intact, open and pending cleanup resume against their
exact keys, an interrupted purge resumes, and manifest-driven result/artifact
deletion succeeds.

For normal key rotation, stage the new key as secondary on WordPress and the
Worker, verify both runtimes, promote it to active on both, retain the old key as
secondary for the `WORKER_SECONDARY_KEY_RETENTION_SECONDS` value owned by
`src/Anchors.php`, then remove it. Only the active key signs. For emergency rotation, stop new grants, replace the
compromised key on both runtimes together without the overlap, verify signed
health, and then resume; in-flight capabilities retry or age into normal orphan
cleanup. Record key IDs and outcomes, never key bytes or signed envelopes.

For a validation-contract deployment, first begin a WordPress-owned retirement
barrier for the old version and prove it has zero retained schema-7 references
before changing the Worker-required validation contract. The Worker accepts only
the configured validation contract; there is no runtime old-reader allowlist,
provider selector, or compatibility reader for retained objects or a superseded
Worker protocol. Unsupported jobs and unsupported validation contracts
acknowledge without inspection before provider work.

The version retirement checklist is fail-closed: begin the version-specific
barrier, preserve review and cleanup credentials, drain ordinary Queue retries,
inspect and classify DLQ entries without replaying customer bodies, wait
through the old-version deadline drain, then run:

```sh
wp eforms gc --begin-validation-retirement=managed-image-v1
wp eforms gc --verify-validation-retirement=managed-image-v1
```

The verify command requires the barrier, scans retained schema-7 manifests
through `UploadBatchStore` under the exclusive upload-lifecycle lease, does not
contact Worker/R2/Queue, and writes only bounded ready state to the barrier when
`references=0`. `Validation contract retirement ready: ... references=0` means
the deployment can switch both runtimes to the new validation contract.
`Validation contract retirement blocked: ... references=N accepted=N ...`
means cutover is blocked; keep the Worker configured to the retiring validation
contract and repeat cleanup/drain until the command exits successfully. After
both runtimes are switched and signed Worker health confirms the new contract,
run:

```sh
wp eforms gc --complete-validation-retirement=managed-image-v1
```

Resume grants only for the active contract after completion.

Before enabling replacement production writes, preserve the prior deployment
artifact and the WordPress configuration needed to stop new grants while
keeping review, GC, remote deletion, and retained validation-result readers
available. Before the first replacement artifact is stored, rollback may restore
the prior deployment. Afterward, rollback is fail-closed repair-forward or an
explicit retained-object expiry procedure; it is never an automatic local-storage
fallback or dual protocol.
