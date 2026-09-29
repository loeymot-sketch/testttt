# LOCK — un coupon accepté au devis doit l'être au paiement

**Fichiers gelés visés** :
- `app/Services/Pricing/DiscountCalculator.php` (CLAUDE.md §7 — zone `Pricing`, SSOT des prix)
- `app/Services/Pricing/PricingService.php` (idem)

**Date** : 2026-08-27
**Gate** : **G-PRIX-COUPON**
**Demandé par** : personne encore. Ce document est écrit *avant* la demande, pour que la décision
du propriétaire porte sur un correctif chiffré et non sur une intention.
**État** : ⏳ **EN ATTENTE DE CONTRESIGNATURE**

---

## 1. Le défaut, en une phrase

Un coupon restreint à une surface — « ce code ne marche qu'à la borne » — est **refusé au devis**,
alors que l'écran de vérification du code, lui, le déclare valide. Le commerçant crée une promotion
qui fonctionne quand il la teste, et échoue quand un client l'utilise.

## 2. La cause, vérifiée ligne par ligne

`CouponService::resolveCouponById()` accepte **cinq** paramètres — les deux derniers étant la
filiale et la surface, avec des valeurs par défaut nulles :

```php
// app/Services/CouponService.php:392
public function resolveCouponById(int $couponId, float $subtotal, int $userId,
                                  ?int $branchId = null, ?string $surface = null): Coupon
```

`DiscountCalculator::couponDiscount()` l'appelle avec **trois** :

```php
// app/Services/Pricing/DiscountCalculator.php:17
$coupon = $couponService->resolveCouponById($couponId, $subtotal, $customerUserId);
```

La surface arrive donc toujours à `null`. Et dans `Coupon` (`:147-149`), un coupon qui déclare des
surfaces **rejette** systématiquement une surface nulle :

```php
if ($surface === null || !in_array(strtolower((string) $surface), ..., true)) { /* refus */ }
```

**Le plus notable** : `PricingRequest` porte déjà les deux informations — `$branchId` (`:14`) et
`$context` (`:16`). Elles existent, elles arrivent jusqu'au calcul, et l'appel les jette.

Ce n'est pas un manque de conception. C'est un raccordement oublié.

## 3. Ce que le projet en sait déjà

Un test existe pour ce cas exact — `tests/Feature/Coupon/CouponSurfaceEnforcedAtCommitTest.php` —
et il est **désactivé**, avec pour motif écrit :

> *BLOCKED by FROZEN PricingService/DiscountCalculator: the coupon is validated via
> `DiscountCalculator::couponDiscount()` with surface=null (3 args), refusing a matching
> restricted coupon*

Quelqu'un a vu le défaut, écrit le test, et l'a mis en veille parce que le corriger exige cette
signature. Ce LOCK existe pour la lui donner.

## 4. Périmètre — volontairement minuscule

**Autorisé :**
1. Ajouter deux paramètres optionnels à `DiscountCalculator::couponDiscount()` :
   `?int $branchId = null, ?string $surface = null`, transmis tels quels à `resolveCouponById()`.
2. Dans `PricingService`, passer `$req->branchId` et `$req->context` à cet appel.

**Rien d'autre.** Aucune ligne du calcul de prix n'est touchée : ni les taxes, ni les arrondis, ni
l'ordre d'application des remises, ni la construction du `composition_snapshot`.

**Interdit explicitement :**
- modifier le montant calculé d'une remise ;
- modifier la validation d'un coupon *sans* surface déclarée — ceux-là doivent continuer à passer
  partout, exactement comme aujourd'hui ;
- toucher `assertComposerStepConstraints`, la logique fiscale, ou quoi que ce soit d'autre dans ces
  deux fichiers.

## 5. Le point qui demande de l'attention, et qu'il ne faut pas survoler

`PricingRequest::$context` vaut `'pos'`, `'table'`, `'web'`… tandis que les surfaces déclarées sur
un coupon sont libres (colonne `surfaces`, tableau). **Rien ne garantit aujourd'hui que les deux
vocabulaires coïncident** — un coupon marqué `['kiosk']` ne reconnaîtrait pas un contexte `'web'`.

Le correctif doit donc s'accompagner d'une **table de correspondance explicite** contexte → surface,
écrite et testée, et non d'un passage direct de la chaîne. Passer `$req->context` tel quel
transformerait un défaut silencieux en un autre défaut silencieux.

C'est la seule vraie difficulté de ce correctif, et c'est pour ça qu'il n'a pas été fait à la volée.

## 6. Le filet

**Avant toute modification** : la ligne de base des prix est déjà posée —
`tests/Feature/Pricing/LigneDeBasePrixTest.php`, 12 cas, 47 assertions, et **elle mord** (vérifié :
un centime de décalage la fait rougir). Aucun total ne peut bouger sans qu'elle le dise.

**Après** : réactiver `CouponSurfaceEnforcedAtCommitTest` — les six tests qu'il contient sont déjà
écrits, ils n'attendent que la levée du `markTestSkipped`.

**Rollback** : les deux modifications sont additives (paramètres optionnels). Revenir en arrière,
c'est retirer deux arguments d'un appel.

## 7. Ce que ça change pour le commerçant

Aujourd'hui : il crée « BIENVENUE, 10 %, borne uniquement », l'écran de vérification lui dit que le
code est valide, et le client se voit refuser la remise à la borne. Le commerçant conclut que le
logiciel ment — et il a raison de le penser.

Après : le coupon fait ce qu'il annonce.

---

**Contresignature propriétaire :** ☐ *(à cocher par le propriétaire — je ne signe pas à sa place)*

**Ce qui se passe une fois cochée** : le correctif tient en quatre lignes, la table de
correspondance du §5 en une dizaine, et six tests déjà écrits repassent au vert.
