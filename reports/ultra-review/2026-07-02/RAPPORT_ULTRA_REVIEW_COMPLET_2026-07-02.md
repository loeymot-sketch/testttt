# RAPPORT ULTRA-REVIEW COMPLET — FoodKing V1 « Le Cayenne »
**Date** : 2026-07-02 · **HEAD** `594eb92f5` (branche `pos/category-first-caisse-2026-06-23`) +
working-tree modifié (audité AS-IS) · **Mission** : /goal « tout comprendre + ultra review technique
+ test-e2e de chaque système + code/sync/security/UI-UX review avec agents adversaires ».

**Méthode** : 6 vagues. W1 compréhension (11 lecteurs + critic adversaire). W2/W3/W4 = 6 finders qui
**vérifient** chaque risque (file:line + repro) puis **1 adversaire refute-by-default par finding**.
W5 = test-e2e réel navigateur (8 surfaces capturées + analysées) + preuve DB. Total **46 agents**,
0 erreur, ~3,3M tokens. Discipline anti-hallucination (verify-before-report) + garde-fous
faux-positifs. Aucune modification du code produit (mission review). NF525/frozen intouchés.

---

## 1. VERDICT GLOBAL

**Le cœur du système est SAIN et cohérent.** Le flux principal prise-de-commande → cuisine → suivi
client → encaissement → fiscal fonctionne end-to-end, prouvé LIVE. **0 finding P0, 0 finding P1.**
1 seul P2 (fuite PII fidélité mineure, calibrée V1 LOCAL), 21 P3 (durcissement / dette / doc).
6 findings **réfutés** par l'adversaire (dont 3 zones que le critic soupçonnait). NF525 CHAIN OK
sur les 4 branches, avant ET après une vente réelle encaissée.

**Recommandation** : **GO V1 LOCAL** dans l'enveloppe connue (mono-poste, FR, TPE simulé assumé,
no-cloud). Le seul blocage réel = **déploiement** (le code actuel n'est pas sur le VPS — c'est la
cause de tous les « bugs terrain » signalés), pas la qualité du code.

---

## 2. STRUCTURE (compréhension max — détail dans `01-STRUCTURE.md`)

Plateforme **Laravel 9 + Vue 2 (Mix)**, 5 systèmes + cœur partagé :
1. **BORNE (kiosk)** — SPA machine-auth (Sanctum `kiosk:order`), wizard compo, preview SSOT,
   Plan B (paiement routé caisse), file offline IndexedDB.
2. **CAISSE (POS)** — wizard Vanilla frozen + `PaymentComponent` V5 frozen ;
   `walkin_route_to_counter=true` → toute commande walk-in différée à l'encaissement (fiscal gap-free).
3. **KDS + OSS** — écran cuisine symbolique (filtre status+payment, pas kds_station) + suivi client.
4. **WEB+APP** — storefront client (désactivé côté client), standalone web/mobile hors repo.
5. **CENTRAL** — ~100 contrôleurs (dashboard, catalogue 59 articles/13 catégories, settings, users,
   rapports, Fiscal Z/X lecture).
**Cœur partagé** : `PricingService` (SSOT), `OrderStateMachine`, chaîne Fiscal NF525
(FiscalSequence/ZReport/AuditLog HMAC append-only), bus sync (events plain → outbox `domain_events` →
`DispatchDomainEventsJob` queue **`high`** → soketi, canal `private-branch.{id}`).

Le critic a relevé **10 zones** initialement hors-carte (scheduler/cron dont clôture Z auto, legacy
`/install` & `/payment`, table QR, pipeline Uber, loyalty, composer profiles, observability, exports
Excel, delivery-boy, `/api/health`) → toutes intégrées aux vagues et vérifiées ci-dessous.

---

## 3. TEST-E2E RÉEL — flux complet prouvé LIVE (détail `05-e2e-live/`)

Commande caisse **#5398 / A0001** (Tacos L, 2 viandes Cordon Bleu + Poulet mariné, sauce Blanche)
suivie sur TOUTES les surfaces (8 captures analysées) :

| Étape | Preuve | ✓ |
|---|---|---|
| CAISSE création | wizard 2/2 viandes → `POST /pos/quote 200` (re-price SSOT) → `POST /pos 201` ; composition_snapshot exact (len 579) | ✅ |
| KDS cuisine | `N°A0001 CAISSE — 1× G\|TACOS\|L\|Cordon P\|BL` + « EN ATTENTE ENCAISSEMENT » | ✅ |
| OSS suivi client | `N°A0001` colonne « En préparation » | ✅ |
| Encaissement | file `Caisse N°A0001 — 7,90 €` → modal Espèce (reçu 7,90) → Confirmer | ✅ |
| Fiscal NF525 | post-encaissement : `PAID`, `pos_pm=CASH`, **`fiscal_sequence_no=2589`** (était NULL), audit_logs +7 | ✅ |
| Chaîne NF525 | `fiscal:verify-chain --all` = **CHAIN OK 4 branches** avant ET après | ✅ |

**Conclusions e2e** : sync cross-surface confirmée ; multi-viandes traversé sans perte (le bug
historique « Viande 2 » est ABSENT) ; **NF525 gap-free by design vérifié** (séquence allouée
uniquement à l'encaissement, jamais à la création différée = owner model B). Console 0 erreur sur
dashboard/POS/OSS/encaissement/catalogue. UI/UX : FR complet, branding Cayenne, a11y correcte
(pavé labellisé), labels « simulation » honnêtes, empty-states cohérents.

**Non couvert live** (raisons explicites) : commande borne complète = navigateur non-provisionné
(3× 401 attendus, la vraie borne injecte un token machine) ; impression physique + pont ESC/POS =
owner-only matériel. Ces flux sont couverts par la carte W1 + les sessions passées.

---

## 4. FINDINGS VÉRIFIÉS (22 : 1 P2 + 21 P3 · 0 P0 · 0 P1) — détail `verify-findings.json`

### P2 — le seul (indépendamment reproduit live)
**SEC-01 · Divulgation cross-compte non-authentifiée via `/loyalty/register`**
`app/Http/Controllers/Frontend/LoyaltyController.php:131-143`. La route
`POST /api/frontend/loyalty/register` (routes/api.php:1435) est publique (`throttle:5,1`, pas
d'`auth:sanctum` ; la clé `apiKey` est embarquée au JS = publique). Si l'`email` fourni appartient à
un autre compte, réponse **409** exposant `existing_loyalty_code` + `existing_phone` du titulaire.
**Reproduit live** : POST avec l'email d'une victime → `{"code":"EMAIL_EXISTS", "existing_loyalty_code":"00597EDE","existing_phone":"0699887702"}`.
→ email→(téléphone+loyalty_code) sans auth. Downgradé P1→P2 (cible connue requise, throttlé,
loyalty_code seul non exploitable — check/redeem exigent `auth:sanctum`).
**Fix scope-minimal** : retirer `existing_loyalty_code`/`existing_phone` du corps 409 (message
générique). Optionnel : gater `/register` derrière `abilities:kiosk:order`.

### P3 — 21 (durcissement / dette / doc — non-bloquants V1 LOCAL)
**Sécurité / authz (défense-en-profondeur)** :
- Lecture settings `index` non gatée `permission:settings` (Company/Site/OrderSetup/Theme/Notification
  `:18-19`) → config lisible par tout staff authentifié (Notification.index expose des champs FCM
  cloud, inutilisés en V1 no-cloud).
- `OrderHistoryController` sans middleware constructeur (gardes `abort_unless` inline) → régression
  latente si méthode future ajoutée sans recopier la garde.
- Sidebar `userHasPermissionUrl()` fail-open (`BackendMenuComponent.vue:235-248`) — visibilité menu
  seulement, backend reste la vraie garde.
- `catch → $exception->getMessage()` brut renvoyé (~418 occurrences) → fuite possible de messages
  internes/SQL. Pattern global.
- CORS `supports_credentials=true` + pattern loopback any-port sans garde d'env (`config/cors.php`).
- `ApiKeyMiddleware` compare `===` (non timing-safe) une clé publique + 400 au lieu de 401.
- `soketi.json` committe des secrets placeholder réutilisés en `.env` local (localhost only).
- `GuestSignupController::verify` : si `site_phone_verification=DISABLE`, `register()` sans vérifier
  l'OTP (dépend d'un setting runtime).

**Fiscal / argent (chaîne intacte, détails de reporting)** :
- Z-report `by_terminal`/`net_after_fees` omet le CA mono-mode + encaissement borne
  (`ZReportCashEnrichmentService`) — ventilation par terminal incomplète ; **le total CA reste juste**
  et la chaîne OK → downgradé P3.
- Montant CARTE saisi au counter-collect (commit `594eb92f5`) non persisté en colonne structurée
  (n'atteint qu'`audit_logs.payload`) → écart pour la ventilation compta carte.

**Sync / doc** :
- `SYNC_CONTRACT.md` périmé ×2 : OSS mur public poll **5s réel** (doc dit 60s) ; fallback KDS **5s**
  (doc dit ~30s) + bandeau.
- Docblock `OutboxBroadcastSwallowedEvent` dit « intentionally unwired » alors qu'il EST câblé
  (`EventServiceProvider.php:327`).
- **2 runbooks déploiement** lancent `queue:work` sans `--queue=high` → les broadcasts (queue `high`)
  ne partent jamais → temps-réel dégradé en poll (confirmé live : backlog 354 au démarrage). **Prod
  DOIT lancer `queue:work --queue=high,default`** (ou corriger supervisor/runbooks).

**Code-quality / robustesse** :
- Uber `uber_menu_map.php` vide → `resolveItemId` retombe sur `LIKE '%titre%'` → mauvais item_id
  possible (Production Access Uber en attente → latent).
- Webhook Uber : échec terminal acquitté **200** sans rejeu (le commentaire « Uber rejouera » est faux ;
  un lane cron retry-failed existe → downgradé P3).
- Migration `order_datetime default(date('y-m-d h:m:s'))` = littéral figé + format PHP erroné (`m`=mois,
  `h`=12h) — inoffensif si toujours fourni.
- `ItemController::store` : `if(env('DEMO'))` deux branches identiques (code mort) + `env()` hors config.
- File counter-collect = ~140 lignes de closures inline (`routes/api.php:807-915`) — testabilité.
- N° commande POS séquentiel `localStorage` par poste (OK V1 mono-poste, à documenter multi-poste).
- Cache-busting `?v=time()` sur `pos-wizard.js/css` à chaque requête → 0 cache navigateur (perf).

### 6 REFUTÉS par l'adversaire (n'apparaissent PAS comme défauts)
- Uber `forceFill` sans PricingService → **correct** (canal agrégateur merchant-of-record, total = payé Uber).
- `buildCartItem` fallback `null` viande → **fail-safe intentionnel** (mettre `v.id` serait pire = id erroné).
- Backup stale 8j → **schedule correctement câblé** ; c'est le dev-box qui n'a pas de `schedule:run` cron.
- `DeliveryConfigSeeder` untracked → **BranchTableSeeder** (tracké+enregistré) pose déjà la config livraison.
- Token invité 30j → mauvais file:line + décision documentée intentionnelle.
- `PRICING_USE_SSOT` sans boot guard → réfuté (chemin legacy non atteignable en pratique).

---

## 5. NF525 / FROZEN / SÉCURITÉ — attestations
- **NF525 CHAIN OK** sur les 4 branches actives, avant et après une vente réelle (#5398 → seq 2589).
- **Frozen-diff = 0** (mission read-only, aucun fichier produit modifié par la review).
- **Installer `/install`** : vérifié **SAFE** (guard `file_exists(storage_path('installed'))` en
  `__construct` → toutes méthodes redirect ; marker présent).
- **`/payment/{order}`** legacy : présent mais chemin Stripe dormant V1 (drain schedulé) — à durcir
  au cutover cloud, non-bloquant V1 LOCAL.
- **`/api/health`** : 200 public, divulgation infra mineure (version/driver/profondeur queue).
- Boot guards prod (`AppServiceProvider`) refusent `POS_SIMULATION_HARDWARE≠false` en prod (intact).

---

## 6. GATES OWNER (physiques — hors périmètre audit)
| Gate | Quoi | Qui | Statut |
|---|---|---|---|
| G1 | **Déployer le code actuel sur le VPS** (`tools/deploy-vps.sh`) + lancer `queue:work --queue=high,default` | Owner | **PENDING — bloqueur central** |
| G2 | Impression physique SAGA + pont ESC/POS `127.0.0.1:9100` | Owner | PENDING |
| G3 | Vrai TPE caisse (fin du mode simulé) | Owner | PENDING (choix assumé) |
| G4 | Décider heal du P2 loyalty + P3 prioritaires (settings authz, queue runbook) | Owner | PENDING (post-rapport) |

---

## 7. RECOMMANDATIONS PRIORISÉES
1. **Déployer** (G1) — résout les « bugs terrain » (= ancien code VPS) + le temps-réel (`--queue=high`).
2. **Heal P2 loyalty** (SEC-01) — retirer les 2 champs du 409 (5 min, non-frozen).
3. **Runbooks/supervisor** : `queue:work --queue=high,default` (sync temps-réel).
4. **Rafraîchir la doc** : `SYNC_CONTRACT.md` (cadences 5s), docblock outbox, CLAUDE.md (sentinel 66,
   « 45 items » → « ~48 vendables / 59 catalogue »).
5. **Backlog durcissement** : `permission:settings` sur les `index`, middleware constructeur
   OrderHistory, générique-iser le corps des `catch`.
6. **Data-hygiène pré-prod** : purger résidus test (b7/b8/b9, 127 source_surface NULL, 151
   PENDING_COUNTER anciens = source des « 277 alertes SLA/21j »).

---
*Artefacts : `01-STRUCTURE.md`, `05-e2e-live/{pos-caisse,cross-surface-sync,central-uiux}.md`,
`verify-findings.json` (22+6), `captures/` (8 PNG), `plans/GOAL_ULTRA_REVIEW_FULL_STACK_2026-07-02.md`.*
