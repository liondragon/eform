**Generated from `tools/spec_sources/security_data.yaml` — do not edit manually.**
<!-- BEGIN GENERATED: cookie-header-actions -->
| Flow trigger | Header action | Invariants |
|--------------|---------------|------------|
| <a id="sec-cookie-header-get-render"></a>GET render | `skip` | Renderer MUST NOT emit `Set-Cookie`; embed `/eforms/prime?f={form_id}[&s={slot}]` so `/eforms/prime` alone mints or refreshes the cookie before POST. |
| <a id="sec-cookie-header-prime"></a>`/eforms/prime` request | `positive` | Only flow permitted to emit a positive `Set-Cookie` for `eforms_eid_{form_id}`; send it when minting or when no unexpired match was presented, and skip it when an identical unexpired cookie arrived. |
| <a id="sec-cookie-header-post-rerender"></a>POST rerender (NCID or challenge) | `deletion` | Error rerenders governed by the NCID rerender and challenge lifecycle MUST delete `eforms_eid_{form_id}` with a matching Max-Age=0 header and embed `/eforms/prime` on the follow-up GET; the rerender itself MUST NOT emit a positive header. |
| <a id="sec-cookie-header-challenge-success"></a>Challenge verification success | `deletion` | Verifier success response clears the cookie via deletion header and relies on the PRG redirect GET to embed `/eforms/prime`; NCID stays pinned per the rerender lifecycle. |
| <a id="sec-cookie-header-prg-redirect"></a>PRG redirect (success handoff) | `deletion` | All success PRG responses MUST delete `eforms_eid_{form_id}` before issuing the 303 so the follow-up GET reprovisions the cookie; flows covered by the NCID rerender and challenge lifecycle rely on that redirect GET to re-prime, and no positive header is permitted in PRG. |
<!-- END GENERATED: cookie-header-actions -->
