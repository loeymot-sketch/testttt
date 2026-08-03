# 🗺️ ULTRA PLAN — Système par système, décomposé (caisse · borne · web · app · sync)

> Structure COMPLÈTE du projet FoodKing V1 Le Cayenne, décomposée à la FONCTIONNALITÉ, avec surfaces,
> fichiers clés, intersections, points de synchro, et la barre de validation par système. Sert de :
> (a) référence d'installation/vérification pour le cowork, (b) carte d'audit pour le prochain agent Fable
> (à croiser avec `GOAL_FABLE5_ULTRA_V2_REVALIDATION_ABSOLUE_2026-07-02.md` pour la méthodo 10-angles).
> **Discipline transverse** : anti-doublage (une fonctionnalité = 1 implémentation) · frozen §7 · NF525 ·
> anti-ancienne-version (cf. `MISSION_COWORK_ANTI_ANCIENNE_VERSION_CACHE`). HEAD `61e9ea7b7`.

---

## SYSTÈME A — CAISSE (POS) · surface `/admin/pos-v4`
**Fichiers** : `PosComponent.vue`, `pos-wizard.js`(frozen)+`.css`(frozen), `admin-pos-v4.blade`(frozen),
`PaymentComponent.vue`(frozen), `ReceiptComponent.vue`, `PosCounterCollectModal.vue`, `OrderService::posOrderStore`.
**Fonctionnalités à valider** :
1. Prise de commande + wizard composition (multi-viandes, sauce, suppléments, menu) — prix SSOT backend.
2. Paiement **inline** (espèces rendu / carte TPE) → `payment_status=PAID` + fiscal alloué. `POS_WALKIN_ROUTE_TO_COUNTER=false`.
3. Ticket **client** imprimé (pont ESC/POS = écran) + ticket **cuisine** à la demande (bouton), **UN seul**.
4. File « à encaisser » = **BORNE uniquement** (pas les commandes caisse — cf. correctif 02-07).
**Intersections** : →KDS (à la création/release), →fiscal (à l'encaissement), →OSS.
**Points de vigilance** : PaymentComponent frozen (LOCK) ; ne pas re-router la caisse au comptoir.

## SYSTÈME B — BORNE (Kiosk) · surface `/kiosk/idle`
**Fichiers** : `KioskWizardComponent`(frozen), `KioskAppComponent`(frozen), `KioskIdleScreenComponent`,
`KioskConfirmationComponent`, `KioskAutoLoginGate`, `kioskPrinter.js`/`posLocalPrinter.js`.
**Fonctionnalités** :
1. Auto-login machine via `?machine_key=`==`KIOSK_AUTO_LOGIN_SECRET` + payload `KIOSK_MACHINE_USERNAME/PASSWORD`.
2. Attract → wizard → panier → upsell → paiement **Plan B (payer en caisse)** → `#A00xx`.
3. `payment_method=CASH_ON_DELIVERY` → `PENDING_COUNTER` + `COUNTER_DEFERRED` → visible KDS + file caisse.
4. Impression **client + cuisine** au pont ESC/POS à la confirmation (UN seul cuisine).
**Intersections** : →caisse (« à encaisser »), →KDS, →fiscal (à l'encaissement caisse).
**Config** : `KIOSK_PAYMENT_ROUTE_ALL_TO_COUNTER=true`, `KIOSK_DEFAULT_LOCALE=fr`.
**Vigilance** : écran blanc = bundle incomplet/SW ancien ; ticket illisible = pont injoignable→`--kiosk-printing`.

## SYSTÈME C — KDS (Écran cuisine) · surface `/admin/kitchen-display-system`
**Fichiers** : `KdsV2Grid`, `KdsOrderCard`, `kdsSymbolic.js`, `KitchenReleaseRule`, `KitchenTicketSymbolicFormatter`.
**Fonctionnalités** :
1. Board-release : `status∈{4,7,8}` ET `payment_status∈{5=PAID,15=PENDING_COUNTER}` ET branche du viewer.
2. Affichage **symbolique** `S/G | PRODUIT | viandes | STO | sauce` + suppléments + `MENU`, sans prix.
3. **Toutes** les commandes + tous les produits (hauteur dynamique). **Le board N'IMPRIME PAS** (caisse only).
4. Badge « à encaisser » (15) vs « réglé » (5).
**Faux mythe à bannir** : `kds_station` n'existe PAS (colonne absente d'`orders`). KDS vide = payment_status/branche/déploiement.

## SYSTÈME D — OSS (Écran client) · surface `order-status-screen`
Statut live de la commande (en préparation / prête). Sync via events/polling. Cohérence avec le KDS.

## SYSTÈME E — ENCAISSEMENT · surface `/admin/encaissement` + `PosCounterCollectModal`
**Fonctionnalités** : file borne (Plan B) ; modal unique (pavé numérique readonly = pas de clavier Windows) ;
espèces (rendu) + carte (montant tapé manuel enregistré compta) ; `confirmCounterPayment` → PAID + fiscal
+ **UN seul** cash_movement (fix V2 : plus de double sur différées) ; impression ticket client au pont.

## SYSTÈME F — FISCAL / NF525
`FiscalSequenceService` (séquence monotone gap-free/branche, allouée à l'encaissement), `ZReportService`
(clôture Z, ventilation carte/espèces via `orders.pos_payment_method`), chaîne HMAC `audit_logs`/`z_reports`,
cron clôture-Z. **Gate** : `fiscal:verify-chain --all` = CHAIN OK sur les 4 branches.

## SYSTÈME G — FIDÉLITÉ · `LoyaltyController` (public `register` / auth `check`)
`register` (public) = **création NOUVEAU compte uniquement** (pas de fuite PII compte existant — fix ;
pas d'attache email arbitraire — fix hijack V2) ; `check` (auth) ; OTP ; points ; redeem ; cohérence
points cross-surface. **Audit sous l'angle énumération/abus/PII sur TOUTES les branches.**

## SYSTÈME H — SITE WEB standalone · `/Users/1millnonstop/Downloads/web/` (React CDN, `api.js`)
Câblé à la caisse (guest-OTP, commandes réelles, images, promo, historique, fidélité). **e2e navigateur
complet** : parcours add→checkout→OTP→commande en caisse ; images = serveur caisse (pas génériques) ;
prix SSOT. Mirror data caisse.

## SYSTÈME I — APPLICATION MOBILE RN · `mobile/`
STANDALONE (pas de wireup API V1 sauf demande owner). Parité data/menu. État réel à cartographier.

## SYSTÈME J — UBER EATS (fondation, INACTIVE) · `UberWebhookController/Client/Mapper`
Go-live gates (à traiter quand Production Access accordé) : mapping menu (`uber_menu_map.php` à remplir),
webhook (signature HMAC + dédup `transaction_id` + 503-retry — faits), monitoring `webhook_events failed`,
index UNIQUE transaction_id, deny/store-status à câbler.

## SYSTÈME K — SYNCHRONISATION (le liant — angle mort structurel)
- **Chaîne** : borne/caisse → **DomainEvents → queue `high`** → **Echo/Soketi** (temps-réel) → KDS/OSS/caisse.
  Dégradation propre : si Echo absent → **polling** (garde anti-« undefined »). **Le worker DOIT tourner
  `queue:work --queue=high,default`** (sinon la queue `high` s'empile → sync en polling lent).
- **Data d'affichage cohérente cross-surface** : ticket == écran (client ET cuisine) ; KDS == cuisine ==
  OSS ; « à encaisser » == réalité ; pas de doublage (1 renderer ESC/POS, 1 chemin d'impression, 1 modal
  d'encaissement, 1 calcul prix SSOT).

---

## MÉTHODE DE VALIDATION (par système, cf. Fable v2 §4 — 10 angles)
Pour CHAQUE système A→K : décomposer → auditer (finders parallèles par angle) → adversaire refute-by-default
→ corriger scope-minimal frozen-safe **sans doublage** → **test-e2e réel** (capture analysée technique+UX)
→ re-test en boucle jusqu'à **≥2 cycles verts** (jusqu'à 10 pour le critique : paiement/fiscal/prix/sync/sécurité).
Un système n'est « validé 1000 % » que si TOUS ses angles sont verts + repro stable + frozen-diff 0 + NF525 OK.

## PRÉ-REQUIS MACHINE (avant tout test terrain)
Appliquer `MISSION_COWORK_ANTI_ANCIENNE_VERSION_CACHE_2026-07-02.md` : VPS reset+rebuild, machines
purge SW+cache, un seul Startup, un seul pont. Sinon on teste une ANCIENNE version → faux résultats.

## LIVRABLES
Par système : structure (file:line) + décomposition fonctionnelle + statut de validation (angles/passes) +
findings (survivors/réfutés) + fix-log + registre anti-doublage + captures analysées + verdict GO/NO-GO.
Global : carte de synchro + verdict projet. BRAIN + mémoire à jour à chaque convergence.
