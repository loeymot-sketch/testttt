# Forensique commandes non terminales — lecture seule

**Date/heure DB :** 2026-08-11 23:53 Europe/Paris système  
**Environnement :** Laravel `local`, application `Le Cayenne`  
**Branche de données observée :** `branch_id=1` uniquement  
**Mutation exécutée :** aucune  
**But :** séparer les commandes réellement actionnables des candidats janitor et des historiques protégés avant toute réparation

## 1. Résultat principal

La pastille « 484 commandes > 15 min » ne représente pas une file opérationnelle actuelle. La base locale contient :

| Population | Nombre | Décision |
| --- | ---: | --- |
| Statuts `PENDING/ACCEPT/PREPARING` | 484 | Compteur health actuel |
| Statut `PREPARED` non terminal | 84 | Absent du compteur health |
| Total non terminal `1/4/7/8` | **568** | Inventaire complet |
| Créées dans les dernières 24 h | **0** | Aucun rush actuel |
| Datées du jour | **0** | Tracker « 0 aujourd'hui » cohérent avec sa fenêtre |
| Planifiées dans le futur | **0** | Aucun `UPCOMING` réel dans ce snapshot |
| Payées parmi les 568 | **479** | Ne jamais purger automatiquement |
| Fiscalisées parmi les 568 | **467** | Protection NF525 absolue |
| Impayées ou attente comptoir, non fiscalisées | **89** | À classifier, pas à annuler en bloc |
| Vieilles de plus de 30 jours | **434** | Explique le dashboard SLA pollué |

Conclusion : le système mélange trois objets différents sous le mot « attente » : backlog technique à nettoyer, commandes payées/fiscalisées bloquées dans un état de production historique, et exceptions métier manuelles.

## 2. Répartition par statut et âge

| `OrderStatus` | 1–7 jours | 8–30 jours | >30 jours | Total |
| --- | ---: | ---: | ---: | ---: |
| `PENDING (1)` | 79 | 2 | 38 | 119 |
| `ACCEPT (4)` | 8 | 2 | 24 | 34 |
| `PREPARING (7)` | 9 | 26 | 296 | 331 |
| `PREPARED (8)` | 1 | 7 | 76 | 84 |
| **Total** | **97** | **37** | **434** | **568** |

Il n'existe aucune ligne de moins de 24 heures. Une alerte de rush ne doit donc afficher aucune de ces 568 lignes comme « nouvelle ». Elles doivent être classées en dette historique, candidat janitor ou exception à examiner.

## 3. Les 89 lignes non fiscalisées

### 3.1 — 78 correspondent aux prédicats du janitor web

Le prédicat en lecture seule reproduit la lane `CleanupStalePendingKioskOrders` : statuts `PENDING/ACCEPT/PREPARING`, paiement `UNPAID|PENDING_COUNTER`, aucune séquence fiscale, surface `web|delivery`, créneau vieux de plus de six heures.

| Sous-groupe | Nombre | Détail |
| --- | ---: | --- |
| Web PENDING UNPAID, cash/COD | 74 | Créées le 2026-08-05 |
| Web PENDING UNPAID, carte | 3 | Également candidates à la lane courte 60 min |
| Delivery PENDING UNPAID, COD | 1 | Surface site normalisée en delivery |
| **Total candidat janitor web** | **78** | Toutes dépassent largement le TTL |

Ces 78 lignes expliquent les 77 commandes web affichées par le POS plus une livraison. Elles ne sont pas supprimées ici : le job émet `OrderCanceled`, et l'audit global a démontré que la compensation stock actuelle peut devenir split-brain lorsque la remise physique échoue mais que `released_qty` est avancé par la disponibilité.

### 3.2 — 11 exceptions hors prédicat automatique

| Classe | IDs | Nombre | Motif de revue humaine |
| --- | --- | ---: | --- |
| `PENDING/UNPAID`, `source_surface=NULL` | 4511, 5000, 5102, 5716 | 4 | Origine canonique absente ; aucune lane ne peut les classer sans inférence |
| POS `PENDING/UNPAID` | 4836, 4944 | 2 | Deux formes de marqueur POS ; déterminer abandon/test/commande réelle |
| Delivery `PREPARED/UNPAID` | 4991, 6220 | 2 | `PREPARED→CANCELED` interdit ; `order_type=DELIVERY` exclu du soft-delete phantom |
| POS `PREPARED/PENDING_COUNTER` | 5530, 5531, 5532 | 3 | Anciennes commandes à encaisser, hors lanes kiosk/phone/web |
| **Total** |  | **11** | Aucune mutation automatique recommandée |

Les montants et données clients ne sont volontairement pas copiés dans ce rapport. Les IDs servent seulement à la revue contrôlée en base et dans l'historique.

## 4. Les 479 commandes payées non terminales

Les principales populations sont :

- 305 POS en `PREPARING`, toutes payées et fiscalisées ;
- 59 POS en `PREPARED`, payées et fiscalisées ;
- 21 kiosk en `ACCEPT`, payées/fiscalisées ;
- 17 kiosk en `PREPARING`, payées/fiscalisées ;
- 8 Uber en `ACCEPT`, payées mais sans séquence fiscale dans le code dirty observé ;
- plusieurs web/kiosk/delivery payées restant en `PENDING`, `ACCEPT`, `PREPARING` ou `PREPARED`.

Ces lignes sont de la dette de lifecycle/état, pas des abandons impayés. Les annuler en masse casserait la trace opérationnelle et, pour 467 lignes, toucherait une vente fiscalisée. La stratégie recommandée est :

1. préserver les écritures ;
2. les retirer des métriques temps réel par classification temporelle ;
3. vérifier les transitions manquantes et les éventuels imports/tests ;
4. produire un jeu de réparation humainement approuvé par classe, jamais une requête globale sur `updated_at`.

## 5. Diagnostic scheduler local

`php artisan schedule:list` déclare bien `CleanupStalePendingKioskOrders` toutes les cinq minutes. Cependant :

- aucun processus `artisan schedule:work`, `schedule:run`, cron ou supervisord n'était actif lors de l'inspection ;
- les logs du janitor trouvés sous `storage/logs` proviennent du canal `testing` et d'exécutions de tests ;
- aucun signal local du 2026-08-11 ne prouve une exécution périodique de production.

Cela explique le backlog **dans cet environnement local**. Ce constat ne prouve pas l'état du scheduler de production. Il prouve en revanche que l'interface santé actuelle ne sait pas distinguer « job déclaré » de « scheduler exécuté récemment ».

## 6. Projection opérationnelle correcte pour ce snapshot

| Catégorie Inbox/Health cible | Nombre attendu | Commentaire |
| --- | ---: | --- |
| `ACTION_REQUIRED_NOW` | 0 à confirmer humainement | Aucune ligne du jour/24 h ; les anciennes ne doivent pas sonner |
| `UPCOMING` | 0 | Aucun `scheduled_at` futur |
| `JANITOR_READY_WEB` | 78 | Éligibles en données, exécution bloquée par contrôle stock/gate |
| `MANUAL_REVIEW_UNPAID` | 11 | Les IDs sont listés ci-dessus |
| `HISTORICAL_PAID_NON_TERMINAL` | 479 | Ne pas afficher comme rush ; audit lifecycle |
| `FISCAL_PROTECTED` | 467 | Jamais de purge/annulation automatique |

Le chiffre `ACTION_REQUIRED_NOW` ne peut être définitivement fixé à zéro qu'après vérification métier des 11 exceptions. Techniquement, aucune ligne n'est récente ou planifiée dans le futur.

## 7. Runbook de réparation proposé — non exécuté

### Étape A — sécuriser avant nettoyage

1. Fermer le défaut de compensation stock ou disposer d'une réconciliation mouvement-par-mouvement prouvée.
2. Ajouter la santé scheduler : dernière exécution réussie/échouée, durée, candidats, lignes traitées et dernier ID.
3. Sauvegarder la base et exporter les 89 lignes avec items, mouvements stock, disponibilité, fidélité, paiements et transitions.
4. Exiger un dry-run dont les IDs correspondent exactement à cette classification ou expliquer tout delta.

### Étape B — 78 candidats janitor

1. Rejouer la sélection sous verrou en mode dry-run.
2. Pour chaque ordre : vérifier fiscal null, paiement non encaissé, absence de transaction acquéreur, absence de `scheduled_at` futur.
3. Exécuter par petits lots avec correlation ID.
4. Vérifier après chaque lot : état terminal, stock physique, disponibilité, fidélité, outbox et absence de double compensation.

### Étape C — 11 exceptions

1. Résoudre l'origine des quatre `source_surface=NULL` sans modifier l'historique arbitrairement.
2. Décider les deux POS PENDING à partir des preuves paiement/session.
3. Traiter les cinq PREPARED via une décision owner/state-machine ; aucun soft-delete implicite.

### Étape D — 479 payées

Ne pas les faire passer automatiquement à un statut terminal. Construire un rapport de transitions manquantes par date/source, puis décider s'il s'agit de données de test, d'import ou de véritables commandes livrées. Toute correction fiscale/lifecycle doit être immutable et auditée.

## 8. Requêtes utilisées

Toutes les requêtes étaient des `SELECT`. Les dimensions contrôlées étaient : `status`, `payment_status`, `fiscal_sequence_no`, `source_surface`, `source`, `order_type`, `payment_method`, `pos_payment_method`, `created_at`, `order_datetime`, `scheduled_at`, `business_date` et `branch_id`.

Les constantes utilisées proviennent des enums serveur :

- `OrderStatus`: PENDING=1, ACCEPT=4, PREPARING=7, PREPARED=8 ;
- `PaymentStatus`: PAID=5, UNPAID=10, PENDING_COUNTER=15 ;
- `PaymentGateway`: COD=1, CARD=4 ;
- `OrderType`: DELIVERY=5, TAKEAWAY=10, POS=15, KIOSK=25.

**FORENSICS_VERDICT: CLASSIFIED_NO_MUTATION**  
**AUTO_REPAIR_AUTHORIZED: NO**
