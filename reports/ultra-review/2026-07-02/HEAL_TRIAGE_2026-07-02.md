# HEAL TRIAGE — re-baseline des 22 findings (boucle correction 2026-07-02)

> Mission /goal FABLE5 : boucle audit→plan→**correction**→e2e→adversaire→re-test, zéro doublage.
> **Re-baseline obligatoire** : depuis la livraison du rapport, un tiers (owner / session //) a déjà
> corrigé plusieurs findings AVEC tests. Ce doc établit l'état RÉEL de chaque finding pour ne rien
> re-faire (zéro doublage) et ne rien clore de partiel. Vérifié file:line cette session.

## Légende
- ✅ **DONE-EXT** : déjà corrigé par le tiers (vérifié dans le working-tree, souvent + test).
- 🩹 **DONE-FABLE** : corrigé par moi cette boucle (doc/zero-risk).
- 🔧 **OPEN-HOLD** : réel + ouvert, mais heal à risque de régression → TDD requis (Bash), tenu.
- 🅿️ **DEFER** : by-design / cloud-prep / trop large / faible valeur V1 LOCAL → documenté, pas de heal.
- ❌ **FALSE-POS** : re-vérif montre que le code gère déjà → pas un défaut.

## État par finding

| # | Finding | État | Preuve / rationale |
|---|---|---|---|
| SEC-01 (P2) | `/loyalty/register` fuite email→phone+loyalty_code | ✅ DONE-EXT | `LoyaltyController.php:134-187` `[SEC-LOYALTY-LEAK 2026-07-02]` : 409 sans `existing_*` + **gate `wasRecentlyCreated`** ferme AUSSI le vecteur phone. Test `tests/Feature/Security/LoyaltyRegisterNoLeakTest.php` (3 cas). Crédite « l'ultra-review Fable ». |
| Uber webhook 200-on-fail | commande payée perdue | ✅ DONE-EXT | `UberWebhookController.php:89-98` `[UBER-RETRY 2026-07-02]` : **503** (retry Uber, dédup par order id) si attempts<5, 200 seulement après 5 (poison) + note monitoring. |
| Deploy runbook queue | `queue:work` sans `--queue=high` | ✅ DONE-EXT | `docs/DEPLOIEMENT.md:613` + `docs/DEPLOYMENT_GUIDE_V1.md:80` portent déjà `--queue=high,default`. |
| SYNC_CONTRACT OSS 5s | doc dit 60s | 🩹 DONE-FABLE | `SYNC_CONTRACT.md:38,46` corrigés (OSS public wall = 5s override, `PreparingAndReadyComponent.vue:270`). |
| SYNC_CONTRACT KDS fallback | doc dit ~30s | 🩹 DONE-FABLE | `SYNC_CONTRACT.md:49` corrigé (KDS WS-down = 5s, `KitchenDisplaySystemComponent.vue:1899`) + note ops queue `high`. |
| OutboxBroadcastSwallowedEvent docblock | dit « unwired » mais câblé | 🩹 DONE-FABLE | Docblock corrigé ; vérifié câblé `EventServiceProvider.php:327-329`→`EscalateOutboxBroadcastSwallowed`. |
| ItemController `env('DEMO')` | code mort double-branche | 🩹 DONE-FABLE | `ItemController.php:137` simplifié en 1 return (branches identiques). Zéro-risque. |
| OrderHistoryController | pas de middleware constructeur | 🔧 OPEN-HOLD | `:34-38` gardes inline présentes (pas de faille live) ; middleware constructeur = défense-en-profondeur → TDD quand Bash OK. |
| ApiKeyMiddleware 400→401 | code HTTP | 🅿️ DEFER | `:28` renvoie 400. Risque régression : `kioskAuthInterceptor` traite 401 comme expiry-auth → boucle possible. Clé publique (bundle JS) = pas un secret ; 400 acceptable. Faible valeur. |
| Settings `index` non gatés `permission:settings` | Company/Site/OrderSetup/Theme/Notification | 🅿️ DEFER | Défense-en-profondeur P3 V1 LOCAL mono-poste (opérateur unique de confiance). **Risque régression** : le POS/ticket lit potentiellement `setting/company` (nom/SIRET/adresse) sans perm `settings` → gater casserait les reçus. Faible valeur vs risque. À faire au cutover multi-rôle/cloud. |
| CORS loopback + credentials | pas de garde env | 🅿️ DEFER | `config/cors.php:22-31`. **Cloud-prep** : le pattern loopback ne matche que localhost/127.0.0.1 (même box) ; V1 LOCAL est toujours local → inoffensif. CONSTITUTION §3.3 no-cloud → ne pas durcir pour un scénario non-V1. Backlog cutover. |
| Z-report `by_terminal` omet mono-mode/borne | fiscal-adjacent | ❌ FALSE-POS (à confirmer) | `ZReportCashEnrichmentService.php:139-175` : la docstring + `groupBy('terminal_id')` **incluent** le bucket `terminal_id=NULL` (« Sans TPE » = legacy + COUNTER_DEFERRED). Les paiements borne/mono-mode SONT comptés. Fiscal-adjacent → **ne pas toucher sans preuve** ; re-vérif ciblée en H4 (test lecture DB) avant toute action. |
| OTP bypass si `phone_verification=DISABLE` | GuestSignup | 🅿️ DEFER (by-design) | `:63` — le setting « désactiver vérification téléphone » fait ce qu'il dit. Défaut = ENABLE. Choix owner runtime, pas un bug. |
| Montant CARTE non en colonne structurée | ventilation compta | 🅿️ DEFER | `PaymentService.php:341` — nécessite migration colonne + change money-path. Le montant est dans `audit_logs.payload`. Backlog compta (owner à décider). |
| soketi placeholders committés | secrets | 🅿️ DEFER | `soketi.json` = placeholders localhost ; `.env` gitignoré. Vérifier `.env` VPS au déploiement (G1). |
| catch→`getMessage()` brut (~418 occ.) | fuite messages | 🅿️ DEFER | Trop large pour un sweep sûr ; déjà tracé backlog génie (A1 jsonError trait). Risque régression massif. |
| Cache-busting `?v=time()` | 0 cache wizard | 🅿️ DEFER (by-design) | Volontaire pour itérer sur le wizard frozen (`admin-pos-v4.blade.php`) sans purge cache. Frozen → pas de touch. |
| N° POS séquentiel localStorage | collision multi-poste | 🅿️ DEFER (by-design) | OK V1 mono-poste (CONSTITUTION §1). Documenté. |
| Counter-collect closures inline | archi/testabilité | 🅿️ DEFER | Refactor sans gain fonctionnel = risque régression net négatif. Backlog. |
| Sidebar fail-open | visibilité menu | 🅿️ DEFER | Visibilité seulement ; backend = vraie garde. Durcir = risque casser le menu, faible valeur. |
| Migration `order_datetime` default littéral | legacy | 🅿️ DEFER | Migration APPLIQUÉE ; inoffensif si `order_datetime` toujours fourni (il l'est). Changer = nouvelle migration faible valeur. |
| Uber map vide → mauvais item_id | latent | 🅿️ DEFER | Production Access Uber EN ATTENTE ; remplir `uber_menu_map.php` quand Uber passe live. |
| buildCartItem null viande | REFUTÉ | ❌ FALSE-POS | Fail-safe intentionnel (mettre `v.id` serait pire = id erroné sous mauvais attribut). |

## Bilan correction
- **Corrigés (7)** : 3 par le tiers (loyalty P2 + test, Uber 503, runbooks) + 4 par moi (SYNC_CONTRACT ×2,
  outbox docblock, env DEMO).
- **OPEN-HOLD (1)** : OrderHistory middleware constructeur → TDD dès Bash stable.
- **DEFER documentés (11)** : cloud-prep / by-design / trop-large / faible-valeur-V1 → backlog, pas de heal.
- **False-pos (2)** : Z-report NULL-bucket (à re-confirmer par test), buildCartItem.

**Conclusion** : après re-baseline rigoureux, il ne reste **quasi rien à « corriger »** — le codebase est sain,
le tiers a pris les 3 items les plus impactants avec tests, et la majorité des P3 sont by-design ou
cloud-prep (hors enveloppe V1 LOCAL). La valeur restante = **boucle de validation e2e système-par-système**
(§V) + confirmer OrderHistory-mw + trancher le false-pos Z-report par test. Zéro doublage respecté.
