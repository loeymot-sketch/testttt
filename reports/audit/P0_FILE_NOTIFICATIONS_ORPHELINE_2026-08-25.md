# 🔴 P0 — La file `notifications` n'est écoutée par personne, et aucune sonde ne le voit

- **Découvert le** : 2026-08-25, vague W4 du GOAL `CONSOLIDATION_V1_PRODUCTION_20260825`
- **Trouvé en cherchant autre chose** : le runbook de redémarrage du worker (T-3.3.1)
- **Statut** : **aucun correctif appliqué** — le déblocage a une conséquence client, voir §5

---

## 1. Le fait, mesuré

```
Queue::size('notifications')  =  1490
Queue::size('default')        =  0
Queue::size('high')           =  0

redis> LLEN queues:notifications        →  1490   (liste « prêts », ni différés ni réservés)
redis> LINDEX queues:notifications -1   →  App\Jobs\SendFcmNotificationJob, attempts=0
```

`attempts=0` : ces travaux n'ont **jamais été tentés une seule fois**. Ce n'est pas un worker qui
échoue, c'est un worker qui ne regarde pas.

## 2. Pourquoi personne ne les traite

| Endroit | Commande |
|---|---|
| Poste local | `php artisan queue:work --queue=high,default --tries=3 --timeout=60` |
| Production (`scripts/deploy/supervisor.conf.template:42`) | `queue:work redis --queue=high,default --tries=3 --timeout=120 …` |

**`notifications` ne figure dans aucune des deux listes.**

Or `app/Jobs/SendFcmNotificationJob.php:67` fait explicitement :

```php
$this->onQueue('notifications');
```

Et ces travaux sont produits par le flux normal des commandes —
`app/Listeners/SendFcmOnOrderCreated.php` (3 points de publication) et
`app/Listeners/SendFcmOnOrderStatusChange.php`.

Horizon n'est pas installé (`config/horizon.php` existe, mais `laravel/horizon` n'est **pas** dans
`composer.json`) : il n'y a donc pas d'autre consommateur caché.

## 3. Pourquoi personne ne l'a vu — le défaut le plus grave

Les **trois** sondes de santé du projet comptent exactement les deux mêmes files :

| Sonde | Ligne | Ce qu'elle compte |
|---|---|---|
| `PosSystemHealthController` | `:179` | `Queue::size('default') + Queue::size('high')` |
| `HealthController` | `:127-128` | `Queue::size('default')`, `Queue::size('high')` |
| `HealthzController` | `:227-228` | `Queue::size('default') + Queue::size('high')` |

**Aucune ne regarde `notifications`.** Résultat : 1 490 travaux pourrissent pendant que les trois
surfaces affichent « file OK ». C'est un **faux vert**, et c'est exactement le défaut que le
correctif OPS-2 du 2026-06-04 avait éliminé pour le websocket — la même erreur, sur une autre sonde.

## 4. Conséquences

1. **Les notifications push clients ne partent pas.** Un client ne reçoit jamais « votre commande
   est prête ». Fonctionnalité silencieusement morte.
2. **Le retard grossit sans borne.** 1 490 aujourd'hui, et la production continue à chaque commande.
   Redis enfle sans que rien ne l'annonce.
3. **La supervision ment.** Le pire des trois : les trois surfaces disent « sain ».

## 5. 🔴 CE QU'IL NE FAUT SURTOUT PAS FAIRE

⛔ **Ne pas ajouter `notifications` au worker sans purger ou dater le retard.**

Au démarrage, le worker enverrait **1 490 notifications push d'un coup**, portant sur des commandes
vieilles de plusieurs semaines. Des clients recevraient « votre commande est prête » pour un repas
consommé le mois dernier. Le remède serait pire que le mal.

C'est précisément pourquoi je n'ai rien débloqué.

## 6. Décision demandée

**Étape 1 — rendre la supervision honnête** *(sans risque client, recommandé immédiatement)*
Les sondes doivent voir toutes les files réellement utilisées, pas deux d'entre elles. Une sonde
qui ignore une file est un faux vert.

**Étape 2 — traiter le retard** *(votre décision)*
- **A)** Purger les 1 490 travaux, puis brancher le worker sur `notifications`. *(recommandé —
  ces notifications n'ont plus aucun sens ; ⛔ purge = opération destructive, votre accord explicite requis)*
- **B)** Brancher le worker en filtrant à la volée sur l'âge (ne notifier que les commandes récentes).
- **C)** Laisser la file orpheline et **retirer** `onQueue('notifications')` du code, si la
  notification push n'est pas une fonctionnalité V1. Assumé et documenté plutôt que subi.
- **D)** Ne rien faire — mais alors documenter la file morte, sinon la prochaine session la
  redécouvrira et perdra le même temps.

**Ma recommandation : étape 1 tout de suite, puis A.** La supervision aveugle est le vrai défaut ;
le retard n'est que son symptôme visible.

---

*Aucune donnée n'a été purgée, aucun worker n'a été reconfiguré, aucun travail n'a été déclenché.*

---

# ⚠️ CORRECTIONS APPORTÉES À CE RAPPORT (même jour, après vérification supplémentaire)

Deux affirmations de ce rapport méritaient d'être précisées. Je les corrige ici plutôt que de
réécrire silencieusement ce qui précède.

## Correction 1 — l'environnement mesuré est LOCAL, pas la production

`.env` de cet arbre : `APP_ENV=local`, `DB_DATABASE=**foodking_e2e**`.

Le chiffre de **1 490** est donc une mesure sur un environnement de développement/E2E, pas sur la
machine qui sert. **Je ne sais pas combien de travaux dorment en production**, et ce rapport ne
doit pas laisser croire le contraire.

**Ce qui reste vrai indépendamment de l'environnement** — et c'est le cœur du défaut :
- `SendFcmNotificationJob:67` publie sur `notifications` ;
- le modèle superviseur **de production** (`supervisor.conf.template:42`) écoute `--queue=high,default` ;
- les trois sondes comptaient `default` + `high` en dur.

Autrement dit : **le défaut de configuration et la cécité des sondes sont réels en production
aussi.** Seule l'ampleur du retard y est inconnue. Première chose à faire sur le serveur :

```bash
php artisan tinker --execute='foreach ((array) config("queue.monitored_queues") as $f) {
    echo $f." = ".Illuminate\Support\Facades\Queue::size($f)."\n"; }'
```

## Correction 2 — ce ne sont pas seulement des notifications clients

J'avais écrit « les notifications push clients ne partent pas ». Échantillon de 300 travaux sur
les 1 490 :

| Sujet | Part de l'échantillon |
|---|---|
| `customer_order_<id>` | ~53 % — notifications de suivi de commande client |
| `kitchen_branch_1` | ~46 % — notifications **cuisine** |
| `oss_branch_1` | < 1 % — écran de statut des commandes |

Ce sont donc **les trois publics** qui sont muets, pas seulement les clients. La cuisine et l'écran
de statut sont concernés au même titre. L'impact est plus large que ce que j'avais écrit, pas plus
étroit.

## Ce qui ne change pas

⛔ Le worker ne doit toujours **pas** être rebranché sans traiter le retard : brancher
`notifications` déclencherait l'envoi en masse de notifications portant sur des commandes
périmées — vers la cuisine, les clients et l'écran de statut à la fois.
