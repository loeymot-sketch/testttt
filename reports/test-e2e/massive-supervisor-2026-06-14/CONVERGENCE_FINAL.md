# TEST-E2E MASSIF — Superviseur adversaire ABUSIF — VERDICT FINAL (CONVERGÉ, 4 rounds)

**Date:** 2026-06-14 · **Tree:** spine release/v1-integration · clone `foodking_2dot0:8780` (soketi+queue UP, vraie sync).
**HEAD au verdict:** `1d7e4bf16` (stock-mgmt A-012b heal) — base `e04c7b8e8` (16 heals précédents).

## Méthode
Boucle test-e2e stricte (skill) : captures LIVE visual-first → superviseur adversaire (doubter abusif) attaque chaque surface + sonde live → fix → re-test depuis le haut. Convergence = **2 rounds consécutifs P0+P1=0 avec set de findings identique**. Exclus de re-signalement : les 16 heals déjà faits + backlog documenté + artefacts clone-data.

## Déroulé des rounds
| Round | Résultat | Détail |
|---|---|---|
| 1 | Clean (0 P0/P1) | Captures gestion live, intégrité cross-surface OK — mais **passe incomplète** (n'a pas sondé le layout étroit du stock). |
| 2 | **1 P1 trouvé** | `/admin/stock/rupture` : noms produits écrasés à **~9px** (illisibles, 1 lettre) sur pane étroit. Set ≠ round 1 → pas de convergence (set-equality a tué le faux-vert). |
| — HEAL | `1d7e4bf16` | **A-012b** : cause racine tracée live (walk de la chaîne flex) = grille fixe `lg:grid-cols-3` → cartes ~191px → rangée `[image48 | nom | toggle86]` affame le nom à 9px. Fix = plancher min-width auto-fill `grid-cols-[repeat(auto-fill,minmax(230px,1fr))]` + découplage défense-en-profondeur du clamp `-webkit-box` du flex-grow. +2 guards source. |
| 3 | **Clean (0 P0/P1)** | Fix confirmé par mesure indépendante (110px, 0 clipped, 2 catégories) + balayage frais 9 surfaces, 0 nouveau P0/P1. 2 notes P2 cosmétiques. |
| 4 | **Clean (0 P0/P1) — CONVERGÉ** | Set identique à round 3 ({}). Stock re-confirmé 110px unclipped (worst-case nom long inclus), revenu cross-surface centime-exact, toggle stock live persisté backend, a11y toggle saine. |

## Heal du round 2 — A-012b (détail)
- **Surface:** `/admin/stock/rupture` (composant `StockRuptureDashboardComponent.vue` — **NON frozen**, réécriture V2).
- **Symptôme (round 2):** chaque carte produit `clientWidth=9px scrollWidth=19px clipped=true` → noms réduits à 1 lettre ("S", "B"…) toutes catégories. Un gérant ne peut plus lire quel produit il active/désactive.
- **Cause racine (empirique, walk flex live):** carte=191px (grille 3-col à 624px de pane) → div gauche `flex-1`=69px → après image 48px+gap, le nom obtient **9px**. Le heal antérieur ciblait l'ellipsis-truncation, pas cette collision de largeur.
- **Fix:** plancher de largeur de carte (auto-fill minmax 230px) → les colonnes ne se forment que si la carte reste lisible. + wrapper bloc qui porte le flex-grow (le `-webkit-box` clampe à width:100% dedans).
- **Preuve LIVE (rounds 3 & 4, mesure indépendante):** Sandwich 110px / Boissons 9 cartes toutes 110px, **0 clipped**, incl. "Coca-Cola Zero 33cl" + nom test 28 car. Toggle stock testé end-to-end (EN STOCK→RUPTURE→reload persiste→retour). Screenshot lisible 2 lignes.

## Intégrité & sécurité (re-vérifiées chaque round)
- **Numérique cross-surface (règle owner P0):** dashboard « Total ventes » = **37 072,37 €** == sales-report « Total Des Revenus » = **37 072,37 €** (centime-exact, même SSOT `scopeRealizedRevenue`). Heals netting #12/#13/#14 TIENNENT live. Ticket moyen 7,85 € = 251,21/32 (dénominateur commandes-payées = scope-diff documenté).
- **NF525:** chaîne Z gap-free + HMAC-linkée (seq 1→20, chaque prev_hash = signature précédente, genèse prev_hash=null), vérifiée round 2. Commandes non-payées = « — » (aucun seq consommé).
- **Erreurs silencieuses:** 0 erreur console finale, aucun 500 avalé. Les 401/400/403 vus en sonde cookie-only = **race d'hydratation token SPA** (l'app authentifie en Bearer, pas cookie) → tous 200 en reload propre, données correctes. NON un défaut.
- **a11y:** boutons icon-only items portent aria-label+title ; toggle stock `role="switch"`+`aria-checked`+focus-visible.

## Notes P2 (non-bloquantes, divulguées)
- **P2 KDS cosmétique:** heure de carte déborde ~23px du bord droit au viewport admin 1200px/4-col (texte lisible, non clippé ; un vrai terminal KDS tourne plus large/moins de colonnes). Pas P1.
- **P2 OSS contrat:** `/admin/order-status-screen` « Aucune commande » 2 colonnes alors que KDS a des commandes actives → cohérent avec OSS = sous-ensemble canal retrait-client (API 200, empty-state gracieux). À confirmer comme contrat ; pas de crash/erreur sync.
- (Backlog connu non re-signalé : catégorie interne dans rapport-articles, format AM/PM = TIME_FORMAT DB, order-count 2691 vs 2697 correct-by-design.)

## CONVERGENCE FORMELLE
**Rounds 3 ET 4 = P0+P1=0 avec set identique ({}).** Règle de set-equality satisfaite → **CONVERGÉ**. La boucle adversaire a fait exactement son travail : le round 2 a attrapé un P1 visuel réel que le round 1 « propre » avait raté, healé, puis re-confirmé deux fois indépendamment.

## VERDICT FINAL : spine **GREEN — CONVERGÉE** (0 P0 / 0 P1, zéro caveat bloquant)
Surfaces live propres, intégrité numérique cross-surface centime-exacte, NF525 intacte, 1 P1 visuel trouvé+healé+re-confirmé, 0 frozen touché, Vitest 2515/0. **Bilan campagne : 17 heals.** Reste = backlog réactif documenté (ultra-audit) + gates owner (G-DELIV-CASH/REFUND, WCAG, env-currency #16, G-OVH).
