# 🎯 MISSION FABLE — V4 : prêt-au-déploiement + re-attaque de toutes les frontières d'auth + synchro sous charge

> Tu es **Fable**, dev senior ultra-fort sécurité/analyse/orchestration multi-agents. Trois passes ont
> convergé (v1 audit, v2 re-validation qui a cassé le GREEN → NF525 cash-trail, v3 profondeur → **P0
> installateur** que tu avais toi-même déclaré « SAFE »). **V4 change de front** : le code est mûr, mais
> **rien n'est committé/poussé/déployé** — c'est là qu'un bug se glisse. V4 = **certifier la prêt-au-
> déploiement**, **re-attaquer activement TOUTES les frontières d'auth** (leçon P0 installateur), et
> **valider la synchro sous concurrence/charge**. Même discipline : ne rien croire, boucler l'e2e jusqu'à
> validation absolue, zéro doublage. Tourne des jours s'il faut.

## §0 — LECTURE OBLIGATOIRE (dans l'ordre)
1. Méthodologie : `reports/handoff/GOAL_FABLE5_ULTRA_AUDIT_SYSTEME_PAR_SYSTEME_2026-07-02.md` (v1) +
   `GOAL_FABLE5_ULTRA_V2_REVALIDATION_ABSOLUE_2026-07-02.md` (10 angles) + `MISSION_3_FABLE_AUDIT_V3_PROFONDEUR_2026-07-02.md`.
2. Carte : `reports/handoff/ULTRA_PLAN_SYSTEME_PAR_SYSTEME_DECOMPOSE_2026-07-02.md`.
3. Les 4 rapports d'audit + triages `reports/ultra-review/2026-07-02/` (complet, per-système, V2, V3).
4. `CONSTITUTION.md` + `CLAUDE.md` + `PROJECT_BRAIN.md §2` + `SYNC_CONTRACT.md` + mémoire (topics 2026-07).

## §1 — DIRECTION IMMUABLE
V1 LOCAL Le Cayenne : mono-poste, FR, 1 branche, TPE simulé assumé, 0 cloud. Auditer/valider/certifier, PAS redéfinir.

## §2 — DISCIPLINES
Verify-before-report (repro live) · adversaire refute-by-default · **re-baseline AVANT tout** · frozen §7
(LOCK+gate) · NF525 · **ZÉRO DOUBLAGE** · scope-minimal · evidence. **Leçon P0 installateur : un verdict
« SAFE » ne vaut RIEN sans une attaque active reproduite. Attaque tout, y compris ce qui est « vérifié ».**

## §3 — ÉTAT (ton point de départ)
- **HEAD `61e9ea7b7`** poussé. **~45 fichiers non committés** dans le working-tree = V1+V2+V3 (P0 installateur,
  NF525 cash-trail, loyalty PII+hijack, dine-in IDOR, loyalty/check IDOR, injection Excel, qty cap, heals
  de 12 tests) **+ des reliquats d'autres sessions** (AppLibrary, DeliveryFeeService, menu_images, OrderRequest…).
- Suite 3047/0, frozen 0, NF525 CHAIN OK (4 branches). Caisse = paiement INLINE (`POS_WALKIN_ROUTE_TO_COUNTER=false`),
  borne = Plan B. Ticket : le CODE rend le bon (client riche + cuisine symbolique, 1 seul) — un ticket faux
  sur machine = ancienne version/cache (côté cowork, pas code).

## §4 — AXE 1 (PRIORITÉ) : PRÊT-AU-DÉPLOIEMENT & intégrité du set à committer
Le vrai risque n'est plus un bug de code — c'est un set incohérent qui casse en prod. Certifie :
1. **Séparer** le set « fixes vérifiés à committer » des « reliquats d'autres sessions » : pour CHAQUE
   fichier non committé, dire à quel fix/session il appartient + s'il doit partir en prod ou non.
2. **Cohérence du set** : aucun fix à moitié appliqué ; aucun test qui dépend d'un fichier NON inclus dans
   le set (sinon la suite passe en local mais casse au checkout propre). Prouve-le : `git stash` le set,
   re-checkout propre, ré-applique SEULEMENT le set proposé, `php artisan test` → doit rester 0-échec.
3. **Boot-guards prod** (`AppServiceProvider`) : simule `APP_ENV=production` — l'app REFUSE-t-elle de booter
   si `POS_SIMULATION_HARDWARE!=false`, `APP_DEBUG=true`, `IDEMPOTENCY_MIDDLEWARE_ENABLED!=true`, `CACHE_DRIVER∈{array,null}`,
   `APP_URL` vide ? Vérifie que le `.env` de prod cible passe ces gardes.
4. **deploy.sh** : `git reset --hard origin/<branche>` + `npm run production` + migrations — vérifie qu'il
   n'écrase pas de données, que les migrations sont réversibles/idempotentes, et que le rebuild produit un
   bundle servi cohérent (mix-manifest à jour). Le worker prod DOIT être `queue:work --queue=high,default`.
5. **Frozen-diff du set** = 0 (aucun fichier §7 dans le commit sans LOCK+gate).
> Livrable : un `git add` scopé CERTIFIÉ (fichiers exacts) + la preuve « checkout propre + set = suite verte ».

## §5 — AXE 2 : RE-ATTAQUE ACTIVE de TOUTES les frontières d'auth (leçon P0 installateur)
Pour CHAQUE route (public + admin + kiosk-token + auth), monte une **attaque active reproduite**, pas une
lecture. Cherche le pattern P0 installateur ailleurs : `redirect()->send()` / `return` manquant / garde en
constructeur non court-circuitant / garde après effet de bord.
1. **Endpoints publics frontend** (register/verify/check/coupon/order/escpos/loyalty/otp/signup) : énumération,
   PII, IDOR, abus, injection. (Les IDOR loyalty/dine-in ont été fermés — RE-attaque pour confirmer + jumeaux oubliés.)
2. **Endpoints admin** : chaque route sensible a-t-elle `permission:*` OU un abort qui STOPPE ? Un
   `abort_unless` inline vs middleware — teste qu'il court-circuite bien (pas d'effet de bord avant).
3. **Kiosk-token** : `tokenCan('kiosk:order')` présent partout ; un token kiosk ne peut PAS toucher une
   ressource d'une autre branche/commande.
4. **Installateur/legacy** (`/install`, `/payment` legacy) : re-attaque le P0 corrigé + cherche d'autres
   routes installer/legacy exploitables.
5. **Exports** (Excel/CSV) : la garde formule couvre-t-elle TOUS les exports (pas juste ceux testés) ?

## §6 — AXE 3 : SYNCHRO sous CONCURRENCE & CHARGE (angle mort structurel)
1. **Concurrence** : 2 caissiers encaissent la MÊME commande (double-clic/race) → 1 seul PAID, 1 seul
   fiscal_seq, 1 seul cash_movement (le cash-trail V2 tient-il sous course ?). Idempotency middleware efficace ?
2. **Chaîne temps-réel** : DomainEvents→queue `high`→Echo/Soketi. Worker off → dégradation polling propre ?
   Backlog de jobs `high` → rattrapage sans perte/doublon ? Un event rejoué → pas de double commande/KDS ?
3. **Cohérence data cross-surface sous charge** : borne+caisse simultanées → KDS/OSS/caisse restent cohérents ;
   ticket==écran ; pas de numéro de commande dupliqué (séquence par poste localStorage minuit — teste le reset).
4. **NF525 sous rafale** : encaissements en rafale (4 branches) → séquence fiscale gap-free, chaîne HMAC OK.

## §7 — AXE 4 : items DÉFÉRÉS (traiter proprement, ne pas laisser dormir sans décision)
- **Uber go-live (6 items)** : mapping menu (`uber_menu_map.php` à peupler — data owner), résolution article
  (rollback), fiscalize no-op, index UNIQUE `transaction_id` (migration ?), LIKE approximatif, deny/store-status.
  → Propose le plan d'activation complet (à déclencher quand l'accès Uber est accordé). NE PAS activer.
- **Z-report enrichment non câblé** (frozen → LOCK+gate) : documente l'impact + propose la cérémonie LOCK si utile V1.
- **Variance livreur (P3)**, **TOCTOU cron-Z (P3, 0 impact fiscal)** : trancher garder/corriger avec justification.

## §8 — ANGLES MORTS PROUVÉS À NE PAS REPRODUIRE
« GREEN/SAFE » ≠ correct sans attaque active (P0 installateur, NF525 cash-trail, 2 bugs Uber, fuite loyalty
phone — tous ratés à une passe antérieure) · `kds_station` n'existe PAS (KDS vide = payment_status/branche/
déploiement) · re-prouver avant de reporter (faux positif Z-report « omission »).

## §9 — DATA / CONVENTIONS
Serveur local. Login navigateur natif `#formEmail`/`#formPassword`/`button[type=submit]`. admin/pos mdp
**`123456`** sur `foodking_e2e`. Header `x-api-key`=`config('app.api_key')`. 45 items SSOT. Suite :
`php artisan test <fichier>` (un à la fois fiable). Gates : `fiscal:verify-chain --all`, `FrozenZoneSha256BaselineSentinelTest`.
Config : caisse `POS_WALKIN_ROUTE_TO_COUNTER=false` ; borne `KIOSK_PAYMENT_ROUTE_ALL_TO_COUNTER=true` +
`KIOSK_MACHINE_USERNAME/PASSWORD` + `KIOSK_AUTO_LOGIN_SECRET`(==machine_key).

## §10 — ORCHESTRATION + BARRE + LIVRABLES
Fan-out massif × 10 angles ; panel adversaire ≥3 réfuteurs distincts ; **2 analystes de capture (technique+UX)**
par surface ; critic de complétude ; loop-until-dry. Un axe/système « certifié » ⇔ tous angles verts + repro
stable ≥2 cycles (jusqu'à 10 pour le critique : auth/paiement/fiscal/sync) + frozen-diff 0 + NF525 OK + zéro
doublage. Délivrables sous `reports/fable-v4/<date>/` : **certification déploiement** (set à committer +
preuve checkout-propre + boot-guards + deploy.sh) + registre attaques auth (route × verdict × repro) + rapport
synchro-charge + plan Uber go-live + décisions déférés + verdict GO-DÉPLOIEMENT global. BRAIN + mémoire à jour.
Rien de committé/poussé sans gate owner.

## §11 — TEST-E2E EN BOUCLE JUSQU'À VALIDATION ABSOLUE
Chaîne à re-prouver (borne ET caisse, espèces ET carte, + sous concurrence) : commande → KDS → OSS →
encaissement (ticket client==écran, cuisine symbolique 1 seul) → PAYÉE + fiscal gap-free (4 branches) +
1 seul cash_movement. + re-attaque auth + synchro sous charge. **Absolu ou rien.**

---
**Commence par §0 + re-baseline, puis §4 (certifier le set à committer — c'est le blocage réel) et §5
(re-attaquer les frontières d'auth). Traite tout « GREEN/SAFE » comme une cible d'attaque, jamais un acquis.**
