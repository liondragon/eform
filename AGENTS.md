# Spec authoring conventions (for agents)

## Canonicality
- Matrices are authoritative for **outcomes** (token_ok, require_challenge, identifiers).
- Helper contracts are authoritative for **behavior/returns** (inputs, side-effects, idempotency, hit/miss/expired).
- Narrative must not contradict either.
- If they conflict, the PR MUST fix both and state which source is canonical in the PR description.
- Verifier checks that **narrative + matrices + helper contracts** are aligned (see `scripts/spec_lint.py`).
- Normative vs. informative hierarchy lives in [`docs/SPEC_CONTRACTS.md#sec-normative-note`](docs/SPEC_CONTRACTS.md#sec-normative-note); other specs must include or link to that section instead of duplicating it.

## Roles
- Spec Agent: finds contradictions; proposes minimal normative edits.
- Implementation Agent: applies diffs; updates code/docs.
- Verifier: confirms alignment and runs spec-lint in CI.

## Scope
- These conventions apply **only to Markdown docs** (`*.md`) in `/docs/` and top-level `README*.md`.
- They **do not apply to source code** (`/src`, `/assets`, templates, tests, build scripts, etc.). Code follows its own language/tooling standards.
- Inside Markdown:
	- The “tabs for lists” rule applies **only to list indentation**.
	- **Fenced code blocks are verbatim**: do not re-indent or convert spaces/tabs inside them.
- Linters:
	- Configure format checks to target Markdown files only.
	- Optional rule “forbid tabs outside lists and fenced code blocks” must be scoped to `*.md` files.
- This file defines **how to edit docs** (style, anchors, links). The **spec** remains the source of truth for behavior.

## Headings
- Start at column 1 (no indentation).
- Use `#` prefixes: `#`, `##`, `###`, …
- Add a stable ID to any heading that may be linked to:
	- Example: `### 7.1.4 NCID fallback {#sec-ncid}`
- Section numbers are decorative; the `{#id}` anchor is authoritative.
- Do **not** rename or reuse existing `{#id}` values.

## Cross-references
- Always link to an explicit `#id` (no auto-slugs).
- Refer by name + optional number:
	- Example: `See [Security → Submission Protection (§7.1)](#sec-submission-protection).`
- IDs must use `sec-` prefix, lowercase-kebab-case, and remain stable forever.
- If you move content, **keep the old anchor** and add a new one; never break existing links.

## Code formatting
- Inline code uses backticks: `` `eforms_mode` ``.
- Code blocks use fenced triples with a language:
	```php
	$cfg = Config::get('security.token_ttl_seconds');
	```
- Do **not** rely on indentation for code blocks; always use fences.

## Lists & indentation
- Use **tabs** (not spaces) for list indentation.
- Indent one level with **one tab**; deeper levels add one tab per level.
- Avoid mixing tabs and spaces in the same list.

## Anchors & numbering
- Every section that might be referenced MUST include a stable `{#id}`.
- Keep existing section numbers if present, but treat them as **decorative** only.
- Prefer references by **heading name + anchor**; the number is optional.

## Security (§7) edits (special care)
- Do not change anchor IDs under Security; add new anchors if you introduce new subsections.
- When editing cookie/NCID/prime semantics, also check the QA checklist links point to the correct anchors.

## Minimal diff hygiene
- Preserve tabs in lists.
- Keep line-wrapping stable; avoid unrelated whitespace churn.
- When adding anchors, place them inline with the heading: `### Title {#sec-id}`.

## CI (recommended checks)
- Fail if any `[...](#id)` target is missing.
- Require `{#id}` on any heading that is referenced.
- **Forbid leading whitespace before heading markers** (`#` must be at column 1).
- Forbid **tab characters** outside lists and fenced code blocks if the linter requires it (configurable).
- Optionally check that spec cross-references to §7/§13/§19 use the canonical anchors:
	- `#sec-submission-protection`, `#sec-cookie-mode`, `#sec-hidden-mode`, `#sec-ncid`,
	- `#sec-success`, `#sec-request-lifecycle-post`, `#sec-request-lifecycle-get`.

## PR checklist (copy into descriptions)
- [ ] Headings are flush-left; anchors added where referenced.
- [ ] All new/changed links use explicit `#id`.
- [ ] No broken anchors (CI link-check passes).
- [ ] Lists use tabs for indentation; no mixed tab/space.
- [ ] No accidental reflow/whitespace-only churn.
- [ ] If editing Security §7, QA matrix links updated as needed.
- [ ] If adjusting the normative/informative hierarchy, edits land in `docs/SPEC_CONTRACTS.md#sec-normative-note` (other files should reference/include it).
