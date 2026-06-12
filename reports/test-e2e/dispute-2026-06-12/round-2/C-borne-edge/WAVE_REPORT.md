# VAGUE C — BORNE EDGE — Round 2 (post-heal) — rapport incrémental

Date: 2026-06-12 · App :8768 · DB foodking_e2e · Viewport 1080×1920 · chromium channel:chrome fr-FR
Quartets: PNG + DOM CORRIGÉ (`#app` outerHTML, slice fin 120KB) + console + network(≥400) — préfixe `c1x-*`/`c2x-*`…
Scripts: `tests/e2e/_d2-C-lib.mjs` + `_d2-C-1x/2x/3x/4x/5x/6x-*.mjs`.

## État DB au démarrage
- `kiosk_promos` #1 BORNEAUDIT5 amount 5,00 € min_cart 0 uses_count=0 active=1
- Loyalty: user 44 « Victim Secret » VICT1234 phone 0612345678 — `users.loyalty_points=165`
- Seeder ADV-F-P1-2 appliqué: items 1/2/3 → catégorie 27 « Technique (interne — upsell) » `channels=["admin"]`, is_featured=10 (off)
- Bundle `public/js/app.js` du 2026-06-12 13:07 (post-heals) — heals frontend ACTIFS dans le bundle servi.
- max(orders.id)=4514 au boot de la vague (agents parallèles actifs sur la même DB).

---

## ÉTAT C10 — HEAL C-RED-01 (promo facturée) + C-ADV-06 (copy paiement) — ✅ CONFIRMÉS

Flux: idle → À emporter → Tacos(26) wizard complet (Poulet mariné, Algérienne, menu complet Coca 33cl) → panier → BORNEAUDIT5 → checkout → loyalty skip → upsell skip → payment → confirm → cash-instruction.

Quartets: `c10-01-cart-before-promo`, `c10-02-cart-promo-applied`, `c10-03-payment-promo`, `c10-04-cash-instruction`.

**Intégrité chiffre par chiffre (C-RED-01)** :
| Étape | Sous-total | Remise | Total |
|---|---|---|---|
| Panier avant promo (écran) | 11,50 € | — | 11,50 € |
| Panier après promo (écran, PNG lu) | 11,50 € | **−5,00 €** (ligne « Code promo BORNEAUDIT5 » + bannière verte « appliqué (−5,00 €) » + « Retirer le code ») | **6,50 €** |
| Payment (écran) | | | TOTAL À RÉGLER : **6,50 €** |
| Cash-instruction (écran) | | | Montant à régler **6,50 €**, n° **#A0004** |
| API POST /frontend/order → 201 | 11.5 | 5 | 6.5 |
| **DB order 4515** | 11.500000 | **5.000000** | **6.500000** (status 4, queue A0004) |
| `kiosk_promos.uses_count` | | | **0 → 1** ✅ |

→ **C-RED-01 HEALED CONFIRMÉ** : la remise affichée est FACTURÉE (écran = API = DB au centime) et la promo est consommée. (Round 1 : commande créée PLEIN TARIF, uses_count jamais incrémenté.)

**C-ADV-06 HEALED CONFIRMÉ** : copy cash-instruction lue sur PNG = « Réglez à la caisse — espèces, carte ou ticket restaurant. » (plus de « espèces uniquement »).

**Bonus observé (heals H2 D-003/ADV-F-P1-1 visibles)** : écran payment Plan B a un bouton « Retour au panier » (échappatoire) ; cash-instruction a un CTA « RETOUR À L'ACCUEIL » + countdown « Retour à l'accueil dans 41 s ».

**Console/network** : 401 one-shot sur `promo/validate` et `order/quote` puis succès transparent (recovery par l'intercepteur auto-relogin — la guerre de révocation de token entre agents parallèles partageant le même compte machine déclenche ces 401 ; UX intacte, promo appliquée à l'essai 1, commande 201). Pattern « 401 one-shot » = gate connue, non recomptée. `c10-04` console+network = vides.

---

## ÉTAT C11 — HEAL C-RED-02 (rachat fidélité) — ❌ RÉFUTÉ pour les clients réels (NOUVEAU P0 : C-RED-02-R2)

Trois runs (c11 / c11b / c11c), client fidélité user 44 « Victim Secret » VICT1234, 165 pts. Quartets: `c11-01..05`, `c11b-01..04`, `c11c-01..02` + trace API complète `_c11c-trace.json`.

**Symptôme reproduit 2× (dont 1 run SANS aucun 401, trace réseau propre)** :
| Étape | Affiché | Réel |
|---|---|---|
| Écran fidélité | « 165 points disponibles = 1,65 € de réduction » → « Réduction appliquée ! −1,65 € » | — |
| Payment | TOTAL À RÉGLER : **4,85 €** | — |
| Cash-instruction | **6,50 €** (vérité serveur) | — |
| DB order 4524/4529 | — | discount **0.000000**, total **6.500000** |
| Points user 44 | — | **165 → 165 (jamais débités)**, 0 row ledger |

**Root cause PROUVÉE par trace payload (`_c11c-trace.json`)** : le frontend (wire-up H2 OK, `kioskCart.js:183`) envoie bien `"loyalty_code":"VICT1234","loyalty_redeem_discount":1.65` sur le quote ET l'order — mais la **réponse du quote renvoie déjà `discount:0`**. Le refus est server-side :
- `app/Services/Order/OrderQuoteService.php:271-273` — `User::where('loyalty_code',...)->where('status', 1)` ;
- `app/Services/FrontendOrderService.php:935-937` — même gate miroir.
Or `Status::ACTIVE = 5` (`app/Enums/Status.php:7`) et les clients réels (seed + créés caisse) ont `users.status=5` (user 44 vérifié status=5). Le lookup rate → retour silencieux plein tarif. **Piège historique documenté DANS le code** : `LoyaltyController.php:100-104` a déjà été corrigé pour ce même bug (« Accept BOTH legacy status 1 AND Status::ACTIVE (5) — the prior == 1 gate 404'd caisse-created customers ») — le heal H1 C-RED-02 (`00dcbffda`) a réintroduit la variante `status=1` dans les 2 moteurs. Seuls les clients inscrits PAR LA BORNE (`LoyaltyController::register` pose `status=1`) bénéficient du heal — ses tests PHPUnit passent car les factories créent des users status=1.
- Server :8768 provenance vérifiée : PID 38797 cwd = CE worktree (`.../release-v1-2026-06-10/public`).
- Conséquence client : promesse écran −1,65 € non tenue (payment 4,85 € / encaissement réel 6,50 €) — même classe d'impact que le C-RED-02 round-1, root cause RELOCALISÉE du frontend (réparé) vers la gate backend.
- Verdict : **C-RED-02 PARTIELLEMENT HEALÉ — RÉFUTÉ pour tout client status=5** → fix 1-ligne ×2 attendu (`whereIn('status', [1, Status::ACTIVE])` parité LoyaltyController).

## ÉTAT C12 — preuve d'isolement + STACK promo+fidélité — ✅ MÉCANIQUE DU HEAL CONFIRMÉE (gate seule en cause)

Mutation test documentée (DB jetable) : `UPDATE users SET status=1 WHERE id=44` AVANT le flux, **restauré à 5 après**. Quartets `c12-01..04` + `_c12-trace.json`.

**Intégrité chiffre par chiffre (stack sur la MÊME commande)** :
| Étape | Sous-total | Fidélité | Promo | Total |
|---|---|---|---|---|
| Panier (PNG lu : 2 lignes distinctes « Réduction fidélité » + « Code promo BORNEAUDIT5 » + 2 bannières) | 11,50 € | −1,65 € | −5,00 € | **4,85 €** |
| Payment (écran) | | | | **4,85 €** |
| Cash-instruction (PNG lu) | | | | **#A0019 — 4,85 €** |
| API 201 order 4532 | 11.5 | | | discount 6.65 / total 4.85 |
| **DB order 4532** | 11.500000 | | | **discount 6.650000 / total 4.850000** |
| Points user 44 | | **165 → 0**, ledger row `redeem −165 balance_after=0 order_id=4532 source_surface=kiosk` | | |
| `uses_count` promo | | | **3 → 4** (+1, le 1→3 = consommations des vagues parallèles) | |

→ La mécanique backend du heal (facturation, débit différé post-seal, ledger lié, stacking, total TTC) **fonctionne** dès que le lookup user passe. Le SEUL blocker est la gate `status=1`. (c11c run propre sans 401 → l'échec n'est PAS la guerre de tokens.)

**C-ADV-01 exercé en passant (écran fidélité)** : throttle atteint en burst → message inline FR « Trop de tentatives, patientez quelques secondes. » (plus de « Too Many Attempts. » brut) — vu live essai 1 c12. La vérif dédiée champ PROMO suit (état C60).

---

## ÉTAT C20 — ADV-F-P1-2 (SKU techniques hors grille) — ✅ GRILLE CONFIRMÉE / ⚠️ NOUVEAU P1 : écran upsell post-panier MORT (auto-skip permanent)

Quartets: `c20-01-grille-sandwich-cayenne`, `c20-02-cart-sandwich`, `c20-03-no-upsell` + `_c20-grid.json` + `_c20-kiosk-upsell-api.json`.

**Volet grille — CONFIRMÉ** :
- Grille « Sandwich Cayenne » (cat=1) : seuls `kiosk-product-add-22` (Sandwich Cayenne) et `-36` (Big Cayenne) visibles. « Boisson Seule » / « Frites Seules » / « Menu (Frites + Boisson) » ABSENTS du texte intégral `#app` ✅. Catégorie « Technique (interne — upsell) » absente de la sidebar ✅ (DB: items 1/2/3 → cat 27 `channels=["admin"]`, is_featured=10).
- **Formule menu via wizard INTACTE** (la voie orderability H1) : C10/C12 ont prouvé « Formule : Menu complet (frites + boisson) (Coca-Cola 33cl) » composée ET facturée (Tacos 11,50 € au lieu de 8,50 € base) — le véhicule item 1 reste commandable par id.

**Volet upsell écran — RÉGRESSION COLLATÉRALE DU SEEDER (nouveau, P1)** :
- Checkout panier → arrive DIRECTEMENT sur /kiosk/payment en ~2 s, l'écran upsell ne s'affiche JAMAIS.
- Mécanisme prouvé : `KioskUpsellComponent.vue:166-170` (frozen, observé seulement) auto-skip `no_suggestions` quand `fetchUpsellItems` rend 0 ; `kioskCart.js:908-918` appelle `GET /api/frontend/item/kiosk-upsell` ; **réponse live capturée = `{"data":[]}`** (`_c20-kiosk-upsell-api.json`).
- Root cause DATA prouvée SQL : pool upsell = items `status=5 AND deleted_at IS NULL AND (is_upsell=5 OR is_featured=5) AND category.kiosk_upsell_include=1` → **COUNT = 0**. Tous les `is_featured=5` restants sont SOFT-DELETED (Suppléments 4-11 deleted 2026-05-28, junk E2E 76-83) ; les SEULS featured vivants étaient les 3 véhicules — `HideUpsellVehicleItemsFromGridSeeder` les a défeaturés (`is_featured=10`) → pool zéro → la surface merchandising borne est morte en silence (`ItemController.php:78-102` kioskUpsell).
- Round-1 (`c1e-05-upsell`, AVANT seeder) affichait bien l'écran upsell — la régression est post-heal.
- Piste fix (non appliquée, lecture seule) : flagger `is_upsell=5` des VRAIS produits add-on (desserts/boissons) ou réintroduire les véhicules dans le pool via `is_upsell` (le pool est indépendant de la grille). DATA-only, zéro frozen.
