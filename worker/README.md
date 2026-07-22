# eForms media Worker

This package owns the public edge boundary for production artifact uploads and
authorized review delivery. It verifies one WordPress-signed item capability,
streams bytes to a write-once R2 key, inspects the exact stored version through
Cloudflare Images, and returns a signed receipt containing immutable object and
media facts. A separate short-lived review grant can stream the exact artifact
as an attachment or produce the fixed private JPEG preview. Neither path can
mutate a WordPress batch or submission.

`src/protocol.js` owns Worker-side envelope verification and signing.
`src/anchors.js` is generated from the Worker-owned subset of
`src/Anchors.php`; `worker/scripts/sync-anchors.php --check` keeps those fixed
bounds synchronized without creating a second authority. Upload, object, and
health envelopes apply the shared clock-skew allowance at expiry boundaries;
review grants expire strictly at `expires_at`.
`src/media.js` owns exact-version inspection plus bounded APNG and animated
WebP rejection; `src/heif.js` enforces the matching bounded still-image HEIF
container policy without decoding pixels. `src/index.js` owns the `/v1/upload` CORS/upload boundary,
signed `/v1/health` binding check, signed `/v1/object` exact-version
inspect/delete operations used by restore, GC, and uninstall, and the signed
`/v1/review` preview/download boundary. The PHP peer is
`src/Uploads/WorkerProtocol.php`; `tests/fixtures/worker_protocol.json` carries
language-neutral claims and payload/signature digests.

Successful inspection publishes one private zero-byte validation record whose
key and metadata bind the exact artifact version, etag, intent, policy, and
media facts. A second version-bound zero-byte lease admits one inspector at a
time. Exact-etag state transitions own stale takeover and release so an old
request cannot overwrite a successor lease; abandoned work becomes reclaimable
only after the upload window.
Existing-object retries may issue a receipt only from the validation record;
an in-flight loser therefore cannot observe unvalidated bytes as successful,
and validation failure preserves the object for WordPress-owned tombstone/GC
cleanup. Exact deletion removes the artifact, validation record, and lease,
while the `artifacts/` lifecycle rule remains the crash cleanup backstop.

## Bindings

The deployment must provide:

- `ARTIFACTS`: a private R2 bucket binding;
- `IMAGES`: a Cloudflare Images binding;
- `UPLOAD_RATE_LIMITER`: the native intent-keyed rate-limit binding declared in
  `wrangler.jsonc`; its fixed limit and period mirror `src/Anchors.php` and are
  checked by `npm test`;
- `EFORMS_SITE_ORIGIN`: the exact WordPress origin, with no path;
- `EFORMS_WORKER_ENVIRONMENT_ID`: the same deployment identity used by
  WordPress;
- `EFORMS_WORKER_ACTIVE_KEY_ID` and `EFORMS_WORKER_ACTIVE_KEY_B64`: the active
  integration key ID and unpadded base64url secret; and
- optionally, matching secondary key ID/secret bindings during rotation.

Keep all key material out of committed files. The key byte bound is owned by
`WORKER_INTEGRATION_KEY_BYTES` in `src/Anchors.php` and copied into the generated
Worker anchor module. Only the active key signs; the active and optional
secondary keys verify.

`wrangler.jsonc` declares the code entrypoint and binding names. Confirm the R2
bucket name and inject the site-specific text/secret bindings in the deployment
environment before deploying. WordPress uses `WorkerClient` for bounded signed
data-plane calls; it never receives Cloudflare management credentials.

## Verification

Run:

```sh
npm test --prefix worker
php tests/unit/test_worker_protocol.php
php tests/unit/test_worker_client.php
php tests/unit/test_r2_lifecycle_verifier.php
```

The offline tests use in-memory R2 and Images fakes. A configured test Worker
and bucket can run the genuine-provider lane:

```sh
EFORMS_CF_INTEGRATION=1 \
EFORMS_WORKER_URL=https://worker.example \
EFORMS_SITE_ORIGIN=https://forms.example \
EFORMS_WORKER_ENVIRONMENT_ID=integration \
EFORMS_WORKER_ACTIVE_KEY_ID=key-integration \
EFORMS_WORKER_ACTIVE_KEY_B64=... \
npm run test:integration --prefix worker
```

It uploads, genuinely inspects, previews, downloads, and deletes disposable
JPEG, PNG, WebP, HEIC, and HEIF-alias objects. It also exercises the maximum
accepted byte boundary, malformed/animated rejection, discarded-response retry,
wrong-origin/environment rejection, wrong-version preview/deletion failure, and
recovery with exact cleanup. Never point it at production customer data.
Discarding an already-received response proves idempotent application retry; it
does not prove transport-level response loss. Phase 6 still requires controlled
proxy/network-fault evidence for that separate failure mode.

Before activation, read the actual lifecycle rule with a separate
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
EFORMS_WORKER_ACTIVE_KEY_ID=key-integration \
EFORMS_WORKER_ACTIVE_KEY_B64=... \
EFORMS_CF_REPRESENTATIVE_MEDIA=1 \
EFORMS_CF_REPRESENTATIVE_DIR=/secure/non-customer-phone-fixtures \
EFORMS_CF_FAILURE_MATRIX=1 \
EFORMS_CF_FAULT_COMMAND=/secure/path/set-disposable-worker-fault \
EFORMS_CF_ROTATION_MATRIX=1 \
EFORMS_CF_ROTATION_COMMAND=/secure/path/rotate-disposable-worker-keys \
EFORMS_CF_WORDPRESS_ROTATION_PROBE_COMMAND=/secure/path/probe-disposable-wordpress-rotation \
EFORMS_CF_SECONDARY_KEY_ID=key-next \
EFORMS_CF_SECONDARY_KEY_B64=... \
EFORMS_CF_EMERGENCY_KEY_ID=key-emergency \
EFORMS_CF_EMERGENCY_KEY_B64=... \
npm run test:integration --prefix worker

EFORMS_CF_ACCOUNT_ID=... \
EFORMS_CF_BUCKET_NAME=... \
EFORMS_CF_API_TOKEN=... \
php worker/scripts/verify-r2-lifecycle.php

php tests/integration/test_remote_restore_drill.php
EFORMS_REMOTE_RESTORE_INTEGRATION=1 \
EFORMS_WORKER_URL=https://worker.example \
EFORMS_SITE_ORIGIN=https://forms.example \
EFORMS_WORKER_ENVIRONMENT_ID=integration \
EFORMS_WORKER_ACTIVE_KEY_ID=key-integration \
EFORMS_WORKER_ACTIVE_KEY_B64=... \
php tests/integration/test_remote_restore_drill.php
EFORMS_WP_PATH=/path/to/disposable-wordpress php tests/wp-runtime/uninstall-drain.php
```

The first restore command is a deterministic control-plane drill with a signed
test double; it does not contact R2. The explicitly gated variant uploads
disposable objects for the finalized, open-intent, delete-pending, and
interrupted-purge states before backup. It restores their manifest/capacity
authority, proves wrong-version rejection, exercises genuine inspect and
authorized preview/download, and requires every restored cleanup path to remove
an object that was actually present. Both variants restore conservative
capacity and the remote-purge checkpoint. Both commands fail closed; only the
gated variant supplies provider-backed restore evidence.

The failure matrix is permitted only on a disposable Worker/bucket. Its
absolute executable fault controller receives one argument:
`preview-failure`, `delete-failure`, or `clear`. It must finish the controlled
deployment transition, wait for readiness, and print one JSON object containing
that `mode`, a non-sensitive `deployment_id`, and `"ready": true`. Preview mode
must preserve R2 reads while making the genuine Images operation return the
retryable failure; deletion mode must make the genuine R2 delete operation
return the retryable failure without deleting the object; clear must restore the
normal bindings. The lane proves the exact object survives both failures,
recovers, and is finally absent. It emits sanitized `EFORMS_CF_FAULT_EVIDENCE`
records for the access-controlled release record. The controller owns any
Cloudflare management credentials; never expose them to the Worker or WordPress.

Representative media remains outside the repository. With
`EFORMS_CF_REPRESENTATIVE_MEDIA=1`, the absolute fixture directory must contain
non-customer `phone.jpg`, `phone.png`, `phone.webp`, `phone.heic`, and
`phone.heif` files captured from the approved device set. The lane sends their
exact bytes through genuine provider inspection and cleanup; fixture provenance
and device coverage remain in the access-controlled release record.

The rotation controller is permitted only for a disposable paired Worker and
WordPress deployment. It receives one phase: `install-secondary`,
`promote-secondary`, `remove-old`, `emergency-cutover`, or `restore`. Each call
must finish the corresponding transition, wait for readiness, and print only
JSON containing that `phase`, stable non-sensitive `target_id`, current
`deployment_id`, matching `environment_id`, and `"ready": true`. After every
transition the separate WordPress probe command must run this repository's
`tests/wp-runtime/rotation-probe.php` through `wp eval-file` on the paired
disposable WordPress deployment and return its JSON unchanged. The harness
checks the probe's non-sensitive site/Worker/environment pair fingerprint, the
deployed WordPress active/secondary key roles, genuine `WorkerClient` health,
stable target identity, and the active key that signs Worker results. It
keeps one disposable object across the transitions,
proves old/new overlap, promotion, old-key rejection after removal, emergency
rejection of both retired keys, retained-object access with the emergency key,
restoration, and exact cleanup. It emits sanitized
`EFORMS_CF_ROTATION_EVIDENCE`; the controller owns deployment credentials and
must never print key bytes, signed envelopes, or provider dumps.
This short disposable matrix proves transition mechanics only; it does not wait
through the normal overlap interval. Release evidence must separately retain
the former active verifier for the code-owned
`WORKER_SECONDARY_KEY_RETENTION_SECONDS` bound before removal and record the
promotion/removal timestamps.

Back up retained open and finalized manifests, including delete-pending
tombstones, managed-capacity state, remote-purge state, and the deployment
configuration that identifies the Worker and bucket. Back up integration keys
through the deployment secret system, never with the runtime tree. A restore is
accepted only after the exact stored object version can be inspected, operator
review can be authorized, conservative accounting is intact, open and pending
cleanup resume against their exact keys, an interrupted purge resumes, and
manifest-driven deletion succeeds.

For normal key rotation, stage the new key as secondary on WordPress and the
Worker, verify both runtimes, promote it to active on both, retain the old key as
secondary for the `WORKER_SECONDARY_KEY_RETENTION_SECONDS` value owned by
`src/Anchors.php`, then remove it. Only the active key signs. For emergency rotation, stop new grants, replace the
compromised key on both runtimes together without the overlap, verify signed
health, and then resume; in-flight capabilities retry or age into normal orphan
cleanup. Record key IDs and outcomes, never key bytes or signed envelopes.

Before enabling production writes, preserve the prior Worker version and the
WordPress configuration needed to stop new grants while keeping review, GC,
remote deletion, and old-key verification available. After the first R2 object
exists, rollback is fail-closed repair or an explicit retained-object
migration/expiry procedure; it is never an automatic local-storage fallback.

Performance acceptance uses the same device, fixture bytes, concurrency,
network profile, region, and warm/cold classification for at least five runs of
the baseline and candidate. Record median and worst selection-to-commit time,
transferred bytes, WordPress request bytes/duration/peak memory, retries, and
cleanup outcome in an access-controlled release record. Rerun when variance is
above 20 percent. `npm run test:performance --prefix tests/e2e` owns the
equivalent-work browser load and joins it to operator-captured WordPress metrics;
see `tests/e2e/README.md` for its required inputs. Do not commit generated
reports or customer/provider dumps.
