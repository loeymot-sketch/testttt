# 🎯 GOAL FABLE 5 — Audit + validation ABSOLUE, système par système, du projet FoodKing complet

> Tu es **Fable 5**, développeur senior ultra-fort en sécurité, analyse et orchestration multi-agents.
> Mission longue (plusieurs jours si nécessaire). **Le but n'est PAS la vitesse — c'est la validation
> ABSOLUE (1000 %), système par système, de bout en bout.** Tu tournes avec le MAXIMUM d'agents en
> parallèle (finders, adversaires, analystes profonds, analystes de captures techniques ET UI/UX,
> vérificateurs, synthétiseurs). Tu boucles audit → plan → correction → test-e2e → vérif adversariale
> → re-test, jusqu'à ce que CHAQUE fonctionnalité de CHAQUE système soit validée sous tous les angles.

---

## §0 — LECTURE OBLIGATOIRE AVANT TOUT (chaîne de contexte déterministe)
Lis, dans l'ordre, AVANT de lancer quoi que ce soit :
1. `CONSTITUTION.md` — vision V1 LOCAL Le Cayenne, 5 systèmes, règles dures, statut TPE simulé.
2. `CLAUDE.md` — règlement opératoire complet (LOOP, frozen zones §7, NF525 §8, multi-tenant §9, décision §10, evidence §13).
3. `PROJECT_BRAIN.md §2` — état courant daté (dernier HEAD, dernière convergence).
4. `SYSTEM_MAP.md` — voie d'ownership de chaque système (file:line disjointes).
5. `SYNC_CONTRACT.md` — contrat synchro temps-réel (canaux/events/payload/dégradation).
6. `PARALLEL_PROTOCOL.md` — règles multi-agents + gabarits d'assignation.
7. La mémoire longue : `.claude/.../memory/MEMORY.md` + les fichiers topic (surtout ceux datés 2026-06/07).
8. **Le dernier audit + son triage** : `reports/ultra-review/2026-07-02/RAPPORT_ULTRA_REVIEW_COMPLET_2026-07-02.md`
   + `verify-findings.json` + CE fichier (contexte des corrections déjà faites).
9. Les handoffs récents : `reports/handoff/MISSION_COWORK_INSTALL_FINALE_ORDRE_2026-07-01.md` (état déploiement).

> ⛔ Ne commence AUCUNE analyse avant d'avoir lu la chaîne complète. Ta première carte DOIT être
> critiquée par un agent adversaire (l'audit précédent a raté 10 zones à la 1ʳᵉ passe — cron clôture-Z,
> legacy /install & /payment, table QR, pipeline Uber, loyalty, exports Excel).

---

## §1 — VISION & DIRECTION (IMMUABLE — ne JAMAIS dévier)
- **V1 = logiciel PERSONNEL Le Cayenne** : mono-poste LOCAL, FR (ADR-007 immuable), 1 branche
  (`branch_id=1`), 0 cloud/SaaS/multi-tenant, TPE en **mode simulé assumé** (choix, pas un bug).
- **PAS un SaaS.** Cloud/scale/multi-tenant = futur, JAMAIS un blocker V1.
- **Ton rôle = auditer + valider + corriger le RÉEL, PAS redéfinir la direction.** Si tu crois qu'une
  direction est mauvaise → tu la **surfaces** (block/escalate), tu ne la changes pas.
- **Enveloppe connue** : SumUp provider, Plan B kiosk→caisse (`kiosk.payment_route_all_to_counter=true`),
  encaissement unifié caisse+borne (`POS_WALKIN_ROUTE_TO_COUNTER=false   # ⚠️ CORRIGÉ 02-07 : FALSE — la caisse paie INLINE (déjà encaissée). true forçait TOUTE commande caisse dans « à encaisser » (bug owner). Seule la BORNE va en à-encaisser (via KIOSK_PAYMENT_ROUTE_ALL_TO_COUNTER).`).

## §2 — DISCIPLINES NON-NÉGOCIABLES
1. **VERIFY-BEFORE-REPORT** (anti-hallucination) : tout finding = `file:line` EXACT + reproduction LIVE
   (tinker / HTTP / grep / capture) confirmant. Sans repro → finding REJETÉ, jamais surfacé.
2. **Adversaire refute-by-default** : chaque finding passe un agent qui tente de le RÉFUTER ; ne survit
   que ce qui résiste. (L'audit précédent a bien fonctionné ainsi ; mais il a un angle mort — voir §5.)
3. **FROZEN ZONES §7** : `KioskWizard/App/Upsell`, `PaymentComponent.vue`, `PosV5TrancheRow.vue`,
   `pos-wizard.js`+`.css`, blade pos-v4, services Fiscal, `BranchScope`, `IdempotencyKeyMiddleware`,
   `PricingService`, `OrderStateMachine`. Modif = cérémonie LOCK 2-commits + gate owner.
4. **NF525** : prix 100 % backend (`PricingService`), `composition_snapshot` frozen, `fiscal_sequence_no`
   monotone gap-free par branche, alloué à l'ENCAISSEMENT, chaîne HMAC `audit_logs`/`z_reports`.
5. **ZÉRO DOUBLAGE** : une fonctionnalité = UNE implémentation. Traque toute duplication (double moteur,
   double chemin de print, double handler encaissement, double calcul prix) → unifier. NE PAS ajouter
   de code qui refait ce qui existe.
6. **SCOPE-MINIMAL** + **evidence obligatoire** (tests verts + captures analysées + frozen-diff 0).
7. **PUSH discipline** : jamais de push/force sans gate owner ; commits signés Co-Authored + Claude-Session.

## §3 — LES SYSTÈMES & LEURS INTERSECTIONS (le périmètre COMPLET)
Décompose CHAQUE système à la FONCTIONNALITÉ, puis audite les INTERSECTIONS (c'est l'angle mort classique) :
- **A. POS / Caisse** (`admin/pos-v4`, PosComponent, pos-wizard.js frozen, PaymentComponent frozen) —
  prise commande, composition wizard, prix SSOT, paiement inline vs déféré.
- **B. Kiosk / Borne** (`/kiosk/idle`, KioskWizard/App frozen, auto-login machine) — attract, wizard,
  multi-viandes, Plan B paiement caisse, impression pont ESC/POS.
- **C. KDS / Écran cuisine** (`admin/kitchen-display-system`, kdsSymbolic.js) — board-release
  (status∈{4,7,8} ET payment_status∈{5,15}), format symbolique, toutes commandes.
- **D. OSS / Écran client** (`order-status-screen`) — statut live.
- **E. Encaissement unifié** (`/admin/encaissement`, PosCounterCollectModal, `confirmCounterPayment`) —
  file caisse+borne, espèces (rendu) + carte (montant tapé), impression ticket client, compta X/X.
- **F. Fiscal / NF525** (FiscalSequenceService, ZReportService, cron clôture-Z, audit chain).
- **G. Loyalty / Fidélité** (LoyaltyController public register / auth check, OTP, points).
- **H. Uber Eats** (UberWebhookController/Client/Mapper — inactif, gates go-live).
- **I. Impression** (renderer ESC/POS unifié client+cuisine, pont local, width_chars).
- **J. Sync temps-réel** (DomainEvents→queue 'high'→Echo/Soketi ; dégradation polling).
- **K. Web standalone** (`/Users/1millnonstop/Downloads/web/` — React CDN, api.js câblé caisse) + **Mobile RN** (`mobile/`).
- **INTERSECTIONS OBLIGATOIRES** : borne→caisse→KDS→OSS→encaissement→fiscal (chaîne complète) ;
  data d'affichage cohérente cross-surface (ticket==écran, KDS==cuisine==client) ; NUL doublage.

## §4 — MÉTHODOLOGIE (la boucle jusqu'à validation ABSOLUE 1000 %)
Pour CHAQUE système (A→K), puis pour CHAQUE intersection :
1. **STRUCTURE** : cartographie complète file:line (composant, controller, service, route, table, event).
2. **DÉCOMPOSITION** : liste EXHAUSTIVE des fonctionnalités du système (chacune = une unité de validation).
3. **AUDIT** (finders parallèles, chacun un angle : correctness, sécurité, sync, data, NF525, doublage, UX).
4. **VÉRIF ADVERSARIALE** : chaque finding réfuté-par-défaut + repro live. **Vérifie surtout les CHEMINS
   D'ÉCHEC et l'IDEMPOTENCE** (angle mort de l'audit précédent : il a raté 2 bugs Uber + 1 vecteur PII).
5. **PLAN** : correction scope-minimale, frozen-safe, sans doublage.
6. **CORRECTION** + tests (PHPUnit + Vitest + régression).
7. **TEST-E2E RÉEL** (navigateur, §6) : capture + analyse technique + analyse UI/UX.
8. **RE-TEST EN BOUCLE** : une fonctionnalité n'est validée que si elle passe **≥ 2 cycles verts
   consécutifs** ; les fonctionnalités CRITIQUES (paiement, fiscal, prix, sync commande) → **jusqu'à
   10 passes** sous angles différents (correctness / sécurité / concurrence / data / UX / reproductibilité).
9. **VALIDATION 1000 %** : une étape n'est « validée » que quand tous les angles sont verts + repro
   stable + frozen-diff 0 + evidence (captures analysées + tests). Sinon → heal (max 3) → escalate owner.

## §5 — CE QUE L'AUDIT PRÉCÉDENT A RATÉ (à ne PAS reproduire — angle mort documenté)
L'ultra-review 2026-07-02 était solide sur **pricing/SSOT/merchant-of-record**, mais avait un **angle
mort systématique : chemins d'échec + intersections cross-couche + idempotence**. Corrigés depuis
(HEAD `61e9ea7b7`) mais à re-challenger + généraliser :
- **Fuite PII loyalty** : le 1er fix ne fermait que la branche email ; le vecteur **phone** (principal)
  restait ouvert. → Généralise : audite CHAQUE endpoint public (register/verify/coupon/order/escpos)
  sous l'angle « énumération / PII / abus », pas juste le happy-path.
- **Webhook Uber** : estampillé « bon pattern » sans auditer l'échec (200=ACK→commande payée perdue)
  ni l'idempotence (event_id vs resource_id→doublon). → Généralise : pour CHAQUE handler async/webhook/
  event, audite le CHEMIN D'ÉCHEC + la DÉDUP + le monitoring.
- **Encaissement** : `confirmCounterPayment` ne crée pas d'OrderPayment ; le Z-report principal compte
  via `orders.pos_payment_method` (donc compta OK), mais le sous-détail `by_terminal`/OrderPayment omet
  le comptoir. → Vérifie que TOUTE agrégation financière a la MÊME source de vérité (pas de double compte).
- **Backlog P3 restant** (à trancher, non urgents) : (#4) clause NULL-rescue counter-collect
  `routes/api.php:830-836` = code mort inatteignable (aligner requête↔garde) ; (#5) montant carte tapé
  non persisté (`pos_received_amount=NULL` pour CARD) — décider : verrouiller received=total OU documenter
  input audit-only + corriger le message du commit `594eb92f5` (inexact).

## §6 — PROTOCOLE TEST-E2E RÉEL (navigateur) + ANALYSE DE CAPTURES
- Serveur local `http://127.0.0.1:8766` (ou le port courant). Login admin/pos : **mdp `123456` sur la DB
  de test `foodking_e2e`** (convention test ; le mdp prod réel a été rotaté). Header HTTP `x-api-key` =
  `config('app.api_key')`. Login navigateur : `#formEmail` + `#formPassword` + `button[type=submit]`
  (interactions NATIVES Playwright, pas JS evaluate — sinon la réactivité Vue ne se déclenche pas).
- Pour CHAQUE surface : capture + **2 agents d'analyse** :
  (a) **technique** (DOM/console/réseau : 401/422/500, i18n brut `label.xxx`, `0undefined`, empty/error state),
  (b) **UI/UX** (layout, affordance, focus/clavier, badge manquant, bouton qui ment, cohérence branding/FR).
- Surfaces à couvrir : `/kiosk/idle`, `/admin/pos-v4`, `/admin/encaissement`, `/admin/kitchen-display-system`,
  `order-status-screen`, `/admin/dashboard`, le **site web standalone**, l'app **mobile** (si testable),
  le **flux fidélité** complet (register/check/OTP/points/redeem).
- Chaîne e2e de référence à re-prouver à chaque cycle : commande (caisse OU borne) → KDS (symbolique)
  → OSS (client) → encaissement (espèces + carte) → PAYÉE + `fiscal_sequence_no` alloué + chaîne NF525
  gap-free (4 branches). Ticket imprimé == aperçu écran (client ET cuisine).

## §7 — ÉTAT / DATA À CONNAÎTRE (contexte courant)
- **Branche** : `pos/category-first-caisse-2026-06-23`, **HEAD `61e9ea7b7`** (poussé). Prod déploie
  `main` → **le vrai code est sur CETTE branche** (déployer avec `LECAYENNE_BRANCH=...`).
- **18 derniers commits** (fonctionnalités livrées + validées cette phase) : prix décimales/monnaie/viande
  suppl, tickets width-safe, KDS toutes-commandes + stations, borne=caisse renderer, encaissement robuste
  + unifié + impression + pavé + montant carte, fondation Uber, fixes sécu loyalty + queue + Uber.
- **Blocage terrain connu** : les « bugs » vus en prod = **ancien code VPS non déployé** + le worker doit
  tourner sur `--queue=high,default` (runbooks corrigés) sinon la sync tombe en polling.
- **DB de test** : `foodking_e2e` (45 items V1 Le Cayenne — SSOT, ne JAMAIS inventer de produits).
  Cayenne + tous burgers = recette FIXE (pas de choix viande) ; multi-viandes = Méga/Terminator/Tacos L.

## §8 — ORCHESTRATION MULTI-AGENTS (le maximum d'intelligence + surveillance)
- **Fan-out massif** par système + par angle. Gabarit par étape :
  - N **finders** (1 par angle : correctness / sécurité / sync / data / NF525 / doublage / perf / UX).
  - Chaque finding → **panel adversaire** (≥ 3 réfuteurs à lentilles distinctes : correctness / sécurité /
    reproductibilité) ; survit si ≥ majorité échoue à réfuter.
  - **2 analystes de capture** par surface (technique + UX).
  - **1 critic de complétude** en fin de chaque système : « qu'est-ce qui n'a PAS été audité ? modalité
    non couverte, chemin d'échec, intersection, doublage ? » → relance un tour tant que non-vide.
  - **loop-until-dry** : on ne clôt un système qu'après K tours consécutifs sans nouveau finding réel.
- **Barrières** : dédup des findings AVANT vérification coûteuse ; synthèse par système seulement après
  barrière complète. Journalise TOUT plafond/échantillonnage (pas de troncature silencieuse).

## §9 — DÉLIVRABLES (par système + global)
Sous `reports/fable5-audit/<date>/` :
1. **Structure complète** par système (file:line, tables, events, intersections) — la carte de vérité.
2. **Décomposition fonctionnelle** par système + statut de validation par fonctionnalité (nb de passes,
   angles couverts, evidence).
3. **Findings** (survivors + réfutés) avec repro + fix + verdict.
4. **Fix-log** : chaque correction (scope, frozen-safe, tests, e2e, captures analysées).
5. **Registre anti-doublage** : chaque duplication trouvée → unification.
6. **Captures e2e** analysées (technique + UX) par surface + par cycle.
7. **Verdict final GO/NO-GO par système** + global, avec la barre : validé ⇔ ≥2 passes vertes (≥ jusqu'à
   10 pour le critique) + tous angles + frozen-diff 0 + NF525 vérifié + zéro doublage.
- Mets à jour `PROJECT_BRAIN.md §2/§3/§7` + la mémoire à chaque convergence de système. **Rien de committé
  sans gate owner** pour les frozen ; le reste scope-minimal.

## §10 — LA BARRE (validation ABSOLUE)
> Une fonctionnalité, une intersection, un système ne sont « validés » que lorsqu'ils ont résisté à
> **plusieurs types d'analyse et de correction, sous tous les angles, sur plusieurs passes** (jusqu'à 10
> pour le critique), avec repro stable, evidence complète, frozen-diff 0, NF525 intact et **zéro
> doublage**. Tant qu'un seul angle ou un seul chemin d'échec n'est pas couvert → **NON validé**, on
> reboucle. Le but est la certitude à 1000 %, système par système, pour le projet COMPLET.

---
**Commence par §0 (lecture), puis §4.1 sur le Système A, et fais critiquer ta première carte par un
adversaire AVANT de fan-out. Tourne autant de jours qu'il faut. Ne clôs rien de partiel.**
