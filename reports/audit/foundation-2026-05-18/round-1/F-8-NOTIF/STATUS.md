# F-8 NOTIFICATIONS — STATUS.md (read-only audit, round 1)

**Zone**: Push (FCM legacy + FCM HTTP v1) + SMS (7 gateways, default Twilio) + Mail (5 Mailables, Laravel mail) + In-app (PushNotification model)
**Method**: 1 master sub-agent self-orchestrating 3 specialist lenses (Architect / SRE / RED-team). Each lens Read-cited. No code mutation.
**Wall-clock**: ~25 min
**Date**: 2026-05-18
**Branch**: v1-0-1-hardening-2026-05-17

---

## Headline (top 4 findings to fix BEFORE V1 SaaS scale-out)

1. **F-8-RED-001 (P0)** — PushNotificationService::store cross-branch leak. Admin push with role_id=0 user_id=0 fans out across ALL branches' device tokens. branch_id stored on the PushNotification record but never used in the recipient query. Tenant-isolation breach. File: `app/Services/PushNotificationService.php:71-90`.

2. **F-8-ARCH-001 (P0)** — Two coexisting FCM client implementations. `FirebaseService` (HTTP v1, OAuth2 service-account, DB-stored creds) AND `FcmNotificationService` (legacy server key, env var, Google-deprecated endpoint `https://fcm.googleapis.com/fcm/send`). Both fire on the same status change. One must die; both currently silent-fail under common misconfigs.

3. **F-8-ARCH-002 (P0)** — Zero notification listeners implement ShouldQueue. With QUEUE_CONNECTION=sync (config default), `OrderService::store` blocks the HTTP thread on 6 sequential dispatches × N recipients (Twilio + SMTP + FCM I/O). PaymentConfirmFcmFailureTest documents one collapse mode; the rest were never hardened.

4. **F-8-ARCH-003 (P0)** — Zero idempotency in notification layer. Duplicate events re-send Mail+SMS+Push. CleanupStalePendingKioskOrders race + outbox-replay race (F-8-RED-005) both reproduce duplicate customer messaging.

## P1 cluster (4)
- F-8-RED-002 — SMS-bomb vector via IP rotation (throttle is per-IP only, not per-phone)
- F-8-RED-003 — Predictable FCM topic `customer_order_{orderId}` enables third-party subscription / order-status PII leak
- F-8-RED-004 — Driver-bound push body may interpolate customer PII (template-injection surface, unverified — read NotificationAlert seeder to confirm)
- F-8-SRE-001 — Failures swallowed via `Log::info` (24 sites). Production with LOG_LEVEL=warning loses every notification failure event.

## Cleanup / dead code
- F-8-ARCH-005 — `app/Listeners/SendEmailVerificationNotification.php` + `app/Events/SendEmailVerification.php` are dead (shadowed by Illuminate import at EventServiceProvider.php:80; grep proved no other reference).
- F-8-ARCH-007 — 5 Mailables ↔ 5 templates, 1:1. **Zero orphan templates** (mandate satisfied).

---

## 4-list synthesis

### LIST A — Duplications (functional)
| # | What | Files | Severity |
|---|---|---|---|
| A1 | Two FCM client implementations | `app/Services/FirebaseService.php` + `app/Services/FcmNotificationService.php` | P0 |
| A2 | Dual FCM firing on status change (legacy track via Order*PushNotificationBuilder + new track via SendFcmOn*) | `app/Services/OrderService.php:1637-1643` ; `app/Providers/EventServiceProvider.php:138-143` | P1 |
| A3 | 9 NotificationBuilder services × ~81 status methods, near-identical | `app/Services/Order{Mail,Push,Sms}NotificationBuilder.php` ; `OrderGot{Mail,Push,Sms}NotificationBuilder.php` ; `OrderDeliveryBoy{Mail,Push,Sms}NotificationBuilder.php` | P1 |
| A4 | Each builder repeats: NotificationAlert::where(['language' => '...'])->first() + SwitchBox::ON check + per-channel send — no shared trait/strategy | as above (~700 LOC of duplication) | P1 |

### LIST B — Dead / orphan
| # | What | Files | Action |
|---|---|---|---|
| B1 | `App\Listeners\SendEmailVerificationNotification` + `App\Events\SendEmailVerification` | `app/Listeners/SendEmailVerificationNotification.php` ; `app/Events/SendEmailVerification.php` | Delete (or wire explicitly) |
| B2 | `NotifyStockLowOnStockLevelChanged` documented as "V1 = log only" — no actual notification emission | `app/Listeners/NotifyStockLowOnStockLevelChanged.php:67-68` | Either implement or rename / decommission |
| B3 | `FcmNotificationService` becomes dead if F-8-ARCH-001 consolidates to FirebaseService (or vice-versa) | `app/Services/FcmNotificationService.php` | Choose one path, delete the other |
| B4 | Orphan mail templates: **NONE** (5/5 Mailables map to templates) | — | mandate satisfied |

### LIST C — Scope leakage / multi-tenant
| # | What | Files | Severity |
|---|---|---|---|
| C1 | PushNotificationService::store admin/role fan-out ignores branch_id | `app/Services/PushNotificationService.php:71-90` | P0 |
| C2 | OrderGot* and OrderDeliveryBoy* push/mail/sms builders are correctly branch-scoped via $order->branch_id | `OrderGotPushNotificationBuilder.php:32-46` etc. | OK |
| C3 | Notification PushNotification model has BranchScope on READ side only; SEND side bypasses | `app/Models/PushNotification.php:28-32` ; `app/Services/PushNotificationService.php` | P0 (C1) |
| C4 | FCM topic `customer_order_{orderId}` predictable across tenants | `SendFcmOnOrderCreated.php:91` ; `SendFcmOnOrderStatusChange.php:114-128` | P1 (RED-003) |

### LIST D — Cross-system contracts
| # | What | Files | Severity |
|---|---|---|---|
| D1 | OrderCreated / OrderStatusChanged → BOTH outbox listener AND in-process notification listeners; listener-order documented but no test asserts it (F-002 round-3) | `app/Providers/EventServiceProvider.php:128-152` | P1 |
| D2 | SendOrderMail/Sms/Push event-vs-listener pairs are NOT ShouldQueue; SendFcmNotificationJob IS ShouldQueue + onQueue('notifications'). Asymmetry. | `app/Jobs/SendFcmNotificationJob.php:67` vs `app/Listeners/SendOrder*.php` | P0 (ARCH-002) |
| D3 | DispatchDomainEventsJob broadcasts via Pusher; SendFcmNotificationJob sends via FCM. Two parallel "push" stacks for "customer notification". Outbox is the sync SSOT (KDS/OSS/POS), FCM is the mobile push surface — boundary clear but the docs do not state it explicitly. | `DispatchDomainEventsJob.php:100-132` ; `SendFcmNotificationJob.php` | P2 doc |
| D4 | V1.0.1 BRAIN backlog "6 listeners idempotency restantes (Catalog/Coupon/Availability×3/Table)" refers to Persist*ToOutbox (sister F-1/F-2), **NOT** the Send*Notification listeners audited here. The notification-listener idempotency gap is currently untracked in any BRAIN backlog entry. | `PROJECT_BRAIN.md:26` | meta — surface to orchestrator |

---

## KPIs (aggregated)
| Metric | Value |
|---|---|
| Notification event classes | 11 (`SendOrderMail`, `SendOrderSms`, `SendOrderPush`, `SendOrderGotMail`, `SendOrderGotSms`, `SendOrderGotPush`, `SendOrderDeliveryBoyMail`, `SendOrderDeliveryBoySms`, `SendOrderDeliveryBoyPush`, `SendResetPassword`, `SendSmsCode`) + 2 outbox-side (OrderCreated, OrderStatusChanged) that also fan out to FCM |
| Notification listener classes | 12 (mapped in EventServiceProvider) + 1 dead (`app/Listeners/SendEmailVerificationNotification`) |
| Listeners implementing ShouldQueue | 0 / 12 |
| Listeners with idempotency guard | 0 / 12 |
| FCM client implementations | 2 (`FirebaseService` HTTP v1 + `FcmNotificationService` legacy) |
| Mailable classes | 5 (OrderMail, OrderGotMail, ResetPassword, SubscriberMail, VerifyEmail) |
| Mail blade templates | 5 (1:1 mapping, **zero orphans**) |
| SMS gateway implementations | 7 (Bulksms, Twofactor, Bulksmsbd, Telesign, Nexmo, Clickatell, Twilio, Msg91) |
| Default SMS gateway | Twilio (DB-overridable) |
| OTP throttle | per-IP `throttle:5,1` (NOT per-phone — F-8-RED-002) |
| Notification dead-letter table | 0 (none) |
| Notification observability hook | 0 (SyncMetricsRecorder exists but never called from notification track) |
| Failure-swallow sites (Log::info on exception) | 24 |

---

## Owner-friendly questions (clear, prioritized)

1. **FCM strategy decision** — keep HTTP v1 (OAuth2 + service-account JSON) or migrate to FCM HTTP v1 with config-only credentials? The current legacy-server-key path is deprecated; the DB-stored service-account path is operationally fragile. Pick one canonical client; we delete the other. **(Blocks ARCH-001, ARCH-006.)**

2. **Cross-branch admin broadcast intent** — should `branch_id=0` admin push notifications reach all customers across all branches (current behavior, leak), OR be branch-scoped (Branch A admin → Branch A customers only)? If SaaS multi-tenant V1.0.2 is the goal, only the latter is acceptable. **(Blocks RED-001.)**

3. **Notification queue strategy** — agree to convert all 12 `Send*Notification` listeners to ShouldQueue + onQueue('notifications')? Implication: requires a queue worker for `notifications` to exist in Supervisor / Ansible (per Wave 5G `deploy/ansible/site.yml`). **(Blocks ARCH-002, SRE-002, SRE-005.)**

4. **Idempotency granularity** — agree to add per-(order_id, status, channel) Cache-based guard with 5-min TTL? Side-effect: legitimate manual re-emits within the window are no-ops (acceptable for V1). **(Blocks ARCH-003, RED-005.)**

5. **Driver PII policy** — confirm whether `delivery_boy_after_assign_message` template (NotificationAlert) is allowed to include customer phone / address placeholders. If yes, GDPR exposure on driver lockscreen needs explicit Art. 32 justification + a "driver-safe placeholder allowlist". **(Blocks RED-004.)**

6. **Per-phone OTP rate limit** — agree to add `RateLimiter::attempt("otp:phone:{$phone}", 3, ..., 60)` inside OtpManagerService? Side-effect: legitimate users who request OTP 4+ times in 60s get 429. **(Blocks RED-002.)**

7. **NotifyStockLowOnStockLevelChanged TODO** — promote from Log-only to actual mail/push, OR formally deprecate the listener? Currently dead behavior with documented intent. **(Cleanup.)**

---

## NF525 / frozen-zone touched
0 frozen-zone files touched (read-only). No NF525 fiscal listener is in F-8 scope (Z*-Report / FiscalSequence / AuditLog services live in F-1).

## Files for the executor (Wave 2 implementer)
- `app/Services/PushNotificationService.php` (C1 fix — add branch_id filter)
- `app/Services/FcmNotificationService.php` + `app/Services/FirebaseService.php` (ARCH-001 consolidation — pick one)
- `app/Listeners/SendOrder{Mail,Sms,Push}Notification.php` ×3 + `SendOrderGot*` ×3 + `SendOrderDeliveryBoy*` ×3 (ARCH-002 + ARCH-003)
- `app/Services/OtpManagerService.php:50-60` (RED-002 per-phone limiter)
- `app/Listeners/SendEmailVerificationNotification.php` + `app/Events/SendEmailVerification.php` (delete)
- `app/Listeners/SendFcmOnOrder*.php` (RED-003 topic naming + RED-004 PII review)
- Long-term V1.0.2: replace 9 NotificationBuilder services with NotificationDispatcher strategy (ARCH-004)

## Specialist JSONs
- `architect.json` — 7 findings (3×P0, 3×P1, 1×P2)
- `sre.json` — 7 findings (3×P0, 3×P1, 1×P2)
- `red-team.json` — 7 findings (1×P0, 4×P1, 2×P2)

Aggregate: **7 P0 + 10 P1 + 4 P2** (uniqued across specialists; some overlap by design as each lens re-frames the same root cause).
