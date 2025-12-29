# CRM Integration — Proposed Changes

**Status:** DRAFT — Laying groundwork only; CRM implementation deferred.

---

## 1. Design Principles

| Principle | Rationale |
|-----------|-----------|
| **Canonical payload as the seam** | One `SubmissionPayload` object produced after normalize/validate/coerce; all downstream sinks (email today, CRM later) consume exactly that object. |
| **Separate delivery identity** | `delivery_id` (per-token correlation) ≠ `submission_id` (token, burned on ledger reservation). True CRM idempotency across retries requires additional design (deferred). |
| **Primary sink semantics** | For `both` mode: CRM = primary, email = secondary/best-effort. Success = "primary sink delivered." |
| **No authenticated download subsystem** | CRM receives structured payload + attachments via API. Avoid "send links" pattern. |
| **Capability-gated** | CRM delivery off by default; opt-in per form via `delivery.mode`. |

---

## 2. New Concepts

### 2.1 `delivery_id`

- **What:** UUIDv4 generated at token mint time (both hidden-mode and `/eforms/mint`).
- **Where persisted:** Token record (`tokens/{h2}/{sha256(token)}.json`).
- **Purpose:** Log/audit correlation, submission tracing.
- **Contract:** Each token mint generates a fresh `delivery_id`. It is NOT stable across remints (e.g., email-failure recovery generates a new token → new `delivery_id`).
- **Future:** A stable idempotency key for CRM deduplication across retries requires additional design (e.g., server-issued remint grants or unifying recovery to server-mint). Deferred.

Token record shape (updated):
```json
{
  "mode": "hidden|js",
  "form_id": "contact",
  "instance_id": "...",
  "issued_at": 1703800000,
  "expires": 1703886400,
  "delivery_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

### 2.2 `SubmissionPayload`

Internal object produced after normalize/validate/coerce succeeds:

```php
readonly class SubmissionPayload {
    public string $form_id;
    public string $template_version;
    public string $request_id;        // correlation from Logging
    public string $delivery_id;       // from token record
    public string $submission_id;     // = posted eforms_token
    public array  $canonical_values;  // field_key => canonical value
    public array  $upload_metadata;   // [ {path, bytes, mime, sha256, original_name_safe}, ... ]
    public array  $meta;              // { submitted_at, ip_processed, origin_state }
}
```

- **Immutable** after construction.
- **Not serialized** to client; internal only.
- **Consumed by:** `Delivery::deliver()`, logging, the new WP action hook.

### 2.3 `Delivery` Interface

```php
interface DeliverySink {
    public function deliver(SubmissionPayload $payload): DeliveryResult;
}

readonly class DeliveryResult {
    public bool    $delivered;
    public string  $sink;           // "email" | "crm"
    public ?string $error_code;     // EFORMS_ERR_* on failure
    public ?string $secondary_sink; // for "both" mode
    public ?bool   $secondary_ok;
}
```

Today only `EmailSink` exists. `CrmSink` added later behind the same interface.

### 2.4 Delivery Modes (per-form, future)

| Mode | Behavior |
|------|----------|
| `email` (default) | Email only; current behavior. |
| `crm` | CRM only; email skipped. |
| `both` | CRM primary, email secondary/best-effort. Log email failures; don't retry or surface errors. |

**Not implemented yet** — template schema unchanged until CRM lands.

---

## 3. Immediate Changes (Non-Breaking)

These can be implemented now to prepare the seam:

### 3.1 Token Record: Add `delivery_id`

Mint helpers (`Security::mint_hidden_record()`, `/eforms/mint` handler) generate and persist `delivery_id`.

### 3.2 Define `SubmissionPayload` Class

Internal DTO; no public API exposure.

### 3.3 Funnel Post-Validation Through `Delivery::deliver()`

Refactor `SubmitHandler`:
```php
// After normalize/validate/coerce succeeds, before ledger reservation:
$payload = SubmissionPayload::fromValidated($ctx, $canonical, $uploads, $token_record);

// After ledger reservation succeeds:
$result = Delivery::deliver($payload); // EmailSink only, for now
```

### 3.4 Add WP Action Hook

```php
// Fired read-only before Delivery::deliver()
do_action('eforms_submission_payload', $payload);
```

Allows experimentation (e.g., logging to external service, prototyping CRM mapping) without touching core.

---

## 4. Future Changes (When CRM Lands)

### 4.1 Template Schema Extension

```json
{
  "delivery": {
    "mode": "email|crm|both",
    "crm": {
      "provider": "hubspot|salesforce|webhook",
      "form_guid": "...",
      "field_mapping": { "email": "hs_email", "name": "firstname" }
    }
  }
}
```

### 4.2 Config Keys

```php
'delivery.crm.enabled' => false,       // global kill switch
'delivery.crm.timeout_seconds' => 10,
'delivery.crm.max_retries' => 2,
```

### 4.3 `CrmSink` Implementation

- POST structured JSON to CRM API with `Idempotency-Key: {delivery_id}`.
- Attach files via CRM's file upload endpoint (provider-specific).
- Map `SubmissionPayload` fields to CRM schema per `field_mapping`.

### 4.4 Error Codes

| Code | Meaning |
|------|---------|
| `EFORMS_ERR_DELIVERY_PRIMARY` | Primary sink (CRM) failed; submission not delivered. |
| `EFORMS_ERR_DELIVERY_SECONDARY` | Secondary sink (email in `both` mode) failed; logged only. |

---

## 5. What NOT to Do Now

| Avoid | Reason |
|-------|--------|
| Adding CRM config keys | No implementation to use them. |
| Refactoring email retry for CRM | CRM has different retry semantics (external API backoff vs instant rerender). |
| Building plugin architecture | One interface + one hook is enough. |
| "Send links" pattern | Requires authenticated download subsystem; out of scope. |

---

## 6. Spec Section (Draft)

Add to `Canonical_Spec.md` under a new section **30. DELIVERY ABSTRACTION** (or append to existing sections):

> ### 30.1 SubmissionPayload (internal)
> After normalize/validate/coerce succeeds, `SubmitHandler` constructs an immutable `SubmissionPayload` containing `{ form_id, template_version, request_id, delivery_id, submission_id, canonical_values, upload_metadata, meta }`. All downstream delivery sinks consume this object.
>
> ### 30.2 delivery_id
> A UUIDv4 generated at token mint time and persisted in the token record. Used for log/audit correlation and submission tracing. Not exposed to clients. A stable idempotency key for CRM deduplication across retries requires additional design (deferred).
>
> ### 30.3 Delivery::deliver()
> Single internal boundary for post-validation side effects. Returns `DeliveryResult { delivered, sink, error_code?, secondary_sink?, secondary_ok? }`. Email is the only sink in v1.
>
> ### 30.4 Extension hook
> `do_action('eforms_submission_payload', SubmissionPayload $payload)` fires read-only immediately before `Delivery::deliver()`. Listeners MUST NOT mutate the payload or perform write operations that affect submission success.

---

## 7. Implementation Checklist

- [ ] Add `delivery_id` to token record schema
- [ ] Update `Security::mint_hidden_record()` to generate `delivery_id`
- [ ] Update `/eforms/mint` handler to generate `delivery_id`
- [ ] Create `src/Delivery/SubmissionPayload.php`
- [ ] Create `src/Delivery/DeliveryResult.php`
- [ ] Create `src/Delivery/DeliverySink.php` interface
- [ ] Create `src/Delivery/EmailSink.php` (wrap existing Emailer logic)
- [ ] Refactor `SubmitHandler` to build `SubmissionPayload` and call `Delivery::deliver()`
- [ ] Add `eforms_submission_payload` action hook
- [ ] Update spec with delivery abstraction section
- [ ] Add `delivery_id` to JSONL log schema
