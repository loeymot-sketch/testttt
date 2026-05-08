# Delivery Platforms — Operations Guide

> Track 1 (Phases 1–4). Per-branch integration with Uber Eats, Deliveroo
> and Delicity. Inbound webhooks ingest external orders into FoodKing;
> outbound status pushes notify the platform when staff move the order
> through the kitchen.

---

## 1. Overview & Architecture

```
                    ┌──────────────────┐
   Uber Eats ─────► │ POST /api/...    │
   Deliveroo ─────► │ webhooks/        │ ── VerifyDeliverySignature ──► DeliveryWebhookController
   Delicity  ─────► │ delivery/        │     (HMAC + nonce + replay)        │
                    │ {platform}/{evt} │                                    ▼
                    └──────────────────┘                          DeliveryWebhookEvent (raw row)
                                                                            │
                                                                            ▼
                                                     ProcessDeliveryPlatformWebhookJob
                                                                            │
                                                                            ▼
                                                        DeliveryOrderIngestionService
                                                                            │
                                                                            ▼
                                                        FrontendOrder + OrderItem
                                                        + DeliveryPlatformExternalOrder
                                                        + AuditLog (chained)
                                                                            │
                                                                            ▼
                                                       OrderCreated  →  KDS, OSS, POS
```

The four moving parts you should know:

| Module | Path | Role |
|---|---|---|
| **`delivery_platforms` table** | `database/migrations/2026_05_08_010000_…` | per-branch config row, encrypted credentials |
| **Adapters** | `app/Services/Delivery/Adapters/` | platform-specific parsing + status push |
| **Ingestion service** | `app/Services/Delivery/DeliveryOrderIngestionService.php` | maps to FrontendOrder, allocates fiscal seq |
| **Admin UI** (Phase 4) | `resources/js/components/admin/deliveryPlatforms/` | configuration + health surface |

The model is multi-tenant: every row in `delivery_platforms` is bound to
a `branch_id`. Listing in the admin UI uses `withoutGlobalScopes()` so a
head-office Admin can manage every branch from one screen — but staff
sessions in the rest of the codebase rely on `BranchScope` to never see
another branch's adapter configuration.

---

## 2. Onboarding a new branch

> Goal: connect a new restaurant to one of the three platforms.
> Estimated time: ~15 min once the platform-side credentials exist.

1. **Create the database row.**
   The first deploy of Phase 1 created the schema; new branches need a
   row inserted via tinker, factory, or a one-shot seed:
   ```php
   DeliveryPlatform::create([
       'branch_id'         => $branch->id,
       'platform'          => 'uber_eats',
       'enabled'           => false,
       'external_store_id' => 'store_uuid_xxx',
       'credentials'       => [],
   ]);
   ```
   The `enabled=false` default ensures inbound webhooks are accepted
   only after the operator finishes the wizard below.

2. **Open the admin UI.**
   `/admin/delivery-platforms` (requires the `settings` permission).
   Pick the branch in the dropdown if you are a head-office Admin.

3. **Click *Edit* on the relevant row.**
   The modal shows:
   - the canonical webhook URL (read-only, copyable);
   - a `Store ID` text input;
   - three credential inputs (`client_id`, `client_secret`, `webhook_secret`);
   - a *Test signature* button;
   - a 24-hour traffic-light health summary.

4. **Paste the platform credentials.**
   - `client_id` + `client_secret`: the OAuth pair issued by the
     platform's developer portal.
   - `webhook_secret`: the HMAC shared secret you'll later paste into
     the platform dashboard.
   - Leave inputs **empty** to keep the existing secret. The server
     merges submitted keys onto the existing decrypted blob, so you can
     rotate one secret without touching the other two.

5. **Set the *Store ID*.**
   This is the platform-side identifier (Uber: store UUID, Deliveroo:
   restaurant id, Delicity: numeric id). The combination of
   `(platform, external_store_id)` is unique across all branches —
   the migration enforces this so a typo cannot route orders to the
   wrong restaurant.

6. **Toggle *Enabled* on and click *Save*.**
   The save flow:
   - validates the request via `UpdateDeliveryPlatformRequest`;
   - merges credentials onto the existing encrypted blob;
   - sets `webhook_secret_rotated_at` if you provided a new
     `webhook_secret`;
   - writes `delivery.platform.updated` to the audit chain.

7. **Click *Test signature*.**
   The endpoint computes an HMAC-SHA256 over a fixed sample payload
   with the just-saved `webhook_secret` and verifies the round-trip.
   This catches "I rotated the secret but forgot to copy it" mistakes
   before any real webhook arrives.

---

## 3. Webhook URL setup on the platform side

The canonical inbound URL is:

```
{APP_URL}/api/webhooks/delivery/{platform}/{event}
```

Where `{platform}` is one of `uber_eats`, `deliveroo`, `delicity`, and
`{event}` is whatever event name the platform sends (`order.created`,
`order.updated`, `order.cancelled`, …).

| Platform | Where to register | Notes |
|---|---|---|
| Uber Eats | Developer Dashboard → Webhooks → Endpoints | Sign with `X-Uber-Signature: v2,t=<ts>,v1=<hmac>` |
| Deliveroo | Partner Portal → Integrations → Webhooks | Header: `X-Deliveroo-Sequence-Guid` (replay nonce) |
| Delicity | Account → API → Webhooks | Header: `X-Delicity-Signature` |

Every platform has a slightly different signature scheme; see
`docs/SECURITY_WEBHOOKS.md` for the full HMAC contract per platform.

---

## 4. Credentials — encryption & rotation

Credentials are **encrypted at rest** via the Eloquent
`encrypted:json` cast on `DeliveryPlatform::credentials`. Direct DB
access only sees the ciphertext envelope; the cast is the sole
authorised reader/writer.

### Rotation procedure

1. Generate a new secret on the platform dashboard.
2. Open `/admin/delivery-platforms`, pick the row, click *Edit*.
3. Paste the new value into the relevant input. Leave others empty.
4. Click *Save*. The server:
   - merges the new value over the encrypted blob;
   - if you rotated `webhook_secret`, updates
     `webhook_secret_rotated_at` so the health surface tracks it;
   - writes `delivery.platform.updated` to the audit chain with
     a redacted diff (only the **key names** appear, never values).
5. Click *Test signature* to confirm the new value is valid.
6. Switch the platform side to the new secret.

### Reading the plaintext

The `reveal()` endpoint
(`POST /api/admin/delivery-platforms/{id}/reveal`) is the only way to
see plaintext credentials. It is gated by:
- the `settings` permission middleware;
- an explicit `Admin` Spatie role check inside the controller;
- a mandatory `delivery.platform.credentials_revealed` audit row
  emitted before the response — including the requester id and IP.

The admin UI does **not** expose this endpoint — it is reserved for
operational debugging via tinker / a future support tool.

---

## 5. Troubleshooting

### "Signature self-test failed"
The stored `webhook_secret` is missing or wrong. Re-paste the secret
from the platform dashboard and click *Save* + *Test signature*.

### "Webhook health is red despite recent orders"
- Open `/admin/delivery-platforms/{id}/health` — check the 24h
  counters. If `webhooks_received > 0` but `webhooks_processed == 0`,
  the queue worker is stuck. Run `php artisan queue:work`.
- If `webhooks_received == 0`, the platform never reached us. Check
  the platform's webhook delivery logs and the FoodKing access logs
  for any 401/403/422 responses.

### "Order ingested but missing items"
The adapter mapping rejected one or more line items. Check
`delivery_webhook_events.processing_error` for the row matching the
order, and `storage/logs/laravel.log` around the timestamp.

### "Branch X cannot edit platforms but Branch Y can"
The `permission:settings` middleware returns 403 for users without
the `settings` permission. Check the user's role assignment — only
Admin / Branch Manager / similar should have it.

---

## 6. FAQ

**Q. Can I configure two Uber Eats stores for one branch?**
No. The `(branch_id, platform)` unique index enforces one row per
branch×platform pair. If a branch operates two physical stores under
the same platform, model them as two FoodKing branches.

**Q. What happens to an order when I disable a platform?**
Inbound webhooks already received are processed normally — disabling
only affects new webhooks (the middleware refuses signature
verification when `enabled=false`). In-flight orders complete their
status push cycle with the credentials that were valid at ingestion.

**Q. Can a branch operator (non-Admin) edit credentials?**
Anyone with the `settings` permission can rotate credentials. Only
the `Admin` role can call the `reveal` endpoint to see plaintext.
Both actions are audit-logged.

**Q. What about staging vs production credentials?**
Each environment has its own DB, so each environment has its own
encrypted credentials. There is no shared store. The `app.url` config
drives the webhook URL the modal displays — keep your `.env` aligned
with the actual host.

**Q. How do I test the inbound flow end-to-end?**
See `tests/Feature/Delivery/UberEats_E2EBroadcastTest.php` for a
worked example using a fake signed payload. The same test factory
can drive the other two adapters.
