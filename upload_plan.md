# Managed Photo Uploads — Remaining Work Plan

> Mutable execution plan only. It lists unfinished work and is not behavior authority. Current behavior remains owned by `docs/Architecture_Router.md`, `docs/Owner_Index.md`, `docs/overview.md`, `docs/contracts/*`, code, and tests.

**Status:** Production activation only. Source and local migration work are complete; release remains blocked on human review, target provisioning, deployment, and live verification.

**Goal:** Activate Virtual Quote with one plugin-owned staged-photo workflow that stores privacy-normalized JPEG review masters and previews, supports ordinary phone photo formats, preserves the existing retry/finalization/security model, and removes the page-specific theme uploader.

**Retirement rule:** Delete this plan after every task is complete and its durable behavior is carried by active contracts, code, tests, and operator documentation. Do not convert completed task cards into history.

**Rebase rule:** If an active carrier changes a cited contract, add a blocking rebase task before continuing.

## Production Activation Context

Source implementation, local migration, and predeployment inspection are complete. Future work begins with human review and production provisioning; completed implementation phases are intentionally omitted.

Preserve these release facts and constraints:

- Production has one public webhead at `s1`; eForms is inactive, the Virtual Quote page has no shortcode, and the predeployment scan found no managed manifests.
- PHP/FPM resource limits are sufficient, but the production ImageMagick build lacks HEIC/HEIF delegates and WP-CLI is absent. Both remain activation blockers.
- The deployed plugin checkout is pruned and dirty, including deployment-only `src/Config.php` changes. Snapshot it and migrate supported values to `wp-content/eforms.config.php`; do not reset or pull across it.
- `UploadBatchStore` remains the public managed-storage owner. Authentication, atomic finalization, at-most-once email, signed private review, capacity safety, external GC, and synchronous upload behavior remain unchanged.
- Deploy the plugin and pass doctor/readiness checks before changing the template, page shortcode, or theme. Rollback stops new staged batches but retains review, capacity, and GC access until existing aggregates expire.
- No compatibility reader, parallel uploader, deployment-specific runtime owner, new configuration surface, WP-Cron worker, object storage, or customer gallery is part of this release.

### [ ] T9 Obtain human review, activate production, and close the release

- `Category:` Production release gate
- `Type:` migration
- `Owner:` human reviewer plus target operations
- `Depends On:` Completed source/local migration and predeployment inspection
- `Artifacts:` production package/runtime configuration; eForms and theme production checkouts; external GC schedule; WordPress page 1541; both source worktrees and active carriers
- `Reuse Target:` Use the documented `s1` SSH alias and production checkouts, eForms `RuntimeHealthDiagnostic`/`wp eforms doctor`, the existing `wp eforms gc` command, the staged Virtual Estimate template, and the plugin-owned upload UI. Do not add a deployment-specific uploader or diagnostic.
- `State Contract:` Preserve the existing Virtual Quote page copy and the empty predeployment managed-aggregate state. Mutate the target image/PHP tooling, eForms configuration and schedule, plugin/theme versions, staged template, and page shortcode only after sign-off. Rollback disables new staged batches but preserves review, capacity, and GC access until any created aggregates expire; no existing WordPress content or media is discarded.
- `UI Surface Preflight:` The plugin owns picker/actions/cards/status, while the theme owns only surrounding section composition and approved variables.
- `Composition Target:` Append the shortcode to existing page 1541 content and keep the live page free of any theme-owned uploader node or script.
- `Work:`
  - Obtain human review of image privacy/quality, manifest migration, capacity/crash recovery, credential redaction, finalization/email ordering, signed review access, source diffs, and predeployment evidence before any production mutation.
  - Snapshot the dirty production plugin checkout, identify its deployment-only `src/Config.php` changes without exposing secrets, and migrate those values to the supported `wp-content/eforms.config.php` carrier before replacing runtime files. Do not reset or pull across the pruned tree.
  - Provision HEIC/HEIF-capable ImageMagick plus the matching PHP Imagick build, WP-CLI, protected private storage, managed capacity/free-space, enabled/tuned throttle, and externally scheduled GC without weakening the existing PHP/FPM request, memory, or execution limits.
  - Deploy the eForms plugin first while keeping the production template synchronous; run `wp eforms doctor` and require every staged readiness check to pass through WP-CLI and a web-runtime diagnostic.
  - Deploy the staged template and append `[eform id="virtual-estimate" cacheable="false"]` to the existing Virtual Quote page content, then deploy the theme migration.
  - Verify upload, retry/removal, validation rerender, finalizing freeze, successful submit, company gallery, responsive layout, and expired-form behavior on the live route.
  - Recheck active carriers against implemented behavior and remove stale original/HEIC-opt-in/theme-owner wording.
  - Confirm both worktrees contain only intended changes. Exclude generated `test-results/`; handle the `agent_docs` submodule pointer separately unless it is deliberately part of another change.
  - Split commits into coherent contract, image/storage, browser, theme migration, and operational units where dependencies allow; do not hide untracked production/test files.
  - Document deployment order as plugin first, then theme. During rollback, disable new staged batches but retain review, capacity, and GC owners until existing aggregates expire.
- `Done When:` Human sign-off predates production writes, every production readiness check and live workflow is green, deployment/rollback owners are named, both worktrees are intentional, and this plan can be deleted without losing behavior or open work.
- `No-Fallback:` Do not deploy the staged template, append the shortcode, or activate the plugin while HEIC/HEIF, WP-CLI, doctor, GC, or human review is incomplete.
- `Verified Via:` explicit human approval; production package/runtime evidence; per-webhead web and WP-CLI doctor; external GC evidence; live desktop/mobile workflow; final diff review; `git diff --check`; clean status inventory; deployment checklist
- `Verification Command:` `ssh s1 'cd /var/www/flooringartists.com && wp eforms doctor && wp eforms gc --dry-run' && E2E_BASE_URL=https://flooringartists.com npm --prefix /home/zhenya/projects/the-artist run test:e2e -- --grep "virtual estimate upload" && curl -fsS https://flooringartists.com/virtual-quote/ | rg 'data-eforms-upload="1"' && ! curl -fsS https://flooringartists.com/virtual-quote/ | rg 'virtual-estimate-upload-status|virtual-estimate\\.js|data-selected-singular|data-selected-plural' && git diff --check && git -C /home/zhenya/projects/the-artist diff --check`; additionally complete the approved real desktop/mobile upload, retry/removal, rerender, submit, gallery, and expiry checklist without retaining test submissions.

## Remaining Release Checks

Run the complete local lane immediately before packaging:

```sh
export EFORMS_REQUIRE_STAGED_HEIC=1
find tests/unit tests/integration tests/smoke -type f -name 'test_*.php' -print0 | sort -z | xargs -0 -n1 php
php tests/wp-runtime/run.php
php tests/tools/assert-template-slugs.php
npm ci --prefix tests/e2e
npm test --prefix tests/e2e
node --check assets/forms.js
git diff --check

# Managed persistence stays behind the facade/internal capacity boundary.
rg -n "manifest\\.json|managed-capacity|capacity\\.lock" src \
  --glob '!src/Uploads/UploadBatchStore.php' \
  --glob '!src/Uploads/ManagedCapacityStore.php'

# Managed v1 artifacts and routes remain absent.
rg -n "original_relpath|original_bytes|candidate_original_bytes|deleted_original_bytes|preview\\|original|eforms_review_variant=original" \
  src tests templates docs README.md

# Credentials and private implementation details stay out of presentation/email.
rg -n "batch_secret|batch_secret_digest|eforms_upload_batches|X-EForms-Batch-Secret|eforms-private|manifest\\.json" \
  src/Email templates/email templates/pages

# The theme does not regain a page-specific uploader owner.
rg -n "virtual-estimate-upload-status|virtual-estimate\\.js|data-selected-singular|data-selected-plural" \
  templates/forms/virtual-estimate.json /home/zhenya/projects/the-artist
```

Interpret scan results by owner: synchronous uses of “original” are legitimate; managed-upload matches require removal or an explicit test-only rationale.
