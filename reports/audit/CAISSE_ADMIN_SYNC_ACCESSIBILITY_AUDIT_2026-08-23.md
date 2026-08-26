# Audit réel — caisse, administration, borne, KDS et synchronisation

**Date :** 2026-08-23  
**Périmètre :** application locale servie sur `127.0.0.1:8766`, parcours caisse, borne, KDS, administration, gestion des appareils et observabilité.  
**Méthode :** navigation navigateur authentifiée, parcours borne, Playwright réel, lecture ciblée des composants et suites serveur.  
**Mémoire :** Graphiti indisponible dans cette session ; la mémoire disque du dépôt a été utilisée en secours.

## Addendum post-remédiation — 2026-08-23

Cet addendum supersède le verdict initial ci-dessous pour le périmètre borné `CAISSE-SUPERVISOR-CONTROL-20260823`. Les constats historiques restent conservés comme trace de départ.

**VERDICT DU PÉRIMÈTRE REMÉDIÉ : VALIDATION TECHNIQUE PASSÉE, DOUBLE AUDIT INDÉPENDANT EN ATTENTE.**

- 39 tests backend ciblés sont verts, dont branche, prix serveur, `OrderStatus`, concurrence et Outbox.
- 89 tests Vitest ciblés sont verts ; le build de production passe.
- Wave E passe désormais avec article dynamique branch-scopé et identité de file alphanumérique vérifiée API/base.
- Le parcours borne multi-produits passe, annule canoniquement sa commande et laisse zéro fixture synthétique active sans supprimer les preuves historiques.
- L'audit navigateur confirme Entrée sur le départ borne jusqu'aux catégories, puis Entrée sur une carte produit jusqu'au wizard.
- La santé POS, la file offline héritée, le cockpit SLA, les préréglages dashboard et les champs POS ont reçu les corrections et sentinelles décrites dans le rapport d'exécution.

Deux limites externes restent ouvertes sans invalider ce périmètre : les cinq signoffs historiques de `pos:lint:pricing` nécessitent l'autorité pricing/frozen appropriée, et une ancienne suite d'idempotence dépend toujours du produit indisponible `Coca-Cola 33cl`. La clôture du cycle reste conditionnée aux verdicts Claude puis GPT.

## Verdict exécutif

**VERDICT : NON CERTIFIABLE POUR UNE MISE EN PRODUCTION GLOBALE À CE STADE.**

Le noyau métier testé est sain : 39 tests serveur ciblés sont verts, notamment le prix calculé côté backend, l'isolation `branch_id`, les transitions de commande et l'outbox après commit. La navigation de gestion est également largement utilisable : 33 des 34 destinations vérifiées ont chargé un contenu utile sans erreur de routage ni fuite de clé i18n.

En revanche, le parcours réel complet **borne → paiement → KDS → suivi caisse** ne peut pas être certifié aujourd'hui : sa suite de référence dépend d'un article supprimé (`id 361`) et une seconde suite intégrée expire au bout de six minutes sans point de contrôle permettant d'identifier l'étape fautive. Ce sont d'abord des défauts d'assurance qualité ; ils empêchent toutefois de prouver la synchronisation inter-écrans à chaque livraison.

Le dépôt contient en parallèle un cycle Wheel en phase EXECUTE. Aucun fichier produit n'a été modifié par cet audit afin de ne pas créer une collision ou d'élargir son périmètre. Les correctifs rapides sont donc préparés dans le plan séparé ci-dessous.

## Ce qui a été réellement vérifié

| Domaine | Preuve | Résultat |
|---|---|---|
| Administration et gestion | Test Playwright `dashboard-nav-buttons-reachability` sur 34 destinations d'administration | 33 chargent du contenu utile ; 1 exception Wheel, détaillée plus bas. |
| Caisse / KDS | `kds-caisse-smoke` | Vert. |
| Sessions appareil multi-terminal | `multi-device-appareils-2026-08-07` | Fonctionnel jusqu'à l'assertion finale ; faux échec de sélecteur causé par le Debugbar local. |
| Métier critique | 39 tests Laravel ciblés : outbox, quote borne, paiement, transitions, `branch_id`, remises caisse | Verts. |
| Borne | Navigation navigateur de `/kiosk/idle` vers les catégories ; catalogue et panier constatés | Vert au niveau d'entrée et de navigation. |
| POS | Navigation navigateur de `/admin/pos`, file et données opérationnelles visibles | Vert au niveau de chargement ; défauts d'accessibilité ci-dessous. |
| Synchronisation end-to-end | Suite Wave E et parcours multi-produits KDS | Non concluante : fixture obsolète puis timeout non diagnostique. |

Les données de test actives `AUDIT-KIOSK-MULTI` ont été neutralisées et vérifiées à zéro (articles, catégorie, taxe et commandes actives associées), sans supprimer les commandes ni les preuves fiscales/métier historiques.

## Parcours par rôle

### Caissier

Le POS charge et présente une file en attente, les comptes cash/web et les appels de données nécessaires. Les opérations métier sensibles restent couvertes côté serveur : la remise ne fait pas confiance au sous-total client, et les transitions invalides sont refusées.

Le frein concret est l'accessibilité des champs de finalisation : nom, téléphone et livraison sont visuellement compréhensibles, mais plusieurs champs ne possèdent pas de `label` associé ni de nom accessible. En service, cela pénalise un caissier clavier, lecteur d'écran ou dictée vocale ; un changement de bordure seul ne donne pas un focus suffisamment robuste.

### Client borne

L'écran de veille, son appel à l'action, le réglage d'accessibilité et les catégories sont visibles et utilisables. Les images rencontrées ont un texte alternatif non vide.

Chaque tuile de produit reste néanmoins un `div` cliquable. Le clic sur toute la carte est donc inaccessible au clavier, tandis que le bouton interne d'ajout est bien un vrai bouton. Ce décalage crée deux chemins d'activation non équivalents entre tactile/souris et clavier.

### Cuisine / KDS

Le smoke test KDS est vert et les gardes serveur testées empêchent une mise à jour concurrente obsolète de modifier une commande ou d'émettre un événement. La synchronisation navigateur inter-postes demeure à re-prouver après réparation des tests E2E. Le commentaire de la suite Wave E signale aussi un environnement local où Pusher peut retomber en polling : la latence temps réel sur une installation représentative doit donc être mesurée séparément.

### Responsable et administrateur

La majorité des pages de gestion testées chargent sans erreur. Le tableau de bord local affichait **331 alertes** et des commandes très anciennes : c'est un signal de fatigue d'alerte ou de données de démonstration non purgées, pas une régression fonctionnelle prouvée. Sans regroupement et horizon d'ancienneté, un responsable risque de manquer une alerte réellement urgente.

## Constats priorisés

| ID | Sévérité | Constat confirmé | Impact en service | Correctif proposé |
|---|---|---|---|---|
| A-01 | P1 | La route Wheel affiche les clés brutes `admin.wheel.home` et `admin.wheel.acces`. Elle a du contenu, mais ne respecte pas le conteneur SPA attendu par le test de navigation. | Libellés non professionnels et rupture de cohérence de l'administration. | Corriger les traductions et valider explicitement le contrat d'intégration de cette surface spéciale. À traiter dans le cycle Wheel déjà actif. |
| A-02 | P1 | La carte produit borne est un `div` cliquable sans `tabindex` ni gestion clavier (`KioskCategoriesComponent.vue:153-166`). | Impossible d'utiliser le geste principal au clavier ; comportement incohérent entre modes d'entrée. | Rendre l'activateur une vraie action clavier, sans doublonner l'action du bouton interne ; ajouter test Tab/Entrée/Espace. |
| A-03 | P1 | La suite Wave E fixe `ITEM_FRITES_SEULES = 361` (`test-e2e-pos-kds-sync-2026-05-10-wave-E.spec.js:118-120`). Le serveur répond `422 Article 361 introuvable`. | Le test critique ne prouve plus la chaîne borne→KDS→POS. | Créer/résoudre une fixture disponible dans la branche de test et vérifier son éligibilité avant le quote. |
| A-04 | P1 | Le parcours borne multi-produits fixe un timeout global de 360 s et ne journalise pas les étapes ; il expire sans diagnostic. Il efface aussi une capture dans un répertoire de rapport persistant. | Impossible de distinguer panne produit, lenteur, session ou fixture ; risque d'écraser une preuve d'audit. | Isoler les artefacts par exécution, ajouter `test.step` et délais courts par étape, cleanup en `finally`. |
| A-05 | P2 | Champs POS sans association de libellé : identité client (`PosComponent.vue:1056-1076`), livraison (`1113-1147`), et bouton d'effacement iconique (`1152-1159`) nommé seulement via `title`. | Lecteur d'écran, saisie vocale et repérage clavier dégradés. | `label for`/`id` ou `aria-label` localisé ; libellés visibles conservés ; nom accessible du bouton. |
| A-06 | P2 | Ces champs POS retirent l'outline sans anneau `:focus-visible` équivalent, par exemple `1067`, `1075`, `1118`, `1126`, `1146`. | Focus difficile à distinguer dans une caisse dense, surtout en pression de service. | Anneau de focus cohérent, contraste vérifié et test clavier direct. |
| A-07 | P2 | Quatre widgets de dashboard rendent les préréglages de période avec un `span @click` : OrderSummary:12, OrderStatistics:10, SalesSummary:13, CustomerStats:12. | Les raccourcis de période sont inatteignables au clavier. | Utiliser le slot/action accessible fourni par le datepicker ou un `button type=button`. |
| A-08 | P2 | Le test appareils lit `locator('table')`, mais le Debugbar local ajoute des tables ; Playwright échoue en mode strict alors que les deux sessions et les lignes attendues existent. | Faux négatif de session multi-appareil. | Scoper le tableau fonctionnel par rôle, conteneur applicatif ou `data-testid`; ne jamais sélectionner les tables globales. |
| A-09 | P2 | 331 alertes et données de commande âgées visibles sur le dashboard local. | Risque de fatigue d'alerte pour le responsable. | Séparer démo/opérations, agréger les alertes, montrer la plus ancienne et les seuils SLA, définir un nettoyage de données non-production. |
| A-10 | P3 | Plusieurs composants utilisent `transition: all` (notamment POS/Payment). Des règles de mouvement réduit existent déjà dans certaines zones. | Animation plus coûteuse et audit `prefers-reduced-motion` incomplet. | Limiter les propriétés animées et couvrir les écrans critiques par préférence de mouvement réduit. |

## Analyse de synchronisation et invariants

Les preuves serveur vertes couvrent directement les invariants FoodKing suivants :

- **Prix :** le quote borne et la remise POS sont calculés/validés côté backend ; aucune confiance au total ou sous-total envoyé par le client n'a été observée dans les tests exécutés.
- **`OrderStatus` :** les transitions invalides sont refusées, et la concurrence KDS avec statut attendu retourne un conflit sans effet ni événement.
- **`branch_id` :** les tests de lecture/paiement cross-branch sont verts.
- **Dispatch après commit :** les sept tests Outbox vérifient la disponibilité, les événements obsolètes et le dispatch associé au commit.
- **Parité OrderService / FrontendOrderService :** non modifiée dans cet audit ; aucun écart nouveau n'est introduit.

Cela réduit fortement la probabilité d'une erreur métier de prix ou de fuite inter-établissement dans le périmètre testé. Cela ne remplace pas une preuve navigateur end-to-end réparable et répétable ; la conclusion de mise en production reste donc conditionnelle.

## Limites volontairement déclarées

- Il s'agit d'un environnement local avec données existantes, pas d'un test matériel TPE/imprimante/KDS physique ni d'un test de charge.
- Les 34 routes administratives vérifiées sont un échantillon de navigation, pas une exploration exhaustive de chaque CRUD, permission et état d'erreur.
- Le premier essai local sur le port 8000 a échoué car la configuration applicative attend 8766 ; l'exécution a été réalignée sur 8766. Ce n'est pas consigné comme défaut produit.
- Le cycle Wheel concurrent possède ses fichiers et sa zone fonctionnelle. L'audit ne modifie ni son plan, ni son code, ni ses artefacts.

## Référentiel d'interface

Les constats d'accessibilité appliquent notamment les principes de sémantique native, de nom accessible, de focus visible et d'interaction clavier du [Web Interface Guidelines](https://raw.githubusercontent.com/vercel-labs/web-interface-guidelines/main/command.md).

## Décision de suivi

Exécuter le plan `reports/planning/CAISSE_ADMIN_SYNC_REMEDIATION_PLAN_2026-08-23.md` dans de nouveaux cycles bornés, après la clôture ou la libération explicite du cycle Wheel actif. Rejouer ensuite les parcours réels avec Pusher/Soketi disponible et une base de données de test isolée.
