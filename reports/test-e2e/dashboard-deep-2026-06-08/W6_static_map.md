# Wave 6 — Static Code-Map (Communications) — LIVE branch
4 surfaces, 18 controls, 0 dead controls.

## 🔴 EXTERNAL NEVER-FIRE (only 2 — QA must NOT submit)
1. **PUSH SEND** — `PushNotificationCreateComponent.vue:56` → POST `/admin/push-notification` → `PushNotificationController@store:37` → `PushNotificationService.php:114-115` → `FirebaseService::sendNotification` (synchronous Guzzle POST to FCM, NOT queued).
2. **SUBSCRIBER SEND-MAIL** — `SubscriberMailComponent.vue:29` → POST `/admin/subscriber/send-email` → `SubscriberController@sendEmail:57` → `SubscriberService.php:116` `Mail::bcc(entire base)->send(SubscriberMail)` (synchronous, no ShouldQueue).

## Surfaces → endpoints
1. MESSAGES (`permission:messages`): list/show/**reply-send (DB-ONLY, safe — Message+MessageHistory create, no Mail/SMS/event)**/mark-read/destroy(route exists, no UI button). Dead PUT/PATCH update already removed (api.php:1132-1133).
2. PUSH-NOTIFICATION (`permission:push-notifications*`): list/compose/**SEND (EXTERNAL)**/show/delete/export.
3. SUBSCRIBERS (`permission:subscribers`): list/export/**send-mail (EXTERNAL)**/delete.
4. SETTINGS/NOTIFICATION + NOTIFICATION-ALERT (`permission:settings`): FCM creds save + channel toggles/templates save = config persistence only. **No test-send control exists.**

## FINDINGS
- [P2] Admin push compose fires FCM synchronously in-request (not queued) — `PushNotificationService.php:114-115` (per-token Guzzle loop inside store() request blocks response on large fan-out). Queued `SendFcmNotificationJob` exists but only order-listeners use it. Non-blocker V1, pattern inconsistency.
- [P3] `FirebaseService::sendNotification` swallows per-send errors silently — `FirebaseService.php:61-62` empty `catch(\Throwable){}` → failed delivery invisible, admin always sees success. Observability gap.

Counts: P0=0 P1=0 P2=1 P3=1. Dead controls: 0. EXTERNAL never-fire: 2 (pinned above).
