# MISSION ONB-05 — RÉGLAGES SANS DÉVELOPPEUR · Rapport de mission
- GOAL : `plans/GOAL_ONB05_REGLAGES_SANS_DEVELOPPEUR_2026-08-26.md` · Index : `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md`
- État des lieux daté du **2026-08-26** (HEAD `43b120c7d`, `:8766`, base `foodking_e2e`)
- Port : **8805** · Voie : CENTRAL « réglages & visibilité du menu » — **seule session autorisée à éditer** `v1-hidden-modules.js`, `settings/MenuComponent.vue`, `BackendMenuComponent.vue` · Parallèle avec : 01, 02, 06, 07, 08, 09, 10 (vague A) — **ils lui envoient des fiches de renvoi**, ils n'éditent pas le menu.

## 0. COMMENT LANCER
```
Tu es le chef de mission du GOAL ONB-05 (réglages sans développeur, visibilité du menu). Lis : CONSTITUTION.md, PROJECT_BRAIN.md §2, SYSTEM_MAP.md,
PARALLEL_PROTOCOL.md, plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md (§2, §3, §5), reports/audit/onboarding-commercant-2026-08-26/
MISSION_ONB05_REGLAGES_SANS_DEVELOPPEUR.md, plans/GOAL_ONB05_REGLAGES_SANS_DEVELOPPEUR_2026-08-26.md, puis recon/Z2_profil_reglages.md,
recon/Z7_equipement_ops.md (§3-§4), recon/Z0_modele_catalogue_wizard_reglages.md (§C-D), recon/Z0_carte_dashboard.md (§0, §2, §4, §6).
Pré-vol §0.1 : worktree .claude/worktrees/onb05-reglages depuis HEAD, APP_URL=http://127.0.0.1:8805, .env.testing, liens durs, serveur 8805,
PLAYWRIGHT_BASE_URL, filet backup/pre-onb05 + dump de la table settings. Les réglages sont GLOBAUX : note chaque valeur avant écriture et restaure-la.
Puis « lance le GOAL » : W0 → W1 (lecture intégrale de app/Services/Pilotage/InterrupteurService.php — 166 lignes —, inventaire des réglages métier,
tableau des 22 pages, cartographie des caches) → W2..W6. Pipeline ultra-audit-profond, 5 spécialistes lecture seule en un message, implémenteur unique,
ROUGE avant tout « fini », Jalonneur §X.8, matrice §S, convergence = deux cycles identiques. Tu exposes des réglages ; tu ne modifies JAMAIS la logique
d'un consommateur d'une autre voie (caisse, borne, KDS) : fiche de renvoi. idempotency.* et fiscal.* sont inéligibles (test). Avant W4, collecte les
fiches de renvoi « dé-cacher X » écrites en §8 des rapports de mission ONB-01/02/06/09/10 et soumets le tableau G-CACHE au propriétaire.
Jamais de push. Compte rendu : FIXÉ / VÉRIFIÉ / BLOQUÉ.
```

## 1. CONTEXTE ET VISION
Le propriétaire veut qu'un nouvel établissement **contrôle chaque détail** sans développeur. Or le seul mécanisme sans déploiement compte 6 booléens et 22 des 31 sous-pages
Réglages sont cachées par une liste de circonstance. Ce GOAL est le **socle transverse** : il construit le mécanisme de réglages typés, tranche la visibilité (avec le propriétaire)
et exécute les demandes des autres GOAL. Sans lui, ONB-01/02/06/09/10 livrent des pages que personne ne voit.

## 2. ÉTAT MESURÉ LE 2026-08-26 (`recon/Z2`, `recon/Z7`, `tmp/recon/Z2/02-api.json`, `tmp/recon/Z7/api-1-results.json`)
**2.1** : 25 pages Réglages ouvertes par URL (cachées comprises) → 25/25 en 200, 0 libellé brut ; `GET /api/admin/observability/interrupteurs` → 6 entrées ; page État du système
(`/admin/observability/system`) **reliée au menu** (table `menus` id 33 « System Health » — la carte Z0 la croyait orpheline : corrigé) ; Outbox orphelin.
**2.2 Ce qui marche** : 6 interrupteurs lisibles avec description FR et conséquence ; PUT `impression_ticket_client_auto` → relu → restauré (Z7 `[40][41][46]`) ; nom hors liste → 404 ;
`pos@` → 403 sur PUT ; propagation immédiate (`order-setup`, `kiosk-setup` relus sans cache) ; validations FR des pages visibles.
**2.3 Constats**
| Sév. | Constat | Preuve |
|---|---|---|
| P1 | Page Licence : « clé de licence » = clé d'API `X-API-KEY` en clair (38 car.) | Z2 `03-captures.json license_page`, `02-api.json admin_get license` |
| P2 | Lecture de `company`, `site`, `order-setup`, `branch`, `otp`, `theme`, `interrupteurs` ouverte au POS Operator (écriture 403) | Z2 `02-api.json pos_get *` (→ ONB-13 pour la politique ; ce GOAL ferme sa propre API) |
| P2 | `settings.item-attributes` caché (`v1-hidden-modules.js:41`, `MenuComponent.vue:99`) ET réinjecté dans le menu principal (`BackendMenuComponent.vue:97`) | Z0 §6 |
| P2 | État du système : 3 alertes permanentes non actionnables (file `notifications` 1 490 messages = gelée volontairement, sauvegarde 21 j, planificateur muet 656 min) ; Outbox : 10 674 en attente, `queue:work` DOWN | Z7 capture 09/10, `/api/health` |
| P2 | PIN admin borne « 1234 » affiché par défaut, jamais imposé (`kiosk_admin_pin_set:false`) | Z7 capture 08, `[16]` |
| P2 | Pages SaaS-era ouvertes et fonctionnelles sans objet V1 (Passerelle de paiement : secrets en `type=text` ; SMS ; OTP ; Cookies ; Analytique ; Réseaux sociaux ; Bannières) | Z2 §3 |
| P3 | Site : 16 champs obligatoires à vocabulaire SaaS | Z2 `03-captures.json site_form` |
**2.4 Angles morts** : tolérance d'écart de caisse (2 € codés, permission `cash.reconcile.variance.override`, `CashDrawerService.php:153,268` lit `config('cash.variance_override_permission')`), barème
livraison (colonnes `branches`, ONB-01), seuil stock bas (`stock_levels.threshold_low`, ONB-08), mention ticket (ONB-01), heures de service, numéro de départ de file borne (`kiosk.queue_start_number` 32),
plafond quantité borne (20), fenêtre/seuil SLA (24 h / 15 min), `pos.walkin_route_to_counter` (gate propriétaire ouverte), `features.offers_enabled`, `kiosk.payment_route_all_to_counter` (plan B — ne pas exposer sans gate).
**2.5 Cayenne** : rien de spécifique à ce GOAL (les valeurs par défaut portent la marque : ONB-12).

## 3. CE QUI A DÉJÀ ÉTÉ FAIT
- 2026-08-09 mémoire `pilotage_sans_developpeur` : interrupteurs créés (2), `idempotency.enabled` exclu par principe NF525 — **ne pas « corriger » cette exclusion**.
- 2026-08-15 GOAL CONFORT MAX V5 : catalogue 2 → 6 (`InterrupteurService.php:56-65` explique le choix « booléen seulement »).
- 2026-08-10 : `settings.loyalty-setup` dé-caché (`v1-hidden-modules.js:24-32`, motif écrit) — **modèle à généraliser** (clé + motif + date).
- 2026-08-13 GOAL ADMIN NAV BREADTH : NAV1-04 (persistance des interrupteurs, `idempotency` absent de l'écran) et SET-T06 (pages orphelines) planifiés, non exécutés.
- Tests existants : `tests/Feature/Settings/{OrphanSettingsRatchetSentinelTest,SettingsUpdatedBroadcastTest,FrenchValidationMessagesAreTranslatedTest}.php` ; sentinelles Vitest `tests/js/sentinels/` (58 fichiers, à identifier celles du menu en W1).

## 4. ANCRAGES CODE
| Rôle | Fichier | Lignes | Note |
|---|---|---|---|
| Interrupteurs | `app/Services/Pilotage/InterrupteurService.php` (166 l.) | `:27-33` exclusion idempotency, `:43-90` CATALOGUE (6 `cle`), `:97-101` lecture défaut, `:114-126` `regler()`, `:153-165` `appliquerAuDemarrage()` | booléen par conception |
| Contrôleur | `app/Http/Controllers/Admin/Pilotage/InterrupteurController.php` | `:38` Admin/Tenant Admin, `:49-55` `Log::info` | routes `routes/api.php:1669-1670` |
| UI santé | `resources/js/components/admin/observability/SystemHealthComponent.vue` · `observabilityRoutes.js:26-35` | interrupteurs + 7 cartes | `menus` id 33 |
| Visibilité | `resources/js/config/v1-hidden-modules.js` | `:6-9` principe, `:11-56` 34 clés, `:24-32` motif loyalty, `:57-59` helper, `:66` urls | **possédé** |
| Menu Réglages | `resources/js/components/admin/settings/MenuComponent.vue` | `:9-139` 31 entrées, `:146` import, `:154-201` `isSettingHidden()` | **possédé** |
| Sidebar | `resources/js/components/layouts/backend/BackendMenuComponent.vue` | `:58,68-78,94-99,239-269,388,424-427` | **possédé** (visibilité uniquement) |
| Réglages métier | `config/pos.php:113-119,150-154,196-200,233-237,271-275,301-305,319` · `config/kiosk.php:16-19,31,54,70,102-106,120,127,134,343,347,348` · `config/dashboard.php:24,29` · `config/features.php:27,50` · `config/printing.php` | clés à exposer | logique = autres voies |
| Configuration commandes / borne | `OrderSetupRequest.php:26-49` (`:32-45` frais hérités) · `KioskSetupRequest.php:16-24` | | |
| Tolérance caisse | `app/Services/Cash/CashDrawerService.php:153,268` (`config('cash.variance_override_permission')`) · `config/cash.php` (à lire W1) | voie CAISSE | G-CAISSE-TOL |
| Table settings | colonnes `id, group, key, payload(json $cast/$value), settingable_*` | groupe `pilotage` | Smartisan Settings |
| Propagation | `SettingsUpdated` (`CompanyController.php:36`), `GET /api/frontend/setting` (no-cache) | mesuré immédiat | |

## 5. BASES CHIFFRÉES
`safe-test.sh --phpunit "Pilotage|Settings|OrderSetup|KioskSetup"` → figer W0 · `npx vitest run tests/js/sentinels/` (58 fichiers, compter) · interrupteurs 6/6 au défaut · menu Réglages 11/31 visibles · `settings` groupe `pilotage` 2 lignes.

## 6. DÉCISIONS PROPRIÉTAIRE EN ATTENTE
| Gate | Question | Proposition du chef de projet | Si non tranché |
|---|---|---|---|
| **G-CACHE** | Pour chacune des 22 sous-pages + 9 modules : garder visible / cacher / retirer | **Visibles** : Taxes, Catégories, Attributs, Rôle & Autorisations, Thème, Horaires/Créneaux, Pages, Coupons, Offres · **Cachés conservés** : Langues, Notification, Alerte notification, Clients, Livreurs, Serveurs, Tables, Commandes en ligne/table · **Retirés** : Licence, Passerelle de paiement, Passerelle SMS, OTP, Cookies, Analytique, Réseaux sociaux, Bannières, Rapport solde crédit | W4 bloquée ; les autres GOAL livrent des pages invisibles |
| G-NOM | Nom de la page de réglages typés | « Réglages métier » sous Configuration | T-1.2.1 bloquée |
| G-CAISSE-TOL | Exposer la tolérance d'écart de caisse (2 €) | oui, la lecture de la valeur par `CashDrawerService` est renvoyée à la voie CAISSE | témoin remplacé par un autre |
| G-DATA | Table dédiée | non (groupe `pilotage` de `settings` suffit) | — |
| G-LIC | Retirer la page Licence | oui | clé d'API reste exposée |
| G0 | Amendement constitutionnel | — | ne bloque pas |

## 7. RISQUES, PIÈGES, INSTRUMENTS
- Les réglages sont globaux et partagés entre sessions parallèles : toute écriture d'essai = valeur notée AVANT + restaurée ; ne jamais laisser `remise_manuelle` ou `kiosk_promo` à `true` après un test.
- `window.foodkingConfig` est injecté au chargement de page : un réglage consommé par le bundle exige un rechargement — le documenter, ne pas le prendre pour un bug.
- La carte Z0 s'est trompée sur « État du système orphelin » (menu en base) et sur l'URL de la page Rôles (`role`, singulier) : vérifier la table `menus` ET le routeur avant de déclarer un orphelin.
- Deux sessions sur les fichiers de menu = conflit garanti : ce GOAL est le seul ; attendre G-CACHE avant W4.
- `:8000` = autre worktree ; ta session = **:8805**.

## 8. JOURNAL DE MISSION (rempli par la session)

Audit adverse en lecture seule le 2026-08-28. **Il a corrigé deux chiffres de la
mission elle-même** — c'est exactement ce qu'on lui demandait.

### 8.1 Les chiffres, remesurés

| Question | Réponse de la mission | Réponse mesurée |
|---|---|---|
| Interrupteurs pilotables | 6 | **6** — inchangé. `InterrupteurService.php:43-90`, tous booléens. Aucun mécanisme typé (nombre/texte/horaire) n'existe |
| Sous-pages Réglages cachées | « 22 » | **19 sur 31** — le chiffre 22 n'est pas reproductible. Au HEAD de référence la liste portait **23** clés, dont 4 (`permission`, `charge`, `translation`, `activity-log`) qui **ne masquaient rien** ; retirées le 2026-08-28 avec la sentinelle `clesDeMasquageMasquentQuelqueChose.spec.js` |
| Réglages lus sans chemin d'écriture | « 45 exigent un développeur » | **~101 sur 115** clés métier lues dans `app/`. Écrivables : 6 par `InterrupteurService` + 8 par `WheelSettingsService` = **14**. Réserve honnête : ~15 des 101 sont des coupe-circuits qui ne DOIVENT pas être exposés — reste **~86** réglages d'exploitation hors d'atteinte |
| Configuration fantôme | non mesuré | **2 réelles sur 304 clés** : `app.version` (`HealthController:35`, absente de `config/app.php` → `/health` annonce toujours `"version":"dev"`) et `permission.testing` |

### 8.2 Corrigé cette nuit

| Défaut | Preuve |
|---|---|
| **Le minimum de commande de la roue était saisi puis ignoré.** `WheelSettingsService::minOrder()` n'avait qu'UN appelant ; **cinq** autres lisaient `config('wheel.min_order_amount')` en direct — dont `WheelController:332`, qui l'APPLIQUE au client. L'exploitant réglait « minimum 15 € », un écran affichait 15 €, et la roue appliquait la valeur du fichier. Les replis divergeaient même : 10 côté service, 0 côté lecteurs directs | `UnReglageEnregistreEstCeluiQuiSAppliqueTest` (5) |

Ce défaut mérite d'être nommé pour ce qu'il est : **le principe qui l'interdit est écrit
dans le même fichier, trois cents lignes plus haut.** Docblock de
`WheelService::segments()` : « lire la config en direct ailleurs, ce serait ignorer les
réglages du propriétaire sur une surface et pas sur l'autre… c'est le motif du jumeau
oublié, et il a déjà coûté deux fois ici ». Troisième fois.

### 8.3 Encore vrai

| Sév. | Constat | Preuve |
|---|---|---|
| **P1** | **Quatre pages n'ont AUCUN lien nulle part** : `admin.settings.tax`, `role`, `theme`, `timeSlot`. Référencées seulement par le menu Réglages, où elles sont masquées. **La TVA n'est atteignable qu'en tapant son URL** — or c'est le point d'entrée d'ONB-01 et ONB-02 | `MenuComponent.vue:79,83,103,111` |
| **P1** | Page Licence : le champ « clé de licence » écrit `MIX_API_KEY` en clair | `LicenseRequest.php:54`, `LicenseService.php:45` |
| P2 | **Trois codes PIN** (carnet, stock mobile, roue) ne viennent que du `.env` : changer un code d'accès exige un développeur | `AppServiceProvider:215`, `EnsureMobileStockPin:26`, `EnsureWheelAccess:58` |
| P2 | PIN admin borne « 1234 » annoncé par le libellé, **aucun consommateur serveur** | `KioskSetupRequest:22`, `fr.json:1258` |
| P2 | `item-attributes` masqué côté Réglages **et réinjecté** comme sous-item Catalogue | `v1-hidden-modules.js:36` vs `MenuComponent.vue:99` |
| P2 | Lecture des réglages : cinq contrôleurs gardent `->only('update')` alors qu'`Interrupteur` et `KioskSetup` ont été rattrapés | — |

Réglages d'exploitation les plus coûteux, sans écran : tolérance d'écart de caisse
(`cash.variance_threshold_eur`), heures de service cuisine (`kds.scheduled_window_open/_close`),
seuils d'alerte SLA, largeur et page de code des imprimantes (`printing.receipt.*` — changer
d'imprimante exige un développeur), plafond de tranches de paiement, durée de validité des lots.

### 8.4 Ce qui reste — par coût pour un commerçant seul

1. **Rendre la page Taxes atteignable.** Sans elle, un nouvel établissement ne peut pas régler sa TVA autrement qu'en tapant une URL.
2. **Trancher G-CACHE** : 19 pages masquées, dont les cinq que les fiches ONB-01/02/06/09 réclament. Rien de la vague A n'est visible tant que ce gate n'est pas signé.
3. **Construire le mécanisme typé** (nombre / texte / horaire). Sans lui, ~86 réglages d'exploitation restent hors d'atteinte : le catalogue booléen ne peut pas les accueillir.
4. **Les trois PIN** : premiers candidats du mécanisme typé.
5. **Retirer la page Licence** (G-LIC).
6. **Fermer la lecture des réglages** sur les cinq contrôleurs restants.
7. **`app.version`** : `/health` annonce « dev » en production.

**État final ONB-05 : un P1 corrigé (le réglage qui mentait) ; deux chiffres de la mission remesurés et corrigés ; l'inventaire des ~86 réglages hors d'atteinte est désormais fait, avec sa réserve honnête sur les ~15 qui ne doivent PAS être exposés. Le mécanisme typé — le cœur de cette mission — reste à construire, et G-CACHE reste au propriétaire.**
