# Plan – CAISSE-SUPERVISOR-CONTROL-20260823 – 2026-08-23

## TASK_ID
CAISSE-SUPERVISOR-CONTROL-20260823

## PRIMARY_EXECUTION_MODEL
gpt-5.5-pro

## REASONING_EFFORT
xhigh

## EXECUTION_TIER
complex

## PRIOR_CONTEXT
- Graphiti MCP n'est pas chargé ; `memory/INDEX.md`, les invariants du dépôt et les preuves de tests locales font foi.
- Le noyau commande/paiement est sain ; les défauts confirmés concernent la supervision, une file locale héritée, l'accessibilité opérationnelle et la preuve E2E.
- Le cycle Wheel reste à son gate UX humain et son code est strictement hors scope.

## PLAN_REVIEW
PLAN_REVIEW_CHANNEL: foodking-complex-implementer (codex-extension-fallback)
PLAN_REVIEW_MODEL: gpt-5.5-pro
PLAN_REVIEW_REASONING_EFFORT: xhigh
PLAN_REVIEW_VERDICT: PASS

## REPLAN_1 — revue indépendante après interruption CLI (`exit 137`)

- La branche authentifiée (>0) devient une précondition du POS et est résolue une seule fois, puis transmise aux quatre sondes fiscal/stock/aging/outbox. Le cache fiscal est obligatoirement `pos_system_health_fiscal:{branchId}`.
- Une erreur de sonde produit `unknown`/dégradé avec un message actionnable ; aucune exception ne peut être convertie en `0`, liste vide ou faux vert.
- Les entrées offline V1 non signées sont **quarantainées et conservées**, jamais purgées automatiquement. Elles n'atteignent aucun POST au montage, au timer, à l'événement `online` ou au clic. Seules des entrées explicitement versionnées/signées peuvent être rejouées, avec tentatives bornées et distinction 4xx terminal/réseau.
- Les tests E2E qui créent des commandes sont autorisés uniquement sur une base identifiée comme dédiée aux tests. Aucun cleanup ne supprime d'`audit_logs`, ne remet un numéro fiscal à `null` ou ne change un `OrderStatus` directement en base ; les transitions passent par les API/services canoniques et les fixtures restent préfixées + branch-scopées.
- Les tests et fichiers ciblés sont désormais nommés explicitement ci-dessous ; aucun dossier générique n'autorise une extension silencieuse.

## REPLAN_2 — défaut de preuve découvert en validation Wave E

- Le parcours complet passe, mais `tests/e2e/helpers/kiosk-order.js` convertit `queue_number` avec `Number(...)`. Le contrat serveur courant est alphanumérique (`A0045`) : la preuve devient `NaN` et plusieurs audits perdent silencieusement l'identité de file.
- Extension bornée au helper de test uniquement : conserver `queue_number` comme chaîne opaque, mettre à jour son type documenté et ajouter une assertion Wave E qui refuse `null`, `NaN` et toute divergence entre réponse API et base. Aucun code produit, statut, paiement, prix ou service n'est modifié.
- REPLAN_2_REVIEW_CHANNEL: foodking-complex-implementer (codex-extension-fallback)
- REPLAN_2_REVIEW_VERDICT: PASS

## REPLAN_3 — activation clavier du départ borne confirmée par audit navigateur

- Le test réel sur `/kiosk/idle` montre une divergence précise : le clic du bouton « Touchez l'écran pour commander » atteint `/kiosk/categories`, mais Entrée ne déclenche aucune navigation. Le bouton accessibilité voisin utilise déjà des gestionnaires explicites Entrée/Espace, alors que les deux cartes de type de commande n'en ont pas.
- La première hypothèse visant `KioskAppComponent.vue` est rejetée par la revue : ce fichier est gelé et aucun gate ne l'autorise. Il reste strictement hors scope et inchangé.
- Extension bornée à `KioskIdleScreenComponent.vue` et à une sentinelle Vitest dédiée : donner aux deux boutons de type de commande une activation Entrée/Espace explicite, stoppée et prévenue, qui appelle exactement le même chemin que le clic. La sentinelle impose exactement un événement `start-order` par activation afin d'exclure tout double déclenchement avec le clic synthétique natif. Aucun store, routeur, wizard gelé, prix, paiement ni cycle de statut n'est modifié.
- La validation doit reproduire le départ avec Entrée dans un navigateur authentifié, atteindre `/kiosk/categories`, puis activer une carte produit au clavier.
- REPLAN_3_REVIEW_CHANNEL: foodking-complex-implementer (codex-extension-fallback)
- REPLAN_3_REVIEW_VERDICT: PASS

## REPLAN_4 — P0/P1 issus de l'auto-audit indépendant du diff complet

- `cleanupKioskAuditOrders()` supprime directement commandes, lignes, transitions, événements et journaux d'audit, puis remet `fiscal_sequence_no` à `null`. Ce chemin P0 devient strictement non destructif et compatible : même signature, garde de base E2E dédiée, annulation des seules commandes préfixées encore annulables via `OrderService::changeStatus`, conservation des commandes terminales et de toutes les preuves fiscales/métier, et échec explicite si une commande active ne peut pas être neutralisée canoniquement. Les onze specs appelantes réelles sont ajoutées en lecture/exécution ; une sentinelle prouve qu'aucune ne dépend des anciens compteurs de suppressions et que le helper ne contient plus `delete`, `fiscal_sequence_no = null` ni `Cache::flush()`.
- La sonde de file appelle un helper qui transforme une exception `Queue::size()` en zéro. Le contrôleur POS doit sonder la file directement et convertir toute indisponibilité en `unknown/degraded`, avec test d'exception isolé.
- Les lectures/écritures du cache fiscal peuvent encore produire un HTTP 500. Une panne de lecture doit tenter la sonde fiscale live ; une panne d'écriture ne doit pas invalider un résultat live sain. Les deux cas sont testés explicitement et restent fail-closed si la sonde fiscale elle-même échoue.
- Le rafraîchissement SLA ne doit jamais lancer une seconde requête pendant qu'une première est en vol. Un test avec réponse supérieure à l'intervalle prouve l'absence de chevauchement et la sortie correcte de l'état de chargement.
- Le parcours borne multi-produits doit annuler sa commande par l'API canonique, échouer si cette annulation échoue, puis désactiver ses seules fixtures préfixées sur la base E2E dédiée, y compris si le parcours s'arrête avant la création de commande. Aucune trace de commande n'est supprimée.
- Les artefacts E2E sur chemins fixes et l'activation clavier de toute la carte borne restent P2 documentés : leur correction nécessite une refonte plus large et n'est pas confondue avec les hotfixes P0/P1 de ce cycle.
- La garde E2E combine obligatoirement `FOODKING_E2E_DEDICATED_DB=1` avec une identité de base contenant `test`, `e2e` ou `playwright` ; `APP_ENV=testing` seul ne suffit pas. Le helper inspection-only ne conserve aucun write, y compris aucun `Cache::flush()`.
- REPLAN_4_REVIEW_CHANNEL: foodking-complex-implementer (codex-extension-fallback)
- REPLAN_4_REVIEW_VERDICT: PASS

## REPLAN_5 — remédiation du GPT final audit (`REMEDIATION_AUDIT_CYCLE: 1/5`)

- Le GPT final a contredit le PASS Claude et a reproduit cinq défauts scoped. La mission repasse en EXECUTE ; aucun verdict antérieur ne permet la clôture.
- Santé POS : sonder la queue avant de construire `sync` et `overall`; `Queue::size()` indisponible doit produire `queue_pending=null`, `checks.sync.status=unknown`, `overall=degraded` et un message actionnable. Le test doit vérifier les quatre éléments, pas seulement `null`.
- Pastille POS : tout contrôle backend `unknown`, y compris fiscal/stock/aging, doit rendre son message précis visible, proposer `Réessayer`, rester ambre et être présent dans le texte détaillé. Ajouter les assertions DOM correspondantes.
- Garde E2E : les trois chemins mutateurs (helper partagé, Wave E, multi-produit) exigent simultanément `FOODKING_E2E_DEDICATED_DB=1` et un nom de base contenant `test`, `e2e` ou `playwright`; `APP_ENV=testing` seul est explicitement ignoré. Dans le helper partagé, la garde est appelée au début de `getKioskApiToken`, de `placeKioskOrder` et de `cleanupKioskAuditOrders`, avant toute création de token, commande ou transition. La sentinelle extrait chaque corps de fonction et couvre les matrices positive/négative afin qu'une simple présence du symbole dans le fichier ne puisse pas donner un faux PASS.
- Helper partagé : conserver la signature, le filtre `branch_id` exact de la machine borne existante et les transitions `OrderService`; retourner/contrôler aussi les commandes préfixées encore actives après sweep. Aucun delete, reset fiscal, update direct de statut ou cache flush.
- Wave E : ne plus transformer une annulation API ratée en `console.warn`. Toutes les commandes connues sont annulées via le point d'entrée POS; un sweep canonique préfixé traite les créations orphelines non encore ajoutées au Set; toute erreur API/service ou commande active restante fait échouer le teardown.
- Multi-produit : la configuration de la machine et son utilisateur lié sont lecture-seule et doivent déjà exister avec la même branche; aucun `updateOrCreate`, mot de passe, rattachement, statut ou identité n'est modifié. Capturer avant/après et comparer strictement les champs d'identité persistants de la machine (`id`, `user_id`, `branch_id`, `username`, `machine_id`, `password`, `status`) et de l'utilisateur (`id`, `branch_id`, `username`, `email`, `password`, `status`, `is_guest`); le champ de session `is_login` et les timestamps sont explicitement exclus du fingerprint. Avant et après le parcours, recenser les commandes synthétiques via les lignes article préfixées et les annuler par l'API POS canonique, même si `createdOrderId` n'a jamais été assigné. Désactiver toutes les fixtures préfixées de la branche, vérifier zéro commande/item/catégorie/taxe synthétique actif et conserver toutes les traces. Remplacer `Cache::flush()` par `Cache::forget('kiosk.menu.branch.'.$branchId)` après création et après désactivation; les retours exposent la clé invalidée et l'absence de valeur après invalidation. Un snapshot d'une clé menu d'une autre branche prouve qu'elle reste strictement inchangée.
- Réparer les appels/doublons laissés par la remédiation interrompue (`createKioskFixture(kioskIdentity)`, noms pluriels de teardown, déclaration Vitest dupliquée). L'inventaire autoritaire reste onze appelants réels du helper partagé. `_teste2e-heal-audit-2026-07-18.spec.js` ne l'appelle que dans des commentaires et possède son propre cleanup destructif (`delete`, reset fiscal, force-delete) : il reste explicitement hors scope/Read-Run et requiert un cycle/gate séparé avant toute exécution mutatrice.
- Le finding « mission input drift » est revérifié contre le fichier courant : `input.json` exclut bien `KioskAppComponent.vue`, tous les tests nommés existent et le contrat exige déjà la double garde. Il est classé constat stale/non reproduit ; aucune extension frozen n'est autorisée.
- Validation minimale de cette passe : `node --check` des trois harnesses, PHPUnit santé, Vitest pastille+cleanup, collecte Playwright des onze appelants, exécution réelle Wave E + multi-produit avec double opt-in, fingerprints exacts machine+utilisateur et clé cache autre branche avant/après, preuve des deux invalidations ciblées, et requête DB prouvant zéro commande synthétique active sans suppression de preuve.
- REPLAN_5_REVIEW_CHANNEL: foodking-complex-implementer (codex-extension-fallback)
- REPLAN_5_REVIEW_VERDICT: PASS

## REPLAN_6 — reprise superviseur Claude Code (`REMEDIATION_AUDIT_CYCLE: 1/5`, suite)

Contexte : la session Codex s'est interrompue (`output_codex.json` = HTTP 400,
« The 'gpt-5.5-pro' model is not supported when using Codex with a ChatGPT
account »). Aucune sortie Codex n'a donc jamais été produite pour ce cycle. La
reprise Claude Code termine VALIDATE puis relance le double audit.

Deux constats NON couverts par REPLAN_5, tous deux découverts en rejouant la
matrice pour de vrai :

1. **Parcours obligatoire `tests/Playwright/multi-device-appareils-2026-08-07.spec.js`
   rouge dans l'environnement du projet lui-même.** Deux causes distinctes,
   aucune n'étant une régression produit (la propriété métier — deux appareils
   restent connectés simultanément — est vérifiée verte, capture à l'appui) :
   - `locator('table')` violait le mode strict : la debugbar (APP_DEBUG local)
     injecte six `<table>` supplémentaires. Le comptage `table tbody tr >= 2`
     était en plus faussement satisfait par des lignes de debug. Correctif
     test-only : cibler `.table-responsive table.table`, exiger `toHaveCount(1)`.
     L'assertion devient PLUS stricte, jamais plus permissive.
   - L'assertion « zéro erreur console » capturait les sondes `/health` des
     PONTS D'IMPRESSION matériels (`127.0.0.1:9100` caisse, `9101` cuisine),
     absentes par construction sans matériel branché. Correctif test-only :
     capturer l'URL exacte via `requestfailed` et n'innocenter que ces deux URL,
     une ligne console par échec réseau prouvé sur l'allowlist. Toute autre
     connexion refusée reste un échec dur.
   - Gap d'environnement traité à part : `media` id 1 (drapeau `english.png`)
     manquait sur disque → 404 sur chaque page admin. Restauré depuis la copie
     canonique du dépôt `public/images/language/english.png`. Aucune donnée
     produit modifiée.

2. **P1 « la fixture multi-produits écrase la configuration de la machine borne »
   n'était remédié qu'à MOITIÉ.** REPLAN_5 a rendu le parcours lecture-seule,
   mais la CORRUPTION DÉJÀ ÉCRITE n'a jamais été réparée : dans `foodking_e2e`,
   `kiosk_machines.machine_id` valait encore `AUDIT-KIOSK-MULTI`
   (`updated_at = 2026-08-23 23:37:32`, soit pendant ce cycle) au lieu de la
   valeur canonique `KIOSK-LC-001` du seeder — valeur que porte toujours la base
   prod-like `foodking`. Conséquence sur la chaîne de preuve : le fingerprint
   « inchangé » du handoff comparait un état corrompu à lui-même, et certifiait
   donc une base polluée comme intacte. Un contrôle avant/après ne peut pas voir
   un dégât antérieur au run.
   - Réparation : `UPDATE kiosk_machines SET machine_id='KIOSK-LC-001'` ciblé sur
     l'unique ligne `username='kiosk-lecayenne'`, base E2E uniquement. Aucune
     trace fiscale, commande, ligne, transition ou audit touchée.
   - Preuve neuve, celle qui manquait : le parcours multi-produits rejoué APRÈS
     restauration passe et laisse `machine_id = KIOSK-LC-001` intact. Le contrat
     lecture-seule est donc prouvé contre une identité SAINE, pas seulement
     contre une identité déjà abîmée.
   - Balayage complet : aucune autre pollution `AUDIT-%` dans `kiosk_machines`,
     `users`, `branches`, `printers`.

Périmètre de REPLAN_6 : `tests/Playwright/multi-device-appareils-2026-08-07.spec.js`
(test only) + restauration de donnée de configuration dans la base E2E. Aucun
fichier produit, aucune zone gelée, aucun service, aucune migration.

REPLAN_6_REVIEW_CHANNEL: claude-code-supervisor (Codex CLI indisponible — HTTP 400 modèle)
REPLAN_6_REVIEW_VERDICT: PASS

## REPLAN_7 — faux vert d'accessibilité découvert en boucle navigateur réelle

Découvert en Phase B (boucle de supervision navigateur, S16), pas par un test :
le correctif d'accessibilité des préréglages de période **ne s'appliquait qu'à
un préréglage sur cinq**.

Mécanique exacte, vérifiée dans le DOM rendu et non déduite :
`@vuepic/vue-datepicker@3.6.8` n'emprunte un slot personnalisé que pour les
entrées de `preset-ranges` qui déclarent une propriété `slot`. Le
`<template #yearly>` corrigé en `<button type="button">` ne rendait donc que
pour l'unique entrée de démonstration héritée du template vendeur. Les quatre
autres préréglages continuaient d'être rendus par la bibliothèque en
`<div class="dp__preset_range">` : ni focalisables, ni activables au clavier,
sans rôle accessible. `SalesSummaryComponent.vue` n'avait aucune entrée avec
`slot` : son template accessible était **entièrement mort**.

Preuve DOM avant correctif (`/admin/pos-orders`, panneau ouvert) :
quatre `<div class="dp__preset_range">Aujourd'hui|Ce mois|Mois dernier|Cette
année</div>` muettes + un seul `<button class="dashboard-date-preset">Cette
année</button>` — ce dernier étant en plus un **doublon visible** du quatrième.

Pourquoi la validation ne l'a pas vu : `tests/js/dashboardDatePresetAccessibility.spec.js`
se contentait de vérifier que le FICHIER SOURCE contenait un
`<button class="dashboard-date-preset">` et pas de `<span @click>`. Un tel test
est vert quel que soit le nombre de préréglages réellement rendus en bouton.
C'est le piège nommé par CLAUDE.md §3 principe 10 : un test vert ne prouve pas
que l'implémentation est acceptable.

Correctif, borné et sans changement de comportement métier :
- Chaque entrée de `presetRanges` déclare `slot: 'yearly'`, donc chaque
  préréglage passe par le `<button>` accessible déjà écrit.
- L'entrée de démonstration en double est retirée. Dans
  `SalesReportListComponent.vue`, elle affichait littéralement le libellé
  vendeur « This year (slot) » à l'utilisateur.
- `SalesReportListComponent.vue` est ajouté au périmètre : il portait encore le
  `<span @click>` d'origine, jamais corrigé. Extension déclarée ici plutôt que
  faufilée : c'est le même défaut, le même patron, le même écran de filtres.
- La sentinelle est reconstruite : elle extrait le tableau `presetRanges` par
  appariement de crochets, lit le nom du slot réellement déclaré dans le
  `<template>`, et échoue en nommant les entrées fautives si une seule échappe
  au slot. Elle interdit aussi les doublons de libellé et tout libellé de démo.

Preuve que la sentinelle peut rougir (test de mutation) : en retirant
`slot: 'yearly'` d'un seul préréglage de `SalesSummaryComponent.vue`, elle
échoue avec « 1/3 préréglage(s) … ne déclarent pas slot: 'yearly' » ; restaurée,
elle repasse à 19/19.

Preuve navigateur après correctif : 4 préréglages, 4 `<button>`, 0 entrée
muette, 0 doublon, le premier prend le focus clavier et `Entrée` applique
réellement la période (champ vide → `08/24/2026 - 08/24/2026`).

Observation hors périmètre consignée, non corrigée : ce champ affiche la date au
format états-unien `MM/DD/YYYY` alors qu'ADR-007 verrouille la locale FR. À
traiter dans un cycle i18n dédié.

REPLAN_7_REVIEW_CHANNEL: claude-code-supervisor (Codex CLI indisponible — binaire natif ENOENT)
REPLAN_7_REVIEW_VERDICT: PASS

## REPLAN_8 — remédiation des trois audits adverses (2026-08-24)

Trois sous-agents adverses indépendants, lecture seule, ont rendu **REWORK** chacun sur un
cycle déclaré prêt. Chaque finding a été revérifié par des commandes propres avant action :
11 remédiés, 2 écartés. Détail complet dans
`reports/audit/CLAUDE_AUDIT_CAISSE-SUPERVISOR-CONTROL-20260823_2026-08-24.md`.

Périmètre de REPLAN_8, tout est test ou lecture-santé, aucun service métier :
- `tests/Playwright/global-setup.js` — **extension déclarée** : double garde de base
  ajoutée. Ce fichier écrivait pour toutes les specs sans vérifier la base cible, et la
  commande qu'il appelle réactive un compte admin et le ressuscite (`deleted_at = null`).
- `tests/e2e/helpers/kiosk-order.js` — sonde d'identité du serveur HTTP (le contrôle ne
  couvrait que la base du CLI), reconnaissance de base dédiée par segment entier, refus des
  préfixes de nettoyage faibles ou porteurs d'un joker `LIKE`, garde en première instruction.
- `app/Http/Controllers/Admin/PosSystemHealthController.php` — fenêtre 24 h sur le compteur
  « en retard », priorité du fait dur sur l'inconnue, branche 0 rendue affichable en 200,
  résolution de branche par la voie canonique `DefaultAccessModelTrait::branch()`.
- `resources/js/components/admin/pos/PosSystemHealthPill.vue` — la sévérité ne redescend
  plus ; un `overall` inconnu ne retombe pas en vert.
- `resources/js/components/admin/pos/PosComponent.vue` — les états hors-ligne et quarantaine
  coexistent au lieu de s'écraser.
- `resources/js/components/frontend/kiosk/{KioskIdleScreen,KioskCategories}Component.vue` —
  garde `$event.repeat` sur l'activation clavier.
- Les **12** composants du dépôt déclarant `preset-ranges` (six s'ajoutent aux six de
  REPLAN_7, **extension déclarée**) + libellés francisés (ADR-007).
- Sentinelles renforcées et **prouvées capables d'échouer par mutation** :
  `dashboardDatePresetAccessibility` (découverte automatique du dépôt, clé `slot` dupliquée,
  anglais interdit), `kioskAuditCleanupSafety` (découpe par accolades + position de la garde
  avant la première mutation), `kioskIdleKeyboardStart` (répétition clavier),
  `posSystemHealthPill` et `PosSystemHealthTest` (sévérité, frontière de seuil, branche 0).

Écartés après vérification, consignés sans correction : le plafond `MAX_ENTRIES` de la file
hors-ligne (plus aucun appelant produit de `enqueueOrder`, verrouillé par sentinelle) et le
faux vert `BROADCAST_DRIVER` non-`pusher` (sonde partagée `HealthzController`, hors
périmètre, escaladé).

REPLAN_8_REVIEW_CHANNEL: claude-code-supervisor + 3 sous-agents adverses (Codex indisponible)
REPLAN_8_REVIEW_VERDICT: PASS

## REPLAN_9 — sécurité composer et remise en état de la suite E2E (2026-08-25)

Déclenché par la demande propriétaire de traiter les avis de sécurité composer, puis de tourner
en boucle de tests E2E. Deux chantiers en sont sortis, tous deux hors du périmètre initial et
donc **déclarés ici** plutôt que faufilés.

### A. Sécurité des dépendances — `composer.lock` seul

`composer audit` donnait **56 avis** (2 critiques, 15 élevés), non 3 comme l'annonçait
`PROJECT_BRAIN §5`. Mise à jour **chirurgicale** par lots nommés — une mise à jour globale était
exclue, elle proposait `laravel/framework → 9.x-dev`, une branche de développement.

Résultat : **56 → 7 avis, 0 critique**. `composer.json` inchangé, `laravel/framework` figé en
v9.52.21. Les 7 restants butent tous sur la fin de vie de Laravel 9 (framework, medialibrary,
php-jwt épinglé par google/apiclient) : ils ne se ferment qu'en montant Laravel — chantier
séparé, escaladé.

**Aucune régression** : les 10 échecs backend observés après la montée ont été rejoués sur le
lock d'AVANT, avec des comptes identiques au test près.

### B. Défauts produit trouvés dans les 10 échecs backend

- `PermissionTableSeeder` non idempotent : `db:seed` et `migrate --seed` **plantaient sur toute
  base déjà migrée** (collision avec la permission créée par la migration
  `2026_08_13_190000`). Passé en `upsert`, rejouabilité prouvée.
- `RolePermissionTableSeeder` sans filtre de guard : `GuardDoesNotMatch` dès que des migrations
  créent des permissions sur le guard `web`. Corrigé par `permissionsForRole()`.
- Trois routes portaient l'intergiciel `idempotency` sans être **exigées** :
  `pos-loyalty/credit-manual`, `pos-loyalty/deduct-manual`, `raw-materials/*/adjust`. La clé
  était facultative sur du crédit de fidélité et de l'ajustement d'inventaire. Inscrites dans
  `required_routes` après vérification que les trois appelants front l'envoient déjà.
- Quatre tests périmés face au durcissement SSRF de `SafeRemoteHost` (format host+port).
- Trois « échecs » étaient un artefact de balayage : `AllergenCoverageSentinelTest` est en
  `@group manual`, porte de conformité EU 1169 délibérément garée.

`tests/Feature` : **10 échecs → 0**.

### C. Remise en état de la suite E2E

Dix specs sur onze étaient rouges, avec dix causes de dérive de fixtures. Le cycle précédent
avait consigné « Collecte Playwright : PASS » — **collecte, pas exécution**.

Remède de fond : `resolveSimpleOrderableItem()` dans le helper partagé, qui décrit le BESOIN du
banc (actif, non supprimé, disponible sur la branche, sans variation, sans étape d'assistant
obligatoire, routé vers une station de cuisine) au lieu de parier sur un identifiant.

**Neuf specs sur onze remises au vert.** Deux restent partielles (`wave-D`, `wave-F`), avec
preuve que le backend est correct dans les deux cas — non forcées.

Escalades ouvertes issues de ce chantier :
- **huit specs partagent le préfixe `AUDIT-KIOSK-WAVE-E`** : le nettoyage de l'une annule la
  commande en vol d'une autre ; elles ne sont pas sûres en parallèle ;
- le worker `queue:work` était absent de l'environnement (436 tâches en attente) — la pastille
  de santé caisse l'avait correctement signalé et s'est éteinte à sa relance ;
- `test.use({ reducedMotion })` est sans effet dans ce dépôt (Playwright 1.58.2) ; il faut
  `page.emulateMedia()`.

Détail : `reports/audit/COMPOSER_SECURITE_2026-08-25.md` et
`reports/audit/E2E_DERIVE_FIXTURES_2026-08-25.md`.

REPLAN_9_REVIEW_CHANNEL: claude-code-supervisor (Codex CLI indisponible — binaire natif ENOENT)
REPLAN_9_REVIEW_VERDICT: PASS

## SUBSYSTEMS_TOUCHED
| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|---|
| `app/Http/Controllers/Admin/PosSystemHealthController.php` | Santé fiscale/sync/stock/aging honnête et cache par branche | Write | Yes — exact branch | Read-only probes |
| `tests/Feature/Pos/PosSystemHealthTest.php` | Matrice branche et erreurs de sondes | Write | Yes | No |
| `resources/js/components/admin/pos/PosSystemHealthPill.vue` | Fraîcheur, état inconnu, accessibilité et mouvement réduit | Write | No | No |
| `tests/js/posSystemHealthPill.spec.js` | Contrat visuel/état/fraîcheur | Write | No | No |
| `resources/js/composables/usePosOfflineState.js`, `resources/js/helpers/posOfflineQueue.js` | Quarantaine/purge et politique de rejeu bornée | Write | No | No |
| `resources/js/components/admin/pos/PosComponent.vue` | Bannière offline et champs POS prioritaires | Write | No semantic change | No |
| `tests/js/posOfflineQueueImpl.spec.js`, `tests/js/usePosOfflineState.spec.js` | Non-régression quarantaine, persistance et rejeu borné | Write/Run | No | No |
| `playwright.config.js` | Alignement commande serveur/base URL | Write | No | No |
| `tests/e2e/test-e2e-pos-kds-sync-2026-05-10-wave-E.spec.js` | Fixture article déterministe | Write | Test branch only | Test only |
| `tests/e2e/helpers/kiosk-order.js` | Préserver l'identité alphanumérique `queue_number` dans les preuves E2E | Write | Test branch only | Test only |
| `tests/js/kioskAuditCleanupSafety.spec.js` | Interdire tout cleanup destructif des traces fiscales/métier | Write/Run | Test DB identity only | No |
| `tests/e2e/{test-e2e-kiosk-kds-sync-2026-05-11-wave-D,rush-sync-flow,menu-v2-kiosk-final,test-e2e-goal-4chantiers-wave-D,test-e2e-pos-kds-sync-2026-05-10-wave-F,zone6-sync-resilience,goal-pageby-borne-2026-05-18,zone3-kiosk-to-kds,wave-p-kiosk-2026-05-20,test-e2e-abuse-P-idempotency,wave-p-cross-system-2026-05-20}.spec.js` | Compatibilité des onze appelants réels du cleanup partagé | Read/Run only | Test branch only | Test only |
| `tests/e2e/audit-kiosk-multiproduct-kds-journey.spec.js` | Étapes, délais, preuves et cleanup fiable | Write | Test branch only | Test only |
| `resources/js/components/admin/dashboard/SlaAlertsComponent.vue` | Cockpit SLA superviseur, fraîcheur et erreurs | Write | Existing scoped API | No |
| `tests/js/slaAlertsSupervisor.spec.js` | Chargement, erreur, stale, synthèse et DOM borné | Write | No | No |
| `resources/js/components/admin/dashboard/{OrderStatisticsComponent,OrderSummaryComponent,SalesSummaryComponent,CustomerStatsComponent}.vue` | Préréglages de période clavier | Write | Existing scoped API | No |
| `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue` | Activation carte produit clavier | Write | Existing branch context | No |
| `resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue` | Parité clic/Entrée/Espace des boutons de départ | Write | Existing branch context | No |
| `resources/js/components/admin/posOrders/PosOrderListComponent.vue` | Préréglage de période clavier | Write | Existing scoped API | No |
| `tests/js/dashboardDatePresetAccessibility.spec.js` | Actions natives des préréglages | Write | No | No |
| `tests/js/kioskProductCardKeyboard.spec.js` | Parité clic/Entrée/Espace carte produit | Write | No | No |
| `tests/js/kioskIdleKeyboardStart.spec.js` | Même événement de départ au clic, à Entrée et à Espace | Write | No | No |
| `tests/js/posCheckoutAccessibilitySentinel.spec.js` | Labels, autocomplete, focus et boutons iconiques POS | Write | No | No |
| `tests/js/playwrightConfig.spec.js` | Matrice default/8766/no-server/commande explicite | Write | No | No |
| `tests/Feature/Branch/OrderBranchIsolationTest.php`, `tests/Feature/Outbox/OutboxDeliveryTest.php`, `tests/Feature/KdsExpectedStatusConflictTest.php` | Sentinelles invariants existantes | Read/Run | Yes | Yes, test only |
| `tests/Feature/PosPricingSsotProofTest.php`, `tests/Feature/OrderStatusNoopSideEffectsTest.php`, `tests/Feature/Order/ChangeStatusRaceGuardTest.php`, `tests/Feature/Order/TerminalOrderResurrectionGuardTest.php` | Sentinelles prix serveur et cycle de statut | Read/Run | Existing exact scope | Test only |
| `reports/execution/`, `reports/audit/`, `reports/post_execute_latest.log` | Traces de validation et audit | Write | No | No |

## SUBSYSTEMS_OFF_LIMITS
- Wheel public/tablette, son plan, son gate et ses services.
- `OrderService`, `FrontendOrderService`, paiement, pricing, remises métier, statuts de commande et migrations.
- `AuditLogService` et autres zones fiscales gelées : la correction reste dans le contrôleur de lecture POS.
- Kiosk wizard gelé, routes, schéma, configuration de production et données réelles.

## INVARIANTS_AT_RISK
- `branch_id` : la santé fiscale doit utiliser exactement la branche authentifiée et un cache distinct par branche.
- Fiscal/NF525 : aucune écriture ni modification de chaîne ; le contrôle doit échouer honnêtement en `unknown`, jamais en faux vert.
- Order lifecycle : aucune commande hors-ligne ne peut être créée ou encaissée sans devis serveur signé ; les traces héritées sont quarantainées, conservées et jamais rejouées aveuglément.
- Dispatch : aucun chemin de dispatch produit n'est modifié ; seuls tests et probes read-only sont concernés.

## GATE_CONDITIONS
- L'autorisation humaine du nouveau périmètre est enregistrée dans `tasks/CAISSE-SUPERVISOR-CONTROL-20260823.md`.
- Aucun gate supplémentaire anticipé. ESCALATE si une correction exige une migration, une suppression silencieuse d'IndexedDB, une modification de service fiscal gelé, de prix, de paiement ou de statut.
- Le gate Wheel reste pending et ne doit pas être modifié ni considéré approuvé.

## Execution Steps
1. Corriger le contrôleur de santé POS : branche authentifiée stricte (>0), propagation à fiscal/stock/aging/outbox, cache fiscal par branche, valeurs `unknown` lors des erreurs de chaque sonde, panne de file incluse et tolérance explicite aux pannes de lecture/écriture cache ; ajouter une matrice A/B, branche absente, cache séparé et panne par sonde.
2. Corriger la pastille POS : distinguer sain, dégradé, indisponible et périmé ; ne jamais conserver un faux vert ; afficher la dernière vérification et respecter le mouvement réduit.
3. Mettre la file hors-ligne héritée en quarantaine sûre sans suppression : compteur et diagnostic copiable séparés, plafond de tentatives pour les seules entrées versionnées/signées, classification 4xx terminal/réseau, zéro POST des payloads V1 non signés au montage/timer/online/clic et persistance après reload.
4. Aligner la commande du serveur Playwright sur host+port de l'URL effective (sauf commande explicite), résoudre dynamiquement l'article branch-scopé et découper le parcours multi-produits en étapes avec timeouts bornés, artefacts et cleanup dans un budget séparé. Neutraliser le helper destructif partagé, imposer une base dédiée fail-fast, les enums canoniques, les transitions API/service, la désactivation bornée des fixtures synthétiques et zéro suppression de trace fiscale.
5. Transformer le bloc SLA en cockpit dense et calme : synthèse, plus ancienne attente, top urgences, état de chargement/erreur/fraîcheur, liste bornée, actualisation accessible et verrou d'une requête en vol.
6. Corriger les activateurs dashboard/borne et les champs POS prioritaires avec éléments natifs, labels/autocomplete, focus visible et noms accessibles ; préserver le design warm-premium/outil industriel existant.
7. Exécuter les nouveaux tests nommés, PHPUnit/Vitest ciblés, sentinelles prix serveur/`OrderStatus`/dispatch/branche, tests Playwright critiques disponibles, `git diff --check`, vérification read-only de chaîne fiscale et diff explicite confirmant qu'aucun des fichiers frozen/off-limits n'a changé.
8. Ajouter aux deux boutons de départ borne des gestionnaires Entrée/Espace explicites qui empruntent exactement le chemin du clic ; couvrir les événements émis par une sentinelle dédiée, puis reproduire Entrée jusqu'au catalogue dans le navigateur.

## SYMMETRY_NOTE
N/A — ni `OrderService` ni `FrontendOrderService` ne sont modifiés.

## SCOPE_PRESSURE

## ESCALATION

## Test Strategy
`local-validation` + `playwright-critical-flow` : `PosSystemHealthTest` couvre branches A/B, branche absente, caches séparés, exception de file et pannes cache get/put ; `posSystemHealthPill.spec.js` couvre succès→échec, stale, messages unknown actionnables et reduced motion ; `usePosOfflineState.spec.js` couvre zéro POST legacy sur mount/timer/online/clic, persistance, plafond d'essais et 4xx vs réseau ; `playwrightConfig.spec.js` couvre défaut 8000, base 8766, no-server et commande explicite ; `kioskAuditCleanupSafety.spec.js` interdit toute suppression de preuve/remise de séquence fiscale à null, impose le double opt-in dans chaque fonction mutatrice, vérifie l'appel au service canonique, l'échec explicite et l'absence de dépendance des onze appelants aux anciens compteurs ; `slaAlertsSupervisor.spec.js` couvre loading/error/stale/priorité/synthèse/DOM borné et requête lente sans chevauchement ; les sentinelles accessibilité nommées couvrent dashboard, kiosk et POS ; `kioskIdleKeyboardStart.spec.js` impose exactement un événement de départ au clic, à Entrée et à Espace. Rejouer ensuite les sentinelles prix serveur, `OrderStatus`, dispatch après commit et branche, les onze specs appelantes au minimum en collecte Playwright puis les parcours Playwright POS/KDS/Kiosk critiques avec `FOODKING_E2E_DEDICATED_DB=1` sur une base dont le nom est explicitement E2E. Capturer les fingerprints machine+utilisateur et la clé cache autre branche avant/après à égalité stricte. La postcondition E2E exige zéro commande synthétique active et zéro item/catégorie/taxe synthétique actif, tout en conservant les traces historiques.

## Audit Status
[x] REWORK 1/5 — corrections REPLAN_5 en cours
[x] PLAN_REVIEW_VERDICT: PASS
[x] AUDIT_VERDICT: PASS — claude-terminal, TERMINAL_AUDIT_OK: 1
[x] GPT_FINAL_AUDIT_VERDICT: REWORK — remédiation requise
[x] REPLAN_5_REVIEW_VERDICT: PASS
[x] Nouvel AUDIT_VERDICT: PASS — 2026-08-24, claude-code-supervisor + 3 sous-agents adverses
[ ] Nouveau GPT_FINAL_AUDIT_VERDICT — CANAL INDISPONIBLE (binaire Codex ENOENT, HTTP 400 modèle) : décision humaine requise
[ ] Passed — cycle closed
[ ] Gate opened
