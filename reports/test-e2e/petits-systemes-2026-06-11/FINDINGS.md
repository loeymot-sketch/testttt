# AUDIT PETITS SYSTÈMES — FINDINGS (2026-06-11)

> Reconstruit par l'orchestrateur après mort de l'agent d'audit (limite session, 156 tool-uses).
> Sources : 40+ captures PNG, `interact-report.json`, scripts `sweep*.cjs`/`coupon-crud.cjs`, lectures Read des captures clés.
> Harnais : `:8767` / `foodking_e2e` (clone jetable, JAMAIS la DB opérante), login admin@lecayenne.fr.

## Périmètre couvert (étape 04 — capture + état de chaque page)
16 pages admin secondaires capturées et chargées sans erreur visible :
coupons, offers, messages, subscribers (abonnés), push-notifications, transactions,
sales-report, items-report, ingredients, delivery-boys, delivery-boy-cash-sessions,
dining-tables, employees, administrators, chefs, customers, historique, loyalty-setup.

## Interactions profondes (étape 05 — `interact-report.json`)
| Étape | Verdict | Détail |
|---|---|---|
| coupon-create (1er essai) | ❌→✅ | 422 `POST /api/admin/coupon` — **erreur de script** (datepickers non commités, min/max non remplis). L'UI a affiché correctement les messages de validation (capture `05-coupon-create-FAIL.png`). Retries 05c/05d : **création réussie** (ligne E2EAUDIT11, toast « création réussie »). |
| coupon-delete (1er essai) | ❌→✅ | Timeout locator (la ligne n'existait pas puisque le create avait échoué). Retries : **suppression réussie** (toast vert, liste vide, `05c/05d-coupon-after-delete.png`). |
| customer-detail | ✅ | détail client OK, 0 erreur console/réseau |
| historique-detail | ✅ | commande réelle SUP-LOY-1 : Sous-total 8,50 € − Remise (fidélité) 1,00 € = Total 7,50 € — **cohérence loyalty↔historique↔DB prouvée à l'écran** (`05e-historique-real-order.png`) |
| sales-report-filter + export | ✅ | panneaux s'ouvrent, 0 erreur |
| push-notification-modal | ✅ | modal s'ouvre, 0 erreur |
| dbcs-detail (caisse livreur) | ✅ | détail session OK |
| ingredient-detail | ✅ | détail OK |

## Preuve L1 (barème fidélité seedé) — à l'écran
`04-loyalty-setup.png` : POINTS PAR € = **1**, POINTS POUR 1€ DE RÉDUCTION = **100**,
MINIMUM POUR UTILISER = **100**, aperçu live « 10€ d'achat = 10 pts → 0.10€ de réduction ».
Conforme D11 (1 pt/€ round, linéaire 100 pts = 1 €, min 100) — sentinel `LoyaltyRateParitySentinelTest` 3/3.

## Findings retenus (après tri faux-positifs)
| ID | Sév. | Finding | Preuve |
|---|---|---|---|
| PS-01 | P3 | Messages de validation coupon **mi-traduits** : « Le champ start date est obligatoire » / « minimum order » / « maximum discount » — les noms d'attributs ne sont pas localisés FR (attendu : « date de début », « montant minimum de commande »...) | `05-coupon-create-FAIL.png` |
| PS-02 | P3 | Label **IMAGE \*** marqué requis sur le drawer coupon alors que la création réussit sans image (05c/05d) — astérisque mensonger | `05-coupon-create-FAIL.png` vs `05c-coupon-after-save.png` |
| PS-03 | — | ~~coupon create/delete cassés~~ **FAUX-POSITIF** : échec du script d'audit, pas du produit. CRUD coupon prouvé fonctionnel bout-en-bout ×2 (05c, 05d) | interact-report + captures |

## Reste à couvrir (vague abuse #14)
- CRUD réels : offers, messages, ingredients, dining-tables, employees/administrators/chefs, delivery-boys
- subscribers + push (⚠️ mass-send = confirm dialog, ne PAS envoyer réellement)
- transactions : filtres + export ; items-report : filtres
- abuse inputs : valeurs limites (montants négatifs, codes dupliqués, dates inversées)
- preuve L2 live (bonus) : solde modal POS mis à jour par event sans reload
