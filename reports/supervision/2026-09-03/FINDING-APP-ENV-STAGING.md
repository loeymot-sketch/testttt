# La machine qui encaisse tourne en `APP_ENV=staging`

Relevé le 2026-09-03 sur `vps-418872ac.vps.ovh.net` (`ssh lecayenne`, `/var/www/lecayenne`).
**Aucune valeur n'a été modifiée.** Ce document constate.

## Les faits, mesurés

```
APP_ENV=staging          app()->environment('production') → NON
APP_DEBUG=false          (correct)
POS_SIMULATION_HARDWARE=true
APP_URL=https://vps-418872ac.vps.ovh.net

orders      = 879
audit_logs  = 1 386
z_reports   = 18          ← des clôtures fiscales quotidiennes
dernière commande : 2026-09-02 23:18:44 (n° 1EB89)
```

## Ce que cela éteint

Tous les gardes de démarrage de `AppServiceProvider` sont enfermés dans
`if (app()->environment('production'))`. Avec `APP_ENV=staging`, **aucun ne s'arme** :

- `POS_SIMULATION_HARDWARE` doit être `false` en production (CLAUDE.md §8 : « NF525 cash-trail
  bypass ») — il vaut `true`, et rien ne l'empêche ;
- `IDEMPOTENCY_MIDDLEWARE_ENABLED`, `APP_DEBUG`, `APP_URL`, pilote de cache : mêmes gardes,
  même inertie ;
- le garde NF525 contre `config:cache` posé cette nuit : **inerte lui aussi**. Il protège
  exactement la machine qui n'en bénéficie pas.

Et côté readiness : `/api/health/ready` ne rend `503` sur `restore_drill`, `backup_age` ou
`scheduler` qu'en production. Aujourd'hui il annonce `status: ok` alors que `restore_drill`
est `degraded` — « restauration de vérification jamais mesurée ». Le statut global ment par
construction.

## Pourquoi je n'ai rien changé

Passer `APP_ENV=production` ferait **refuser le démarrage** : le garde
`POS_SIMULATION_HARDWARE must be false in production` lèverait immédiatement. Le service
tomberait.

Et passer `POS_SIMULATION_HARDWARE=false` n'est pas une option de configuration : les
terminaux ne sont **pas câblés à la banque** (CLAUDE.md §3bis, « SumUp provider current,
terminals pas câblés bank Plan A »). L'encaissement s'arrêterait.

Autrement dit : les deux valeurs se tiennent l'une l'autre, et `staging` est très probablement
un **contournement délibéré** pour que la machine démarre malgré du matériel non câblé. Le
dépôt le sait à demi-mot — `docs/FINAL_SECURITY_PHASE_CHECKLIST.md:53` prévoit le cas et écrit
« documenter (staging non-légal) ».

C'est une décision d'exploitation et de conformité, pas un correctif. Je la porte, je ne la
prends pas.

## Les issues, avec leur coût

| Issue | Ce que ça implique | Coût |
|---|---|---|
| **A — Câbler les terminaux**, puis `POS_SIMULATION_HARDWARE=false`, puis `APP_ENV=production` | Les gardes s'arment enfin. C'est la seule issue qui rétablit l'architecture voulue. | Dépend de la banque, hors logiciel |
| **B — Armer les gardes hors production** : remplacer `environment('production')` par un critère qui vaut aussi pour `staging` quand la machine produit des documents fiscaux (`z_reports > 0`) | Le système refuserait de démarrer **immédiatement** sur `POS_SIMULATION_HARDWARE=true`. Même chute qu'en A, sans le bénéfice. | À ne PAS faire tel quel |
| **C — Rendre l'état visible sans le changer** : une sonde qui annonce « documents fiscaux produits avec les gardes de production inertes » sur le cockpit et readiness | Ne casse rien, supprime le silence. Ne rétablit aucune protection. | ~1 h |
| **D — Statu quo documenté** | Ce fichier. Le risque reste, mais il est nommé. | fait |

Recommandation : **C tout de suite, A dès que la banque suit.** B est un piège : il produit
l'arrêt du service sans apporter de protection, puisque la cause est matérielle.

## Ce que ce constat change pour les rapports antérieurs

Toute affirmation du dépôt de la forme « le garde de boot empêche X en production » est vraie
**dans le code** et fausse **sur cette machine**. Cela inclut le §8 de `CLAUDE.md`, qui présente
ces gardes comme « concrete enforcement » des invariants NF525.
