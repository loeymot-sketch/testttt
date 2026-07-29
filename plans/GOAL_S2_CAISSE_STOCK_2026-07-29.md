# GOAL S2 — CAISSE + GESTION DE STOCK : SÉRIEUX ABSOLU (2026-07-29)

> Tu es le LEAD CAISSE & STOCK. Lis `plans/DISCIPLINE_MULTI_SESSIONS_2026-07-29.md`
> D'ABORD. Mission : la caisse = l'outil de travail quotidien du caissier — zéro
> friction, zéro page cassée, gestion (stock/catalogue/historiques) impeccable
> depuis UNE interface claire. Convergence DISCIPLINE §6, autonomie §7.

## Ownership (tes chemins)
- `resources/js/components/admin/pos/**` (⚠️ FROZEN : PaymentComponent.vue,
  v5/PosV5TrancheRow.vue → LOCK) · `encaissement/**` · `orderHistory/**`
- POS vanilla : `public/js/pos-wizard.js` + `public/css/pos-wizard.css` +
  `resources/views/admin-pos-v4.blade.php` = FROZEN (LOCK obligatoire, data d'abord)
- Stock/BOM : `resources/js/components/admin/stock/**`, `app/Services/Stock*`,
  `app/Services/RawMaterial*`, `app/Services/Purchase*`, purchasing scan/écrans,
  `/m` mobile (`MobileStockController`, `mobile-stock.blade.php`)
- Catalogue admin : items/composer éditeur (`ProductComposerEditor*`), catalog-hub
- `tests/Feature/Pos*|Stock*|Purchas*|Encaissement*`, `tests/js` pos*/stock* ·
  rapports `reports/goal-s2-caisse-stock/`

## État connu (anchors)
- CaisseSecondaryNav posée (Encaissement·Suivi·Historique·Écran client) `d7c8e777`.
- Tickets : client À LA DEMANDE (flag OFF), cuisine auto, réimpression suivi.
- BOM opérationnel : RawMaterial/recettes (steak 75 g, cheddar pièce, sauce 25 g),
  conso à la commande + reverse à l'annulation, achats P3a-d (scan IA mock/OpenAI),
  vue Conso&Stock. `/m` PIN 2580 : quantités, couper ingrédient/produit.
- Wizard caisse : tuiles viandes unifiées (supplément 2,50 tuile), grandes images.

## Vagues
### V1 — Cartographie caisse totale + captures
TOUTES les pages admin de la caisse (login PIN, POS, wizard 4 archétypes,
encaissement, suivi/tracker, historique+filtres, écran client, stock rupture,
conso&stock, achats/scan, catalogue, /m mobile, carnet PIN) — y compris pages
CACHÉES/indirectes (routes du router pos-app.js + admin non lié dans la nav).
Chaque bouton cliqué, chaque état capturé + Read. `V1-SURFACES.md`.
Acceptance : registre 100 %, boutons morts/labels bruts = findings.

### V2 — Flux caissier sous pression (logique)
Scénarios réels chronométrés : 5 commandes enchaînées, encaisser+rendre monnaie,
annulation avec motif, remboursement, commande borne à encaisser, commande web
à accepter/refuser, parking/reprise, split. Agents logique + RED sur chaque
règle d'argent (arrondis, TVA 10 %, tiroir). Acceptance : matrice money-path
au centime + temps par opération + tests régression.

### V3 — Stock & BOM fiabilité
Décrément réel par vente (borne+caisse+web), reverse à l'annulation, rupture 86
propagée partout (<2 s), inventaire /m, achats→stock (validation doc), food cost
juste. Chasse aux doublons de logique stock (DISCIPLINE §9). E2e : vendre → stock
baisse → annuler → stock remonte, prouvé en DB. Acceptance : cycle complet prouvé
×3 archétypes + zéro écart compteur.

### V4 — Gestion UNE interface (mandat owner)
Depuis la caisse, TOUT se gère sans changer d'outil : stock, temps de préparation
(voir S4 contrat), commandes (en cours/prête/servie), historiques (passées ET
annulées, filtres), tickets. Audit navigation : ≤2 clics vers toute action
fréquente. Acceptance : parcours gestion complets capturés + navigation mesurée.

### V5 — Convergence
Suites Pos|Stock|Purchas complètes + vitest + e2e caisse réels + adversarial
final 2 cycles propres. Deploy §3 + BRAIN + memory.

## Notes
Le wizard caisse doit RESTER miroir de la borne (S1 = référence UX) — divergence
UX = finding. Les événements/synchro temps réel appartiennent à S3 : tu CONSOMMES.
