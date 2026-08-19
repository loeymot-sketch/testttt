# Arbitrage propriétaire — le caissier peut-il annuler une commande PAYÉE ?

> Rédigé le 2026-08-19 en clôturant le GOAL caisse/cuisine.
> **Rien n'a été changé sur les droits.** Ce document mesure, expose les options, et donne la
> commande exacte à lancer si la réponse est oui. La décision déplace de l'argent : elle
> n'appartient pas à l'agent.

---

## 1. Le fait

Sous un compte **Caissier (POS Operator)**, annuler une commande **PAYÉE** renvoie **403**.

Ce n'est pas un bug. C'est une garde délibérée, écrite noir sur blanc dans le code :

- `app/Http/Controllers/Admin/PosOrderController.php:328-342` — annuler une commande PAYÉE
  **rend l'argent** (`cashBack` si une transaction existe, sinon `recordCashRefundMovement`
  pour une vente en espèces). Le contrôleur exige donc `pos-refund` sur `CANCELED` et
  `REJECTED` **dès que `payment_status = PAYÉ`**. Annuler une commande NON payée reste libre :
  aucun argent ne bouge.
- `database/seeders/RolePermissionTableSeeder.php:89` — `pos-refund` est donné à Branch Manager,
  **délibérément pas** au Caissier. Motif inscrit dans le seeder : *vecteur de remboursement de
  masse*.

## 2. Ce que ça coûte aujourd'hui (mesuré en base le 2026-08-19)

| Mesure | Valeur |
|---|---|
| Comptes portant le rôle Caissier | **40** |
| Comptes portant `pos-refund` (Admin + Branch Manager) | 21 + 5 |
| Commandes non terminées antérieures à la journée de service | **577** |
| …dont **PAYÉES** (donc inannulables par un caissier) | **486** |
| Commandes non terminées scellées dans un Z clos (aucun compte ne peut les annuler) | **73** |

Autrement dit : sur les commandes qui traînent, **un caissier seul ne peut en clore aucune des
486 payées**. Il doit appeler un responsable — de jour comme de nuit.

## 3. Ce qui a DÉJÀ été corrigé sans toucher aux droits

Le vrai défaut immédiat n'était pas le refus : c'était le **mensonge de l'écran**. Le bouton
« Annuler » restait affiché, le clic partait, le serveur répondait 403 — sans dire pourquoi ni
quoi faire.

Le tableau de suivi dit maintenant la vérité, sans qu'aucune garde ne bouge :

- commande **payée** sans le droit de rembourser → marqueur inerte **« Responsable »**,
  infobulle : *« l'annuler rend l'argent, ce compte n'a pas le droit de remboursement »* ;
- commande **scellée dans un Z** → marqueur **« Clôturé »**, et pour qui porte `pos-refund`,
  le bouton **« Rembourser »** (contrepartie NF525) à la place d'« Annuler ».

C'est vrai pour **tous les rôles** et ne présume d'aucune décision ci-dessous.

## 4. Les trois options

### A — Ne rien changer (statu quo)
Le caissier appelle un responsable pour toute annulation d'une commande payée.
- ✅ Aucun risque nouveau. L'écran ne ment plus, donc le blocage est au moins compris.
- ❌ 486 commandes payées restent hors de portée du comptoir ; friction réelle en service.

### B — Accorder `pos-refund` au rôle Caissier
- ✅ Le comptoir se débrouille seul, jour et nuit.
- ❌ **40 comptes** gagnent le droit de sortir de l'argent du tiroir sans contrôle. C'est
  exactement le vecteur que le seeder nomme. Chaque geste reste tracé (audit NF525 + motif
  obligatoire), mais **rien ne le plafonne**.

### C — Accorder `pos-refund` AVEC un plafond quotidien *(recommandée si la friction gêne)*
Le dépôt a déjà tranché ce dilemme une fois, exactement de cette façon : le ticket promo a été
ouvert au caissier le 2026-08-13 **non pas** en levant la garde, mais en la remplaçant par un
plafond applicatif — `PromoFlyerService::DAILY_CAP_PER_USER = 40`, Admin exempté, migration
dédiée `2026_08_13_190000_grant_pos_flyer_print_to_cashier.php`.
- ✅ Le comptoir se débrouille pour les cas normaux ; l'abus de masse reste impossible.
- ❌ Demande un petit développement (compteur journalier par utilisateur + message de refus).
- ⚠️ Le plafond doit se compter en **euros remboursés par jour**, pas en nombre de gestes —
  10 annulations à 8 € et une seule à 400 € ne sont pas le même risque.

## 5. Si la réponse est B — la commande exacte

```bash
php artisan tinker --execute='
$r = \Spatie\Permission\Models\Role::where("name","POS Operator")->firstOrFail();
$r->givePermissionTo("pos-refund");
echo "accordé — comptes concernés : ".\App\Models\User::role("POS Operator")->count().PHP_EOL;'
```

⚠️ Une commande tinker ne survit pas à un `db:seed --class=RolePermissionTableSeeder`. Pour que
ce soit **durable**, il faut aussi ajouter `'pos-refund'` à `$posOperatorManagerPermissionNames`
(`database/seeders/RolePermissionTableSeeder.php`) **et** écrire une migration dédiée, sur le
modèle exact de `2026_08_13_190000_grant_pos_flyer_print_to_cashier.php`. Sans ces deux gestes,
le droit disparaîtra au prochain redéploiement — silencieusement.

Aucun de ces gestes n'a été fait. Ils attendent la décision.

## 6. Ce qui reste hors de portée quelle que soit la réponse

Les **73 commandes scellées dans un Z clos** ne seront annulables par personne, jamais : NF525
l'interdit et c'est correct. Leur seule sortie légitime est la contrepartie comptable
(« Rembourser »), désormais proposée depuis le tableau de suivi aux comptes qui en ont le droit.
