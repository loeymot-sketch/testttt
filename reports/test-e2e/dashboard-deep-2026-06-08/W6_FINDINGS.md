# Wave 6 — Communications — CONSOLIDATED (static + visual + verified)
**Verdict: YELLOW** (no P0/P1; nothing broken/unsafe in V1's current state — all comms channels OFF, no FCM creds, no SMS provider). **NO push/mail sent (attested).** Clone mutations: 1 message reply (DB-only) + 1 notification-alert toggle flipped & reverted (net zero). Operating tripwire intact.

## Coverage (5 pages, DEPTH CONTRACT)
messages ✅ (1-to-1 chat, reply DB-only) · push-notification ✅ (compose captured, NEVER sent) · subscribers ✅ (send-mail drawer captured, NEVER sent) · settings/notification ✅ (FCM creds empty) · settings/notification-alert ✅ (3 tabs, all toggles OFF).

## FINDINGS
- **[P2] Push send = "Enregistrer" fires live FCM, no confirm / no recipient-count** — `PushNotificationCreateComponent.vue:56`. Button labeled "Save" but dispatches a live synchronous push; Rôle+Utilisateur can both be blank with no warning → accidental-broadcast ergonomic risk. (Broadcast-scope on blank target NOT asserted — couldn't source-grep.)
- **[P2] Subscriber bulk-mail = "Enregistrer" → `Mail::bcc(entire base)`, no confirm / no count** — `SubscriberMailComponent.vue:29`. Same anti-pattern: a gérant could mass-mail believing they saved a draft.
- **[P3] Messages bubble timestamp 12h en-US** ("04:17 PM, 08-06-2026") on FR-only system.
- **[P3] Push list header anglicism "Notification pushs"** (FR: "notifications push" invariant).
- **[P3] Settings/Notification raw English label "Firebase Application Id"** among FR siblings.
- **[P3] Settings/Notification no FCM test-send control** (KNOWN; pairs with FirebaseService silent error-swallow W6-static P3).
- **[P3] Settings/Notification-Alert English labels + headings + default template TEXTS** (Mail/SMS/Push, DB `notification_alerts.name`/`*_message` stored English) — **latent** (all OFF, no provider → no customer impact in V1), but if enabled FR customers get English order mails/SMS.
- **[P3] Settings/Notification-Alert ON/OFF caption doesn't track toggle state** (a11y `[checked]` but sibling caption stays "OFF").

## IMPROVEMENT LIST (gérant lens)
1. Push → rename "Enregistrer"→"Envoyer la notification" + confirmation modal (audience + recipient count) before dispatch.
2. Subscribers → "Envoyer à N abonnés ?" confirmation modal with live count; rename Save→Envoyer.
3. Settings/Notification → "Tester la connexion Firebase" button (validate creds, surface errors).
4. Settings/Notification-Alert → ship FR default templates/labels; make ON/OFF caption reflect switch state.
5. Messages → FR 24h timestamp.

## Note
W6 agent recreated the gitignored `.env.e2e` (DB_DATABASE=foodking_e2e gate PASSED) + saw empty comms tables (clone never seeded messages/subscribers — empty states, not defects). Worktree `app/` stripped → agent limited static claims (correct §3ter posture).

Counts (W6): P0=0 · P1=0 · P2=2 · P3=6.
