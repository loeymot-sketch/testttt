# PLAN NEXT-BEST — après validation fidélité + micro-audit dashboard (2026-06-12)

> Sortie Wave 6 du GOAL superviseur (`plans/GOAL_SUPERVISOR_LOYALTY_VALIDATION_MICRO_2026-06-12.md`).
> Sources : 12 findings confirmés adversarialement + 28 vérifiés partiellement (refuters D tués par limite session — refuters F1/F2/F3 + D-B1-01/03/04/05 ONT tourné, rapports `refuter1-*.md`) + backlog BRAIN §2 + fragmentation branches (CONSOLIDATED_STATE 06-09).
> ⚠️ Panel adversarial 3-juges du plan = DIFFÉRÉ (limite subagents, reset 7:10) — à rejouer avant exécution massive : `valeur-resto / risque-NF525 / coût-intégration`.

## P0 — INTÉGRATION AVANT TOUT (le vrai risque n'est pas un bug, c'est la fragmentation)
| # | Action | Pourquoi | Effort |
|---|---|---|---|
| INT-1 | **Réconcilier les branches** : `heal/clients-next-2026-06-10` (fidélité+CMS, ICI, non pushée) × `release/v1-2026-06-10` (UI/UX caisse-borne + heals W4-W6, autre session) × spine `heal/pre-cloud-exec` (déployée OVH) → UNE branche ship | Chaque session améliore SA branche ; rien ne converge vers main ; le serveur OVH tourne sur une 3e lignée. Tout le reste du plan dépend d'un tronc commun | 0,5-1 j (merges + suites complètes + arbitrages collisions connus : loyalty.js, PosComponent) |
| INT-2 | Pousser le tronc réconcilié + tag `v1.0-rc` + déployer sur OVH (suivre `project_cloud_deploy_ovh_lecayenne`) | La prod réelle est OVH ; les heals fidélité (sur-remise F2-01 !) n'y sont pas | 0,5 j + gate owner push |
| INT-3 | **Seed barème OVH** : `php artisan foodking:set-loyalty-rates 1 100 100` sur la DB OVH + vérif page admin (le clone e2e était resté à 10/100/50 — la prod l'est probablement aussi) | F1-01/F4-DATA-01 : un client OVH gagnerait 10× les points promis | 5 min + gate owner D11 |

## P1 — Corrections à fort impact restaurant (vérifiées, non healées faute de session)
| # | Finding | Ancre | Effort |
|---|---|---|---|
| Q-1 | Caisses Livreur : colonnes LIVREUR=«10», FILIALE=«1» (IDs bruts au lieu des noms) — D-B3-01 | resource liste delivery-boy-cash-sessions | 30 min |
| Q-2 | online-orders : MONTANT «7.00» sans €/virgule + statut «Accepter» (infinitif) — DB2-01/02, même famille D-B1-07 (stats dashboard) | formatter FR déjà écrit (heal WT-D-R1-F4 POS) à appliquer aux listes online + clé i18n statut ≠ bouton | 45 min |
| Q-3 | Datepickers EN/12h partout (dashboard ×4, coupons, offres) — D-B1-03 (refuter CONFIRMÉ, capture calendrier 'Jun/Mo Tu We') + DB5-02 | `@vuepic/vue-datepicker` props `locale="fr"` `:is24="true"` `format="dd/MM/yyyy"` — wrapper commun à créer | 1-2 h |
| Q-4 | RGPD fidélité (F4-CONSENT-01 P2 confirmé) : register sans consentement + AUCUNE route opt-out | LoyaltyController::register + nouvelle route opt-out (LoyaltyConsent existe déjà pour kiosk) — toucher API = doc WIREUP à jour | 2-3 h |
| Q-5 | Outbox observability : `toLocaleString()` sans locale (EN/AM-PM) + purge sans confirm — DB5-03/04 | composant observability/outbox | 30 min |
| Q-6 | Settings : « IOS APPLICATION LIEN », « (€) Left », « 12 Hour » EN + bornes TypeError vue-select (DB4-F1/F2/F7/F4) ; error-state listes avalé (DB4-F5) | i18n + guard vue-select + toast erreur fetch | 1-2 h |
| Q-7 | Coupons liste : REMISE «12.00» sans unité €/% selon type — DB5-05 ; KPI «INDISPONIB» tronqué 1280px — D-B1-04 (refuter confirmé) | colonne formatée par discount_type ; CSS overflow KPI | 45 min |
| Q-8 | Dashboard « Meilleurs Clients » liste des 0-commandes — D-B1-06 ; téléphone «+330600...» concat naïve — D-B3-03 | query having + formatter téléphone FR | 1 h |

## P2 — Performance & robustesse (mesuré, pas du ressenti)
| # | Sujet | Mesure | Action |
|---|---|---|---|
| PERF-1 | Boot SPA ~3,2-4 s first-content sur TOUTES les pages admin (bundle 2,2-3,2 Mo) — DB5-01/DB2-04/D-B1-02 ; API elle-même 37-150 ms | mesures lanes D | split vendor/app + lazy plus agressif + (option) préchargement shell ; objectif <1,5 s |
| PERF-2 | `php artisan serve` mono-process = pages blanches sous charge (vécu CE goal) | PR-03 déjà écrit | appliquer `PHP_CLI_SERVER_WORKERS=4` + supervisor restart capé (plan `plans/core-bulletproof/PR-03_serve_crash_safety.md`) — sur OVH = nginx/fpm déjà OK |
| PERF-3 | Worker latence event→push ~13 s (poll multi-queues) | mesuré preuve live | `--sleep=1` ou queues dédiées broadcasts ; cible <3 s |
| ROB-1 | Sentinel intégrité routeur étendu : noms cités par :to/$router.push vs routes déclarées (le redirect cassé « page blanche silencieuse » était invisible 0 console) | routerRedirectIntegrity.spec.js à étendre | 1 h |
| ROB-2 | F1-02 asymétrie welcome (lazy-mint sans +25) + F3-03 barème non propagé live (SettingsUpdated absent de LoyaltySetupService — pattern existe ailleurs) | décision produit + 3 lignes | 30 min |

## P3 — Hygiène / data-ops owner (inchangé + nouveaux)
- `TIME_FORMAT="h:i A"`→`"H:i"` `.env` opérant (prouvé 24h e2e) — **1 ligne owner**.
- Disque Data 100% : purge des ~30 worktrees (29 Go) — owner arbitre les sessions mortes.
- notification-alert (page cachée V1) massivement EN — DB4-F6, défer V1.0.X.
- DEDUP confirmés (ne PAS refixer ici) : Google Maps DrawingManager (dashboard-deep 06-08), lots A-H release/v1.
- Révoquer les tokens Sanctum e2e de test (`goal-0612`, `curl-admin`, `f1-lane`, etc.) + retirer `.f2-token` du repo.

## Ordre recommandé (superviseur)
**INT-1/INT-2/INT-3 d'abord** (sinon chaque fix re-diverge), puis Q-1→Q-8 en 1 vague TDD+visual sur le tronc commun (≈1 j), puis PERF-1/2/3 (mesures avant/après), ROB/P3 au fil de l'eau. Panel 3-juges à rejouer sur CE document avant la vague Q (post 7:10).
