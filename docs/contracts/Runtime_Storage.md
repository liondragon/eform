# Runtime Storage

This file captures runtime storage, token, upload, and maintenance contracts. Operator setup guidance belongs in `README.md` and `docs/overview.md`; exact implementation lives in code and tests.

## Private Storage Root

All runtime artifacts written under `wp_upload_dir()` live under:

```text
${uploads.dir}/eforms-private/
```

Runtime must create `index.html`, `.htaccess`, and `web.config` deny/protection files for private directories it owns. Directory permissions are `0700`; file permissions are `0600`. Permission or hardening failures fail closed on paths that need private storage.

## Layout

| Artifact | Path |
| --- | --- |
| Token records | `tokens/{h2}/{sha256(token)}.json` |
| Ledger markers | `ledger/{form_id}/{h2}/{submission_id}.used` |
| Uploads | `uploads/{Ymd}/{submission_id}-{file_index}-{sha16}.{ext}` |
| Throttle state | `throttle/{h2}/{ip_hash}.tally` and `.cooldown` |
| JSONL logs | `logs/` |
| Fail2ban file | `f2b/` unless configured otherwise |
| Declined-review files | `declined/` |
| GC lock | `gc.lock` |

`{h2}` is the first two hex characters of `sha256(id)` and is derived through the shared helper.

## Filesystem Assumptions

- Token writes use write-to-temp plus rename in the same directory.
- Ledger reservation uses atomic exclusive-create.
- Throttling and JSONL logging require reliable `flock()` semantics.
- Multi-webhead/container deployments must share persistent storage that preserves those filesystem semantics. Ephemeral per-container storage is unsupported.

## Token And Ledger Contract

- Hidden-mode GET render mints and persists a hidden token record before emitting hidden security fields.
- JS-minted mode obtains token records only through `POST /eforms/mint`.
- Token records include `mode`, `form_id`, `instance_id`, `issued_at`, and `expires`.
- Posted mode hints are informational; validated mode comes from the persisted token record.
- Token validation is read-only. It must not mutate records, headers, or ledger state.
- The ledger marker is reserved immediately before side effects. `EEXIST` is duplicate submission; other filesystem failures map to ledger IO failure.
- Once a ledger marker exists, only `wp eforms gc` may delete it.
- Email-send failure after ledger reservation does not reopen the token.

## Cache Safety

Responses that embed hidden security metadata, respond to `eforms_*` result query args, or return PRG result pages must send private no-store cache headers. If headers cannot be set for a hidden-token response, runtime must not mint or emit hidden tokens in a cache-unsafe response.

## Upload Policy

- Accepted upload token mappings:
  - `image` -> `image/jpeg`, `image/png`, `image/gif`, `image/webp`
  - `pdf` -> `application/pdf`
- Default exclusions include SVG, HEIC, HEIF, and TIFF.
- Validation requires agreement between accept token, extension, and `finfo` MIME result.
- `fileinfo` is required for upload attempts; if unavailable, upload attempts fail deterministically.
- Display filename starts from the client name but strips paths, control characters, CR/LF, unsafe dot/space runs, and excessive length.
- Stored filename is private and collision-resistant: `{Ymd}/{submission_id}-{file_index}-{sha16}.{ext}`.
- Runtime must not overwrite existing upload paths.
- Uploads are deleted after successful send unless retention applies. Failed sends follow retention policy.

## Throttle Contract

- Throttle is optional and per resolved client IP.
- It uses a fixed 60-second byte-counter window plus optional cooldown sentinel.
- On lock acquisition failure, throttle enforcement is skipped for that request and a warning is logged when logging is enabled.
- Hard-fail response semantics:
  - hidden GET render: HTTP 200 inline per-form error with no-store cache headers
  - POST submit: HTTP 429 with `Retry-After`
  - `/eforms/mint`: HTTP 429 with `Retry-After`

## GC Contract

- The plugin does not schedule WP-Cron for GC.
- Operators run `wp eforms gc` through system cron or an equivalent external trigger.
- GC uses a single-run lock to avoid overlapping work.
- GC targets expired token records, eligible ledger markers, stale throttle files, retained uploads, old logs, Fail2ban rotated siblings, and old declined-review files.
- Ledger markers are eligible only after `TOKEN_TTL_MAX + LEDGER_GC_GRACE_SECONDS` from marker mtime.
- Dry-run reports candidate counts and bytes without deleting.

