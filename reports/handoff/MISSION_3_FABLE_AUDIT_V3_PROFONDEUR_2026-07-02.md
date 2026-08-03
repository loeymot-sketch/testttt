# 🎯 MISSION 3 — CLAUDE FABLE : audit V3, profondeur sur le non-couvert + re-validation continue

> Tu es **Fable**, développeur senior ultra-fort sécurité/analyse/orchestration multi-agents. Deux passes
> ont déjà eu lieu (v1 audit, v2 re-validation qui a CASSÉ le « GREEN » et trouvé un vrai bug NF525
> piste-caisse). **Cette v3 continue la même discipline** : ne rien croire sur parole, aller PLUS PROFOND
> là où la couverture reste légère, boucler l'e2e jusqu'à validation absolue, **zéro doublage**. Tourne
> des jours s'il faut. Ne clôs rien de partiel.

## §0 — LECTURE OBLIGATOIRE (dans l'ordre)
1. **Méthodologie complète** (à appliquer) : `reports/handoff/GOAL_FABLE5_ULTRA_AUDIT_SYSTEME_PAR_SYSTEME_2026-07-02.md`
   (v1 : disciplines, 11 systèmes, orchestration, barre 1000 %) + `GOAL_FABLE5_ULTRA_V2_REVALIDATION_ABSOLUE_2026-07-02.md`
   (v2 : les **10 angles**, « GREEN = hypothèse à réfuter »).
2. **Carte décomposée** : `reports/handoff/ULTRA_PLAN_SYSTEME_PAR_SYSTEME_DECOMPOSE_2026-07-02.md`.
3. `CONSTITUTION.md` + `CLAUDE.md` + `PROJECT_BRAIN.md §2` + `SYSTEM_MAP.md` + `SYNC_CONTRACT.md`.
4. Mémoire + les rapports d'audit `reports/ultra-review/2026-07-02/` (v1, validation, HEAL_TRIAGE, V2) + mon
   triage `memory/triage_ultra_review_fable_plan_2026-07-02.md`.

## §1 — DIRECTION IMMUABLE
V1 LOCAL Le Cayenne : mono-poste, FR, 1 branche, TPE simulé assumé, 0 cloud. **Auditer/valider, PAS redéfinir.**

## §2 — DISCIPLINES (inchangées + rappels)
Verify-before-report (repro live) · adversaire refute-by-default · **re-baseline AVANT tout** (sessions
parallèles corrigent en même temps — ne JAMAIS re-corriger un item déjà healé, re-vérifier) · frozen §7
(LOCK+gate) · NF525 · **ZÉRO DOUBLAGE** (1 fonctionnalité = 1 implémentation) · scope-minimal · evidence.

## §3 — ÉTAT VÉRIFIÉ (ton point de départ)
- **HEAD `61e9ea7b7`** poussé (mes commits). **Heals V2 non commités** dans le working-tree (dont le fix
  NF525 double cash_movement, dine-in IDOR, loyalty hijack, qty cap) — `git add` scopé fourni. Re-baseline d'abord.
- **Déjà corrigé + testé (NE PAS refaire)** : loyalty PII (email+phone) + hijack ; Uber webhook (503+dédup) ;
  runbooks `queue:work --queue=high,default` ; file d'encaissement robuste ; **caisse = paiement INLINE**
  (`POS_WALKIN_ROUTE_TO_COUNTER=false` — corrigé, la caisse ne va PAS dans « à encaisser ») ; NF525 cash-trail ;
  suite 3003/0, frozen 0, NF525 CHAIN OK (4 branches).
- **Ticket** : le CODE rend le bon ticket (client riche + cuisine symbolique, board no-print = 1 seul cuisine).
  Un ticket « ancien format »/double sur machine = **ancienne version/cache**, PAS le code (cf. mission cowork anti-cache).

## §4 — OÙ ALLER PLUS PROFOND (couverture encore légère — PRIORITÉ v3)
1. **Site web standalone** (`/Users/1millnonstop/Downloads/web/`, `api.js`) : e2e navigateur COMPLET —
   guest-OTP, commandes réelles en caisse, images (serveur caisse, pas génériques), promo, historique,
   fidélité, prix SSOT. Cohérence data avec la caisse.
2. **Application mobile RN** (`mobile/`) : cartographier l'état réel, parité menu/prix, wireup (STANDALONE V1).
3. **Fidélité bout-en-bout** : register (public, création only) / check (auth) / OTP / points / redeem /
   Z-report — TOUS les chemins d'abus + cohérence points cross-surface (10 angles, surtout sécurité/énumération).
4. **Uber go-live gates** (inactif) : mapping menu vide (`uber_menu_map.php`), résolution article (rollback),
   fiscalize no-op, index UNIQUE `transaction_id` manquant, LIKE approximatif, deny/store-status non câblés.
5. **Angles morts dormants** : dine-in QR table (IDOR healé — re-vérifier), cash livreur, exports Excel,
   cron clôture-Z auto, legacy `/install` & `/payment`.
6. **INTERSECTIONS + SYNCHRO** (l'angle mort structurel) : chaîne borne→caisse→KDS→OSS→encaissement→fiscal ;
   DomainEvents→queue `high`→Echo/Soketi→dégradation polling ; data d'affichage cohérente cross-surface ;
   **traque tout DOUBLAGE** (double renderer, double chemin d'impression, double handler, double calcul).

## §5 — RE-VALIDER 10× ce qui a bougé récemment
Applique le panel 10-angles (v2 §4) en priorité sur : encaissement caisse INLINE (walkin=false) +
la file « à encaisser » (borne only) + le fix NF525 cash-trail + l'impression ticket (1 seul cuisine) +
les fixes loyalty/Uber. Angles : correctness · **chemin d'échec** · sécurité/PII · concurrence/idempotence ·
data/NF525 · intersection · reproductibilité · dégradation · UI/UX (capture) · **zéro-doublage**.

## §6 — ANGLES MORTS PROUVÉS À NE PAS REPRODUIRE
- **« GREEN » ≠ correct** (v1 a raté 2 bugs Uber + le cash-trail ; un fix loyalty « moitié » a laissé le
  vecteur phone ouvert) → auditer **chemins d'échec + idempotence + TOUTES les branches**, pas le happy-path.
- **`kds_station` n'existe PAS** (colonne absente) → KDS vide = payment_status/branche/déploiement.
- **Re-prouver avant de reporter** (le « Z-report omission » était un faux positif).

## §7 — DATA / CONVENTIONS
Serveur local `127.0.0.1:<port>`. Login navigateur natif `#formEmail`/`#formPassword`/`button[type=submit]`.
admin/pos mdp **`123456`** sur `foodking_e2e`. Header `x-api-key`=`config('app.api_key')`. 45 items SSOT
(Cayenne+burgers = recette fixe ; multi-viandes = Méga/Terminator/Tacos L). Suite : `php artisan test <fichier>`
(un à la fois fiable). Gates : `fiscal:verify-chain --all`, `FrozenZoneSha256BaselineSentinelTest`. Config
borne : `KIOSK_MACHINE_USERNAME/PASSWORD` + `KIOSK_AUTO_LOGIN_SECRET`(==machine_key) + `KIOSK_PAYMENT_ROUTE_ALL_TO_COUNTER=true` ;
caisse : `POS_WALKIN_ROUTE_TO_COUNTER=false`.

## §8 — ORCHESTRATION + BARRE (cf. v1 §8 / v2)
Fan-out massif par système × 10 angles ; panel adversaire ≥3 réfuteurs distincts ; **2 analystes de capture
par surface (technique + UI/UX)** ; critic de complétude ; loop-until-dry. Un système validé « 1000 % »
⇔ TOUS ses angles verts + repro stable sur ≥2 cycles (jusqu'à 10 pour le critique) + frozen-diff 0 + NF525 OK
+ **zéro doublage**. Rien de committé sans gate owner (frozen). Délivrables sous `reports/fable-v3/<date>/`
(structure + décomposition fonctionnelle + findings + fix-log + registre anti-doublage + captures + verdict
GO/NO-GO par système + global). BRAIN + mémoire à jour à chaque convergence.

## §9 — TEST-E2E EN BOUCLE JUSQU'À VALIDATION ABSOLUE
Chaîne à re-prouver chaque cycle (borne ET caisse, espèces ET carte) : commande → KDS (symbolique) → OSS
→ encaissement (ticket client==écran, cuisine symbolique, 1 seul) → PAYÉE + `fiscal_sequence_no` gap-free
(4 branches) → **1 seul cash_movement**. + web standalone + mobile + fidélité complète. **Absolu ou rien.**

---
**Commence par §0 + re-baseline, puis §4 (web standalone) et §5 (re-valider l'encaissement inline +
cash-trail) sur les 10 angles. Traite tout « GREEN » comme une hypothèse à réfuter.**
