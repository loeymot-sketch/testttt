# ULTRA A→Z — Wave 4 : SYSTÈMES STANDALONE (web + mobile) — 2026-07-04
**Goal** (Stop-hook A→Z : couvrir TOUS les systèmes) : web standalone (`/Users/1millnonstop/Downloads/web`,
repo séparé) + mobile RN (`testttt/mobile`), tous deux STANDALONE no-wireup V1 (§3bis). Focus = **parité SSOT
menu vs DB canonical + produits inventés + palette + logique prix** (aucun impact live caisse/borne). Workflow
8 agents. HEAD `e3517b84a`.

## 1. RÉSULTAT — les 2 systèmes standalone sont VALIDÉS SAINS. 4 confirmés (tous P3 hygiène), 2 réfutés.
Le contrôle le plus important (§3bis anti-dérive : **0 produit inventé, prix SSOT-exacts**) PASSE sur les deux.

### Verdict parité (le point critique)
- **web-standalone** : « système très propre. PARITÉ SSOT MENU = EXACTE. J'ai diffé les 31 produits de `data/menu.js` vs la DB (48 items status=5) : **0 produit inventé**, tous les prix concordent (Cayenne 7,40 / Suprême 7,00 / Méga 8,00 / Terminator 9,00 / Tacos M 6,90 L 7,90 / Bols 7,90 …), 9 catégories 1:1, livraison identique à `DeliveryFeeService`, palette noir/orange/jaune/blanc, 0 raw label. »
- **mobile-standalone** : « Verdict SAIN. PARITÉ SSOT SOLIDE — 31 produits = EXACTEMENT la DB, **0 produit inventé, 0 catégorie inventée**. PALETTE conforme au mandat (**0 occurrence de `#F4501E`** rouge Cayenne ; orange #FF5A1F, jaune #FFD93D). CALCUL PRIX panier correct (buildLineItem→computeTotal). »

## 2. CONFIRMÉS (4 × P3 — hygiène/copy, 0 impact live, fix fourni)
| Système | Fichier | Le défaut | Fix |
|---|---|---|---|
| web (repo séparé) | `screens.jsx:156` | **Bannière « offre du jour » : « Cayenne + Menu à 9,00 € » (barré 10,00 €) jamais honorée** — le vrai prix est 9,90 € (Cayenne 7,40 + Menu 2,50) et le CTA ouvre le wizard standard sans remise. + faux compte à rebours statique. Le client est facturé le bon prix (SSOT backend) → **fausse pub, pas surfacturation**. | Corriger la copie à 9,90 € + retirer le faux barré/compte à rebours, OU câbler un vrai coupon 0,90 €. |
| mobile | `screens-item-steps.jsx:387` | **« Sans Sauce » promis dans le hint mais jamais rendu** (branche exclusivité morte `s-sans`, sauce = étape requise). Toutes sauces @0€ → même prix. | Ajouter une carte « Sans Sauce », OU retirer « (ou Sans Sauce) » du hint + la branche morte. |
| mobile | `screens-item-steps.jsx:113` | **Perso Bols : code mort** (`has_bol_wizard` jamais true, bols routés vers template tacos) + ids inexistants (`sb-boule-gratinee`→`sb-gratine`) + `SUPPLEMENTS_BOLS` resté à 5 alors que le web est passé à 9 → **Bol Riz mobile ne reçoit pas « Option Gratiné »** (+2€) que la DB+web proposent. Bols facturés correctement via le pool générique. | Décision : compléter le wizard bol (has_bol_wizard + 9 supp + ids + gratiné riz-only, miroir web) OU supprimer le code mort + corriger le commentaire. |
| mobile | `screens-item-steps.jsx:781` | **Commentaire + fallback prix formule périmés** : « DB caisse = 3,00€ » et `: 3` alors que le SSOT actuel = **2,50€** (owner l'a baissé 2026-06-23 ; `config/menu.php:745` garde le legacy 3,00 stale). Affiché correct (2,50€, fallback inatteignable) → **footgun latent** (un futur dev pourrait « corriger » à 3,00 sur la foi du commentaire). | Commentaire → 2,50€, fallback `: 3` → `: 2.50`. |

## 3. RÉFUTÉS (2)
- web sauce +0,50€/extra « branche morte » → **délibéré/documenté** (UI max=1, garde de sécurité), le fix proposé introduirait une régression. NONE.
- mobile « Viande supplémentaire +2,50€ absente » vs web/DB → divergence de **miroir** (le mobile est resté à une version antérieure sur 4 points : extra-viande, choix pain, sauces bol, 9 suppléments) ; le web-sync 2026-06-26 a avancé, pas le mobile. Lag de parité mirror, pas un défaut de prix visible client → non-bloquant.

## 4. DÉCISION — DOCUMENTÉ (pas healé)
Les 4 findings sont **P3 hygiène/copy sur des prototypes STANDALONE sans aucun impact live** sur l'opération V1 LOCAL
(borne+caisse+KDS+OSS = backend testttt). Les contrôles critiques (§3bis : produits inventés, prix SSOT, palette)
sont **propres**. Healer ces cosmétiques standalone est disproportionné vs leur valeur (0 impact restaurant). Fixes
fournis ci-dessus si l'owner priorise le polish des standalone (le mobile a un simple **lag de mirror** vs le web-sync).

## 5. GATES
- 2 systèmes standalone audités, 0 modification (audit read-only, no-wireup respecté). 0 produit inventé, 0 prix faux facturé, palette conforme.
