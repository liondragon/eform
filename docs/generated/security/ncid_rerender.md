**Generated from `tools/spec_sources/security_data.yaml` — do not edit manually.**
<!-- BEGIN GENERATED: ncid-rerender-steps -->
- **Expire the minted cookie** — MUST delete `eforms_eid_{form_id}` via a `Set-Cookie` header whose Name/Path/SameSite/Secure/HttpOnly attributes match the minted cookie and whose `Max-Age=0` (or `Expires` is in the past) whenever NCID fallback or challenge rerenders fire, including the PRG redirect on challenge success.
- **Re-prime via `/eforms/prime`** — MUST embed `/eforms/prime?f={form_id}[&s={slot}]` on those rerenders so the persisted record is reissued before the next POST; the rerender itself MUST NOT emit a positive `Set-Cookie`.
- **Keep the NCID-pinned `submission_id`** — MUST continue using the existing NCID as `submission_id`; rerenders, verification templates, and challenge success responses MUST NOT mint or substitute another identifier mid-flow. This delete + re-prime cycle is an allowed carve-out and does not violate the “no rotation before success” rule.
- **Challenge verification handoff** — Challenge verification success MUST emit only the deletion header and rely on the PRG follow-up GET (the redirect target) to embed `/eforms/prime` and restore cookie presence before the next POST; verifiers MUST NOT mint or refresh the cookie directly.
<!-- END GENERATED: ncid-rerender-steps -->
