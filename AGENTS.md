# User Preferences
- I am a product designer with little experience with coding - NOT a developer
- I need much more detailed explanations than you would give to a senior developer
- Always make smaller, incremental changes rather than large modifications
- I want to learn while coding, so break everything down into simple steps
- For larger or riskier changes, provide specific warnings and signals like
"⚠️ LARGE CHANGE ALERT" or "🔴 HIGH RISK MODIFICATION"
- Always remind me to verify larger changes before they're implemented

# Be in Learning Mode
- When writing code or concepts, provide educational context and explanations. Break down complex topics into digestible parts, explain your reasoning process, and aim to help me understand not just what to do but why it works that way. Feel free to be more verbose in your explanations when teaching new concepts.
- When making code changes, explain each step of the way and break each code change down to its individual changes. Add additional comments for what you're doing and why that I can edit or remove as I see fit.
- Add warnings for auto-accepting code changes, especially ones that are larger or more complex so that I can review and learn from them.
- Use clear visual signals like emojis (⚠️ 🔴 ⏸️) when making larger or riskier changes
- Always pause and wait for my confirmation before implementing significant modifications

# Code authoring conventions (for agents)

**Style & linting**
- Follow **WordPress Coding Standards (WPCS)** using **PHPCS** rulesets: `WordPress-Core`, `WordPress-Docs`, `WordPress-Extra`.
- Naming: **snake_case** for functions, **StudlyCaps** for classes, and **$lower_case_with_underscores** for variables.
- Prefix all globals (functions, classes, hooks, options) with a unique plugin prefix, e.g. `eforms_`.

**Security (quick checklist)**
- Always **Validate → Sanitize → Escape** (in that order).
- Nonces for state-changing requests: `check_admin_referer()` / `wp_verify_nonce()`.
- Capabilities: gate actions with `current_user_can()`; never rely on the UI only.
- Database: use `$wpdb->prepare()` (no string interpolation in SQL).
- Files/paths: validate extensions and size limits from config; prefer `WP_Filesystem`.
- Output escaping: see “Escaping map” below.

**Escaping map (what to use where)**
- Text node → `esc_html( $s )`
- HTML attribute → `esc_attr( $s )`
- URL/href/src → `esc_url( $s )`
- Limited user HTML → `wp_kses_post( $html )`

**Internationalization (i18n)**
- Wrap strings: `__( 'Text', 'eforms' )`, `_x()`, and `esc_html__()` as appropriate.
- Load text domain early (e.g., `plugins_loaded`).
- Avoid concatenating sentences; use placeholders:  
  `sprintf( __( 'Hello, %s', 'eforms' ), $name )`.

**Docblocks & naming hygiene**
- Add file headers and function/class DocBlocks (purpose, params, return types).
- For public APIs, document inputs, side effects, error modes.
- Prefer small, pure helpers; keep side effects in orchestrators.

**Actions & filters**
- Prefix hook names (e.g., `eforms_before_send`).
- Document each filter’s expected input, return type, and timing.

**Minimal tooling (non-blocking)**
- Provide a repo-level `.phpcs.xml.dist` using WPCS rulesets.
- Optional npm scripts (if present) for convenience:
  - `npm run lint:php` → `phpcs -q`
  - `npm run lint:fix` → `phpcbf -q || true`

**Tiny end-to-end example (nonce, caps, sanitize, escape)**
```php
/**
 * Save a setting (example).
 */
function eforms_save_setting() {
	if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'eforms_save' ) ) {
		wp_die( esc_html__( 'Security check failed.', 'eforms' ) );
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'eforms' ) );
	}

	// Validate + sanitize
	$raw = isset( $_POST['eforms_message'] ) ? wp_unslash( $_POST['eforms_message'] ) : '';
	$val = sanitize_text_field( $raw );

	update_option( 'eforms_message', $val, false );

	// Safe redirect (no output before this)
	wp_safe_redirect( admin_url( 'options-general.php?page=eforms&updated=1' ) );
	exit;
}

# Spec authoring conventions (for agents)
## Canonicality
- Matrices are authoritative for **outcomes** (token_ok, require_challenge, identifiers).
- **Do not edit generated blocks** (between `<!-- BEGIN GENERATED:` / `<!-- END GENERATED:`).
- Generated blocks must be updated only by editing the YAML and re-running the generator.
- Helper contracts are authoritative for **behavior/returns** (inputs, side-effects, idempotency, hit/miss/expired).
- Narrative must not contradict either.
- **When contradictions arise:**
	1) Fix the YAML/matrix first (if matrix is wrong), regenerate, then align narrative.
	2) If matrix is correct, edit the narrative only.
	3) PR description must state the canonical source used to resolve the conflict.
- If they conflict, the PR MUST fix both and state which source is canonical in the PR description.
- Verifier checks that **narrative + matrices + helper contracts** are aligned (see `scripts/spec_lint.py`), and fails on **source drift** (YAML ≠ generated Markdown).
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
- For cookie/NCID/prime semantics, edit the YAML first and regenerate; do not hand-edit generated tables. Also check the QA checklist links point to the correct anchors.

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
- [ ] Regenerated all **generated blocks**; no source drift (YAML ⇔ Markdown).
- [ ] Ran `scripts/spec_lint.py` and link-check locally; CI also green.
- [ ] ⚠️ LARGE CHANGE ALERT: Used when cookie/NCID/PRG semantics change (add a 2–3 bullet impact summary).
- [ ] 🔴 HIGH RISK MODIFICATION: Used when changing header boundaries (who may send `Set-Cookie`) or rotation/TTL; include a quick rollback note.
