# AUDIT ADVERSARIAL GLOBAL — GOAL RUPTURE + CARNET + SYNC/GESTION
— 2026-07-15 · branche `pos/category-first-caisse-2026-06-23` · workflow `wf_24f8b592-8dd`

## Méthodologie
- **65 agents** (3,64 M tokens, 673 tool calls) : **7 finders read-only** par lentille
  (rupture-rbac, rupture-sync, carnet-secu, carnet-logique, sync-global, gestion-data,
  régression-caisse) × **2 réfuteurs adversariaux par finding** (consigne : réfuter avec
  preuve file:line, doute = réfuté).
- Anti-hallucination : finding sans file:line ouvert + repro = rejeté à la source.
- Résultat brut : **29 findings → 22 CONFIRMÉS (2×2 doublons inter-lentilles = 20 uniques), 7 RÉFUTÉS**.
- Détail complet (evidence + verdicts intégraux) : `audit_raw.json` (même dossier).

## Verdict d'ensemble
Les fondations existantes (pricing SSOT, NF525, outbox+rescue, storage photos privé…)
ont tenu — plusieurs attaques ont été réfutées par des gardes déjà en place. Les
défauts confirmés se concentrent sur **le code neuf de la session** (features W1-W5),
ce qui est exactement le rôle de cet audit. **Tous les P1 et P2 actionnables sont
healés + testés** (commit heal W6) ; les P3 restants sont corrigés ou documentés.

## CONFIRMÉS & HEALÉS (commit heal W6)

### P1 — bloquants persona (3 uniques)
| # | Finding | Heal |
|---|---|---|
| 1 | **Chef → panel rupture KDS mort** : GET `/api/admin/item` exigeait `canAny(items_show\|pos)` que le Chef n'a pas → 403 systématique (finding doublé par 2 lentilles) | `ItemController:38` gate élargi à `availability_toggle` + test `test_availability_toggle_grants_item_list_for_the_panel` |
| 2 | **Bouton « Rupture » caisse invisible pour POS Operator** : lignes DB `availability_toggle` avec `url=NULL` (créées avant l'ajout des defaults ; `firstOrCreate` ne backfille jamais) et matcher UI sur `p.url` | Seeder → `updateOrCreate` (convergent, backfill prouvé en DB live : url=`availability-toggle` sur les 2 guards) ; matchers POS+KDS sur **`p.name`** (stable) |
| 3 | **15 items faker « voluptatem nam » (ids 126-140) LIVE** : status actif, servis par l'API publique, listés dans le panel rupture, gonflant les compteurs | Vérifié 0 référence commande → **soft-delete des 15** + purge de la row de rupture orpheline de l'item test 142 |

### P2 healés (6)
| # | Finding | Heal |
|---|---|---|
| 4 | Bouton Rupture KDS rendu sans gate → Stuff/Waiter voyaient un cul-de-sac 403 (doublé 2 lentilles) | `v-if="canToggleAvailability"` ajouté au KDS (miroir POS, matcher name) |
| 5 | Anti-bruteforce PIN contournable : throttle 5/1 par IP + TrustProxies `*` = spoof X-Forwarded-For | Limiter nommé `daily-book-pin` : 5/min par IP **+ plafond global 15/min toutes IP** |
| 6 | Note avec montant fantôme acceptée → total jour (front) ≠ mois (back) | Validation `prohibited_if:type,note` sur amount |
| 7 | Acomptes par travailleur éclatés par casse/espaces (« karim » ≠ « Karim ») | Groupement `mb_strtolower(trim())`, affichage première casse |
| 8 | Seeder permission orphelin (jamais appelé sur fresh install) | Enregistré dans `DatabaseSeeder` après IngredientPermissionSeeder |
| 9 | Dashboard rupture ignorait les auto-86 quota (`out_of_stock`) | `whereIn('unavailable_reason', ['stock_rupture','out_of_stock'])` |

### P3 healés (4)
| # | Finding | Heal |
|---|---|---|
| 10 | Photo facture collée à la mauvaise entrée après switch de type | watch `form.type` → purge photoFile |
| 11 | Échec suppression avalé (dont 401 session expirée) | 401 → relock ; autre → message visible |
| 12 | `entry_date` non bornée → mois fantômes | bornes `2024-01-01` ↔ demain |
| 13 | PIN défaut '2468' commité (2 findings) | `.env.example` : `DAILY_BOOK_PIN=change-me-please` + commentaire prod — **le choix du PIN réel reste GATE OWNER G2** |

## CONFIRMÉS — documentés / différés (owner ou V1.0.X)
| Sév | Finding | Position |
|---|---|---|
| P3 | Grant web-guard no-op pour POS Operator/Chef (ces rôles n'existent qu'en sanctum) | Sans impact : le SPA admin s'authentifie sanctum ; le seeder reste no-op inoffensif. Documenté |
| P3 | Borne sans WebSocket : rupture visible après ~6 min max (cache FE 5 min + BE 60 s) | Acceptable V1 (soketi actif en prod = quasi-instantané) ; backlog : polling version menu |
| P3 | Panel 86 sans abonnement broadcast : 2 panels ouverts en même temps divergent jusqu'au refetch (ouverture/↻) | Accepté V1 (refetch à chaque ouverture) ; backlog V1.0.X |
| P3 | `ItemService::destroy` ne purge pas `item_branch_availability`/`StockLevel` (rows orphelines) | Backlog V1.0.X (touche un service partagé) ; debris actuel purgé |
| P2 | Panel liste les items techniques (« Frites Seules », « Boisson Seule »…) | **Assumé** : la cuisine doit pouvoir 86 « Frites Seules » (= plus de frites). Pas un défaut |

## RÉFUTÉS (7) — fausses alertes tuées par les réfuteurs
1. ~~Rupture extra/variation pas appliquée au checkout~~ — gardes checkout réelles prouvées.
2. ~~Panel admin branch_id=0 fan-out incontrôlé~~ — comportement scope contrôleur correct.
3. ~~Photos factures servies sans PIN via /storage~~ — Spatie ici ne publie pas d'URL énumérable publique (vérifié).
4. ~~Chef sans availability_toggle voit le bouton KDS~~ — doublon mal posé (le vrai cas = Stuff/Waiter, healé #4).
5. ~~17 DomainEvent outbox « invisibles sans alerte »~~ — `OutboxRescueCommand` re-queue attempts<5 + `MonitorOutboxStaleness` compte dead-letters (NUIT-A 2026-07-03).
6. ~~i18n availability.* manquante ar/bn/de~~ — fallback i18n couvre (locale FR-lock de toute façon).
7. ~~atp-overlay z-index 1200 sous la pile POS V5~~ — pas de chevauchement réel reproductible.

## Gates post-heal
- PHPUnit : Availability 13/13 · DailyBook 11/11 · Stock 64 (4 skips baseline) · Menu 99 (10 skips baseline)
- Bundles : `webpack compiled successfully`
- Frozen-diff plage GOAL : **0 ligne** · NF525 : `fiscal:verify-chain --all` = CHAIN OK 4 branches (vérifié en W7)
