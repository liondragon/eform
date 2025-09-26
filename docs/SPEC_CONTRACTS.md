# Spec Contracts

> Single place for canonicality, ownership, and the helper-contract template.
> This file is **normative** unless a paragraph is explicitly marked “non-normative”.

<!--8<-- [start:sec-normative-note] -->
<a id="sec-normative-note"></a>Normative vs. Non-normative
This front matter summarizes the single canonical hierarchy defined in [Spec Contracts → Canonicality & Precedence (§1)](#sec-canonicality). Update that section first, then link to it from any new mandates instead of restating hierarchy in full.

| Scope | Authoritative source | Notes |
|-------|----------------------|-------|
| Runtime behavior (code paths, fixtures) | PHP implementation, helper fixtures, and verifier scripts | Runtime sources remain the ground truth for execution details; this spec references them without overriding behavior. |
| Spec constraints (matrices, helper contracts, narrative) | `docs/SPEC_CONTRACTS.md#sec-canonicality`, Security §7 matrices, anchored helper contracts | These define normative outcomes, inputs/outputs, ranges, and precedence rules. Narrative changes must stay aligned with the matrices and helper contracts they summarize. |
| Informative material | Appendices, diagrams, explanatory callouts | Marked non-normative; they illustrate behavior but do not change requirements. |

- Narrative text, tables, and matrices are normative unless explicitly marked otherwise.
- Diagrams and callouts are non-normative references only; they illustrate the normative rules above.
- **Conflict resolution (normative):** Follow the hierarchy above when sources disagree, and keep [Spec Contracts → Canonicality & Precedence (§1)](#sec-canonicality) and this hub in sync so verifiers have a single entry point.
<!--8<-- [end:sec-normative-note] -->

## 1) Canonicality & Precedence {#sec-canonicality}
- **Matrices are authoritative for _outcomes_** (e.g., `token_ok`, `require_challenge`, `submission_id`, `cookie_present?`).
- **Helper contracts are authoritative for _behavior/returns_** (inputs, side-effects, idempotency, hit/miss/expired).
- **Narrative text must not contradict** matrices or helper contracts.
- If two sources conflict, the PR **must** update both and declare which is canonical in the PR description.
- The narrative preamble at [Normative vs. Non-normative](#sec-normative-note) defers to this section; update the hierarchy here and reference it elsewhere to avoid drift.

## 2) State Model (cookie/hidden/NCID)
- **Identifiers**: `token` (hidden), `eid[_ _slot{n}]` (cookie), `nc-…` (NCID).
- **Flags**: `token_ok`, `require_challenge`, `hard_fail`, `cookie_present?`, `is_ncid`, `soft_reasons[]`.
- **Rotation**: “no rotation before success” except the **explicit carve-outs**: NCID fallback & pre-verification challenge (cookie mode) may clear cookie + re-prime; the submission remains NCID-pinned.

## 3) CI/Verifier Rules (summary)
- Every “MUST/SHOULD/MAY” sentence **SHOULD** include a condition (`when|if|unless`) unless it’s a global invariant.
- Every helper contract in §7.1.x contains **Inputs / Side-effects / Returns** blocks.
- Changes in the **Security §7.1 matrices** require a corresponding change in at least one anchored narrative/helper section (and vice-versa).

## 4) Anchors used by the Verifier
- Matrices: `#sec-cookie-policy-matrix`, `#sec-cookie-lifecycle-matrix`, `#sec-cookie-ncid-summary`
- Helper sections: `#sec-hidden-mode`, `#sec-cookie-mode`, `#sec-ncid`
- Narrative hub: `#sec-submission-protection`

*(Non-normative) Tip: keep quotes short; prefer matrices for exact outcomes.*
