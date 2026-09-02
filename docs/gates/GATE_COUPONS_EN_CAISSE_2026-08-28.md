# GATE — Les codes promo en caisse : un interrupteur, ou deux ?

**Date :** 2026-08-28 · **Mission :** ONB-09 (animation commerciale)
**Statut :** ⛔ EN ATTENTE DE DÉCISION PROPRIÉTAIRE
**Nature :** décision commerciale — l'obstacle fiscal, lui, a disparu

---

## 1. Ce qui se passe aujourd'hui, du point de vue de l'exploitant

Vous créez un code promo. Vous mettez `POS_COUPON_CODES_ENABLED=true`, parce que
`config/pos.php` annonçait que ce drapeau autorise « le pré-contrôle **et
l'application** d'un coupon, que les remises manuelles soient coupées ou non ».

Le client présente son code. Il est **accepté** au pré-contrôle. Puis **refusé** au
moment de payer, avec :

> « Les remises (manuelle, coupon, fidélité) sont désactivées en V1 »

Le code promo ne sert donc à rien tant que les **remises libres au comptoir** ne sont
pas ouvertes — c'est-à-dire exactement ce que le drapeau avait été créé pour éviter.

---

## 2. Vérification, ligne à ligne

**Ce que le drapeau commande vraiment** — 4 lecteurs, tous en amont du paiement :

| Fichier | Rôle |
|---|---|
| `app/Http/Controllers/Frontend/CouponController.php:55` | pré-contrôle public d'un code |
| `app/Services/FrontendOrderService.php:1099` | surface borne / web |
| `app/Services/Wheel/WheelService.php:813` | émission d'un coupon par la roue |
| `app/Http/Controllers/Admin/PromoFlyerController.php:83` | ticket promo nominatif |

**Ce qu'il ne commande pas** — l'application :

```php
// app/Services/OrderService.php:3700
private function assertDiscretionaryDiscountAllowed(float $discount): void
{
    if ($discount > 0.0 && config('pos.manual_discount_enabled') !== true) {
        throw ValidationException::withMessages([...]);
    }
}
```

Sept sites d'appel (461, 613, 954, 1169, 1645, 1814 et le corps). `grep -c
coupon_codes_enabled app/Services/OrderService.php` → **0**.

**Ce n'est pas un défaut du garde.** `plans/LOCK_ZREPORT_F1_DISCOUNT_NETTING_2026_05_31.md
§7` désigne explicitement `manual_discount_enabled` comme l'interrupteur **unique**
d'activation. Le garde applique une décision prise ; c'est le commentaire de
`config/pos.php` qui promettait autre chose. **Cette promesse a été corrigée le
2026-08-28** — un réglage qui ment coûte plus cher qu'un réglage absent.

---

## 3. Le point qui change tout : l'obstacle fiscal n'existe plus

Le docblock du garde justifie le refus ainsi (`OrderService.php:3715-3722`, daté du
**2026-05-30**) :

> *« At 10% VAT the discount→HT/TVA split in the FROZEN ZReportService/PricingService
> is wrong (the F1 defect). Until F1 is fixed under a lock-plan, ANY non-zero manual
> POS discount is refused so no discounted order can sign a wrong Z. »*

**F1 a été corrigé le lendemain**, le 2026-05-31, sous clé propriétaire accordée :

> *« Owner gate: GRANTED 2026-05-31 — owner chose "Fixer F1 maintenant sous
> lock-plan" (re-split TVA on the post-discount base, **re-enable coupons+loyalty**)
> over keeping discounts disabled. »*

`ZReportDiscountNettingTest` le prouve : **5 tests, 23 assertions, verts** (relancés
le 2026-08-28). La TVA par taux est mise à l'échelle du ratio
`(sous-total − remise) / sous-total`, ce qui revient à répartir la remise
proportionnellement puis à recalculer la TVA sur la base nette.

**Autrement dit : le garde survit à sa propre justification, périmée d'un jour.**
Et la décision citée dans la clé — « réactiver coupons + fidélité » — n'a jamais été
mise en œuvre côté caisse.

---

## 4. Trois options

### Option A — Découpler (ce que la clé de mai avait décidé)

Faire lire `pos.coupon_codes_enabled` par le garde, en distinguant l'origine de la
remise : un coupon passe, une remise libre reste refusée.

- **Coût :** la signature de `assertDiscretionaryDiscountAllowed(float $discount)` ne
  transporte pas l'origine. Il faut lui passer un contexte (`'coupon' | 'manuel' |
  'fidelite'`) sur les 7 sites d'appel. Environ une demi-journée, plus les bancs.
- **Risque :** un site d'appel oublié laisse passer une remise libre. À couvrir par
  un banc par site, et par un banc négatif (remise manuelle toujours refusée).
- **Zone gelée :** aucune. `OrderService` n'est pas dans §7 ; `PricingService` n'est
  pas touché — c'est un garde d'admission, pas un calcul.
- **Gagne :** exactement ce que l'exploitant voulait — des codes nominatifs sur
  ticket, sans remises libres au comptoir.

### Option B — Ouvrir les remises entièrement

`POS_MANUAL_DISCOUNT_ENABLED=true`. Zéro ligne de code.

- **Coût :** aucun développement.
- **Risque :** rouvre la remise **arbitraire** en caisse — un caissier saisit le
  montant qu'il veut. C'est précisément le risque que le drapeau coupons devait
  éviter. La traçabilité existe (`OrderDiscountLog`) mais le contrôle a priori, non.

### Option C — Ne rien faire

Le drapeau reste un pré-contrôle sans application. Le commentaire ne ment plus, donc
personne ne perd de temps à comprendre pourquoi ça ne marche pas.

- **Coût :** aucun. **Perte :** les codes promo, la roue et le ticket nominatif
  restent des vitrines : ils émettent des coupons que la caisse refusera.

---

## 5. Recommandation

**Option A**, et en deux temps.

D'abord parce que c'est la décision **déjà prise** en mai — la clé dit « re-enable
coupons+loyalty », et seule la moitié fiscale a été livrée. Ensuite parce que
l'obstacle invoqué n'existe plus, et qu'il est prouvé qu'il n'existe plus.

Le premier temps est mécanique et sûr : passer l'origine de la remise au garde, sans
changer son comportement (tout reste refusé). Un banc par site d'appel prouve que
l'origine arrive juste. Le second temps est une ligne : autoriser l'origine
`coupon` quand le drapeau est vrai.

Séparer les deux permet de vérifier la mécanique avant d'ouvrir quoi que ce soit —
et de s'arrêter entre les deux si le résultat ne convainc pas.

⚠️ **Ce que je ne fais pas sans votre signature :** toucher au garde. Il refuse
aujourd'hui des remises que la loi de finances rendrait fausses si F1 n'avait pas été
corrigé. Il l'a été, c'est prouvé — mais l'arbitrage « qui peut accorder une remise,
et de quelle nature » vous appartient, pas au code.

---

## 6. Ce qui a été fait sans attendre

- **La promesse fausse de `config/pos.php` est retirée** (2026-08-28). Elle décrit
  désormais les 4 lecteurs réels, dit ce que le drapeau ne commande pas, et renvoie
  ici. Corriger une affirmation fausse n'est pas une décision d'architecture.
- **Le banc `LeDrapeauCouponsDitCeQuIlFait`** épingle le comportement actuel : si
  quelqu'un fait lire le drapeau par la caisse sans passer par ce dossier, il vire au
  rouge et renvoie ici.
