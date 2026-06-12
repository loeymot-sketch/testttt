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

## Vague abuse #14 — EXÉCUTÉE (voir `ABUSE_RESULTS.md`, 18/18 OK, 0 FAIL produit)
Couverture : offers, ingredients (read-only by design), dining-tables, employees, chefs,
messages, subscribers, push (garde confirm prouvée code, envoi non déclenché), transactions
(filtre date prouvé au niveau requête), abuse coupons 4/4 (doublon→422 FR, remise −5→422,
dates inversées→422, cleanup 0 fantôme), historique détail cohérent.

### Findings additionnels (vague #14)
| ID | Sév. | Finding | Statut |
|---|---|---|---|
| AB14-01 | P2 | Module Offres désactivé V1 (403 guard backend intentionnel) mais l'UI exposait le drawer complet et **crashait sur le 403** (TypeError reading 'name') → zéro feedback après saisie complète | ✅ **HEALED** `9d415b8db` — catch blindé (Offer + Coupon), toast FR du message serveur ; vérif visuelle `07-offers-403-toast.png` |
| AB14-02 | P3 | Horodatage AM/PM sur UI FR (Messages, Transactions) | Root-cause = `TIME_FORMAT="h:i A"` dans `.env` (AppLibrary::datetime). **Data-op owner** sur le `.env` opérant (1 ligne → `H:i`) ; prouvé 24h sur `.env.e2e` (`07-transactions-24h.png`) |
| AB14-03 | P3 | Messages validator hardcodés EN (CouponRequest ×7, OfferRequest ×4) | ✅ **HEALED** `9d415b8db` — 11 messages FR ; vérif `07-coupon-validation-fr.png` |
| PS-01 | P3 | Attributs validation non localisés | ✅ **HEALED** `9d415b8db` — 12 alias FR ajoutés `lang/fr/validation.php` |
| PS-02 | P3 | Astérisque IMAGE mensonger | ✅ **HEALED** `9d415b8db` |

### Preuve L2 bonus (producteur→outbox, live e2e)
`LoyaltyBalanceChanged::dispatch(1,1,142,17,'earn')` → row `domain_events`
`channel=["private-branch.1"]`, `broadcast_as=LoyaltyBalanceChanged`,
`payload={delta:17,reason:earn,user_id:1,branch_id:1,balance_after:142}` (sans PII).
Côté consommateur : Vitest `posLoyaltyLiveBalance.spec.js` 4/4 (abonnement, dégradation, refresh, unsubscribe).

### Verdict final : **GREEN** — 0 P0/P1 ; 1 P2 + 3 P3 healés même session ; 1 data-op owner documenté (TIME_FORMAT).
### Hors scope produit : volume disque 100% (29 Go = 30 worktrees `.claude/worktrees/`) — ménage à arbitrer owner.
