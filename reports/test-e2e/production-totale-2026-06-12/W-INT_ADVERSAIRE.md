# W-INT — Rapport ADVERSAIRE-MERGE (T-INT.6)
2026-06-12 · Adversaire-merge W-INT · worktree `integration-v1-2026-06-12` · branche `release/v1-integration-2026-06-12` · HEAD `e9a11710b`

> Mandat : RÉFUTER le travail de l'intégrateur (`W-INT_RAPPORT.md`). Read-only code
> (0 edit source), re-exécution indépendante de chaque claim : merges, suites, re-jeu
> live :8770, frozen-diff, chaîne NF525. Chaque claim ci-dessous = commande + sortie citée.

## VERDICT : **CONFIRMED — intégration saine** (0 issue bloquante ; 2 observations non-bloquantes + 1 levée de defer)

---

## 1. Les 3 merges — topologie vérifiée

```
$ git rev-parse heal/ultra-audit-w4-2026-06-11 heal/clients-next-2026-06-10 goal/cms-gestion-2026-06-10-spine HEAD release/v1-2026-06-10
da32af6b9… / c10320958… / 02042a687… / e9a11710b… / 120597bc7…
$ git merge-base --is-ancestor <chaque tip> HEAD
heal/ultra-audit-w4-2026-06-11 = ANCESTOR of HEAD
heal/clients-next-2026-06-10   = ANCESTOR of HEAD
goal/cms-gestion-2026-06-10-spine = ANCESTOR of HEAD
```
- Les 3 tips correspondent **exactement** aux SHA sources du rapport (`da32af6b9`, `c10320958`, `02042a687`) — aucun drift de branche depuis le merge.
- `git branch --contains` : chacune des 3 branches n'est contenue que dans elle-même + `release/v1-integration-2026-06-12`. ✅
- Topologie des parents (`git log -1 --format=%P`) :
  - `e28cba39b` = merge(`120597bc7` BASE_SHA, `da32af6b9` w4)
  - `9fbef73c9` = merge(`e28cba39b`, `c10320958` clients-next)
  - `168064249` = merge(`9fbef73c9`, `02042a687` cms-spine)
- `release/v1-2026-06-10` = `120597bc7` = BASE_SHA pinné : **le spine n'a pas bougé depuis le pin** (G-SPINE tenu).
- Arbre propre : `git status` ne montre QUE des untracked (`.env.e2e` + rapports copiés). 0 modification source non commitée.

## 2. Suites re-exécutées par moi (DB foodking_test, `./vendor/bin/phpunit` du worktree)

| Suite | Rapport intégrateur | Ma re-exécution | Verdict |
|---|---|---|---|
| 10 classes W4 (8 acceptance + Outbox + Transactional) | OK (32, 105) | **OK (32 tests, 105 assertions)** | identique ✅ |
| Univers fidélité `--filter 'Loyalty\|Redeem\|EventContract'` | OK (118, 471) | **OK (118 tests, 471 assertions)** | identique ✅ |
| `tests/Feature/Loyalty/` | OK (28, 113) | **OK (28 tests, 113 assertions)** | identique ✅ |
| `tests/Feature/Composer/` | 123, 535, 2 skipped, 0 fail | **Tests: 123, Assertions: 535, Skipped: 2** | identique ✅ |
| Vitest `routerRedirectIntegrity.spec.js` | 1/1 | **1 passed (1)** | identique ✅ |
| 7 specs Vitest fidélité | 48/48 | **48 passed (48)** | identique ✅ |
| **PHPUnit FULL (bonus adversaire — était DEFERRED df)** | déféré (1,1 Gi) | **LANCÉE par moi (en cours à la clôture de ce rapport**, `./vendor/bin/phpunit` worktree intégration, PID 28749, task bg `b83oyq08q`) — hors mandat T-INT.6, démarrée car G-DISK de fait levé (11 Gi). Résultat à lire dans la sortie du task ou re-jouer per DEFER MANIFEST | en cours (hors mandat) |

## 3. T-INT.6 — re-jeu live des fermetures clés (serveur :8770)

Serveur : `APP_ENV=e2e PHP_CLI_SERVER_WORKERS=8 php artisan serve --port=8770` (nohup, PID listener 26449) ; provenance vérifiée `lsof -p … cwd = …/integration-v1-2026-06-12/public`. Comptes dédiés re-confirmés : `ultraheal@` **id=61 Admin**, `ultraheal-pos@` **id=62 POS Operator** ; tokens frais `advmerge-*` créés via tinker (jamais les tokens partagés).

### (a) Flip off-book 15→5 sans trace → **422 FR** ✅ (RED-DASH-02 / P0)
Cible re-SELECTée : ordre **4548** (`payment_status=15`, `fiscal_sequence_no=NULL`, 0 `order_payments`, 0 `transactions`).
```
POST /api/admin/pos-order/change-payment-status/4548  {"payment_status":5}
→ HTTP=422  {"status":false,"message":"Encaissement requis : cette commande ne porte aucune
   trace de paiement. Utilisez le flux d'encaissement (encaissement comptoir / Vue Caisse)…"}
DB après : payment_status=15, fiscal_sequence_no=NULL, order_payments=0 → statut INTACT
```

### (b) GET variation/group-by-attribute/37 → **200** ✅ (variantes-500)
```
GET /api/admin/item/variation/group-by-attribute/37 → HTTP=200
{"data":[{"item_attribute_id":5,"name":"Sauce (1ère Gratuite)","children":[{"id":162,…"name":"Spicy"…
```

### (c) sales-report/overview caissier → **403** ✅ (CDASH-01)
```
GET /api/admin/sales-report/overview (token ultraheal-pos@, POS Operator) → HTTP=403
GET /api/admin/sales-report/overview (token ultraheal@, Admin)           → HTTP=200
```

### (d) POS quote item 2 + extras 236/237 → **total 4,00 €** ✅ (CAISSE-01)
Ids re-SELECTés : `item_extras` item 2 = **236 « Grande Portion » 1.000000** + **237 « Cheddar Fondu » 1.000000** ; item 2 = « Frites Seules » 2.000000.
```
POST /api/admin/pos/quote  items=[{item_id:2, qty:1, item_extras:[{id:236},{id:237}]}]
→ HTTP=200  "subtotal": 4, "total_ttc": 4, "total_tax": 0.36
```
Preuve renforcée au-delà du quote : la commande live **4550** (cf. (g)) persiste les 2 upgrades dans `composition_snapshot` (`extras: [{extra_id:236, line_total:1},{extra_id:237, line_total:1}]`, `total_price: 4`) avec `fiscal_sequence_no=2184` → facturation bout-en-bout, pas seulement au devis.

### (e) promo/validate BORNEAUDIT5 → **valid:false FR** ✅ (BORNE-PROMO-01)
Promo en DB : `kiosk_promos` id=1 `BORNEAUDIT5` amount 5.00 **active=1** ; `config('kiosk.promos_redeemable') = false` (dormance config, pas data).
```
POST /api/frontend/promo/validate {"code":"BORNEAUDIT5"…} (token kiosk:order user borne KIOSK-LC-001)
→ HTTP=422  {"data":{"valid":false,…,"message":"Les codes promo ne sont pas disponibles pour le moment."}}
```

### (f) GET loyalty config → **points_per_euro=1** ✅ (barème canon)
```
GET /api/frontend/loyalty/config → HTTP=200
{"status":true,"data":{"points_per_euro":1,"points_for_1_euro_discount":100,"min_redeem_points":100,
 "tiers":[100,250,500,1000,2000],"label":"Dépensez 100 points = 1€ de remise"}}
```

### (g) Repro accrual fidélité live → **1 pt/€ exact** ✅
Flux RÉEL complet, commande préfixée `advmerge-` (idempotency keys `advmerge-store-*`/`advmerge-deliver-*`) :
1. Client fidélité re-SELECTé : user **45** `loyalty_code=REDC0001`, **loyalty_points=0**.
2. Quote → store `POST /api/admin/pos` (opérateur ultraheal-pos@) → **HTTP=201**, ordre **4550** total **4,00 €**, `loyalty_customer_code=REDC0001` **dérivé serveur** depuis customer_id, payé CASH (payment_status=5, status=7 PREPARING).
3. `POST /api/admin/pos-order/change-status/4550 {"status":13}` → **HTTP=200**, `status_name: "Livrée"`.
4. Vérifs DB :
```
orders 4550 : status=13, loyalty_points_awarded=4
users 45    : loyalty_points 0 → 4          (= round(4,00 € × 1 pt/€))
loyalty_transactions : id=17, user_id=45, loyalty_code=REDC0001, order_id=4550,
                       type=earn, points=4, balance_after=4, source_surface=pos,
                       description="Commande #1206264550"
```

## 4. Frozen-diff 15 fichiers §7 — re-exécuté : **0 ligne** ✅
```
$ git diff --stat 120597bc7..HEAD -- <les 15 chemins §7 exacts du plan T-INT.5>
(sortie vide) EXIT=0
```
Contre-vérification du claim T-INT.3bis « LOCK-W6 bit-identique » :
```
$ git diff HEAD goal/cms-gestion-2026-06-10-spine -- public/js/pos-wizard.js public/css/pos-wizard.css resources/views/admin-pos-v4.blade.php | wc -l
0
```

## 5. Chaîne NF525 — re-attestée ✅
```
$ APP_ENV=e2e php artisan fiscal:verify-chain --all
  + branch=1 CHAIN OK
SWEEP COMPLETE — CHAIN OK on every active branch (1 total)
$ SELECT COUNT(*), MAX(id) FROM audit_logs → 3726 / 3730
```
- Baseline POST du rapport intégrateur = **3724/3728** : retrouvée à l'identique AVANT mes re-jeux ; après mes re-jeux (ordre 4550 + livraison) = 3726/3730, **croissant only** (+2 = mes propres mutations légitimes). Aucun historique réécrit.

## 6. Contre-vérification ciblée du point « à contre-vérifier en priorité » (FrontendOrderService)
Le rapport rejette 2 lignes clients-next (`$calculatedDiscount += $maxDiscount; loyaltyApplied=true;`) dans le consumer déféré. Vérifié par lecture (`grep -n`) :
- Phase pending : `app/Services/FrontendOrderService.php:999-1000` applique déjà `+= $maxDiscount` / `loyaltyApplied=true` (1 seule application).
- Consumer déféré `consumePendingKioskLoyaltyRedemption()` (l.1015+) : debit post-seal + **dispatch `LoyaltyBalanceChanged` conservé l.1064** (F3-02), commentaire l.1061 documentant le rejet.
- Sémantique adossée à l'univers fidélité **118/118 vert** (re-run indépendant) + repro live (g) ci-dessus.

## 7. Bonus : smoke 6 surfaces (T-INT.5 partiel)
`/`, `/login`, `/kiosk/idle`, `/admin/pos`, `/kds`, `/admin/order-status-screen` → **6× HTTP 200** sur :8770.

---

## OBSERVATIONS (non-bloquantes)

| # | Sévérité | Constat | Preuve | Suite proposée |
|---|---|---|---|---|
| OBS-1 | **DEFER-LEVÉE** | Le motif du DEFER (df 1,1 Gi) **n'existe plus** : `df -g /` → **11 Gi libres** au moment de cet audit. G-DISK est de fait franchi. | `df -g / | tail -1` → `460 11 … 92%` | Exécuter SANS attendre : `npm run prod` → sentinelles → Vitest FULL → commit bundles (chemins explicites). J'ai lancé la PHPUnit FULL moi-même (en cours à la clôture, §2) ; toute classe touchée par les merges est déjà re-validée verte en ciblé. |
| OBS-2 | P3 (pré-existant, PAS introduit par les merges) | `PricingService` ignore **silencieusement** les extras envoyés en ints nus (`[236,237]`) : `$extra->id ?? null` → skip → quote 2,00 € au lieu de 4,00 €. Backward-compat documentée (`legacy [{id}]`), les frontends envoient des objets — mais un client mal formé serait sous-facturé sans erreur. | Quote A `item_extras:[236,237]` → total_ttc=2 ; Quote B `[{id:236},{id:237}]` → total_ttc=4 (mêmes ids, même item) | Backlog : rejeter 422 (ou normaliser) les entrées scalaires dans le payload extras. Fichier : `app/Services/Pricing/PricingService.php:170-176`. NE PAS toucher sans gate (frozen §7). |
| OBS-3 | INFO | Bundles `public/js/*` datés du 06-10 (`git log -1 -- public/js/pos-app.js` → `f6ac96dc3 2026-06-10`) = caveat intégrateur exact : ne pas faire de validation VISUELLE avant rebuild. Tous mes re-jeux sont API-level → non affectés. | commande citée | couverte par OBS-1 |

## Discipline tenue
- 0 edit source (Write limité à ce rapport) ; 0 `git add -A` ; 0 push ; 0 `--no-verify` ; 0 `composer dump-autoload`.
- df vérifié avant la suite FULL (11 Gi > 1 Go).
- Mutations exclusivement sur `foodking_e2e` (jetable) via :8770 ; tests sur `foodking_test` ; `foodking` op-DB jamais touchée ; OVH jamais touché.
- Chemin canonique `tests/Feature/KDS/` respecté (aucun run sur variante de casse).
