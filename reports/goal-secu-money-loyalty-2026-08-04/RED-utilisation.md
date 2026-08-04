# RED-TEAM — UTILISATION DES POINTS (redeem / redemption)

**Date** : 2026-08-04
**Mode** : audit adversarial **READ-ONLY** (aucun fichier applicatif modifié)
**Périmètre** : dépense des points en remise — borne, web, caisse
**Dépôts** : `foodking-web/web/testttt` (backend) + `lecayenne-web-deploy/Site lecayenne` (site)
**Harnais de preuve** : PHPUnit 9.6.29 / sqlite `:memory:` — **14 tests, 65 assertions, 14/14 GREEN**
(fichier de repro reproduit intégralement en Annexe A)

```
OK (14 tests, 65 assertions)   Time: 00:03.601
```

---

## VERDICT

**BLOCK** sur le chemin « pré-rachat de points » (`POST /api/frontend/loyalty/redeem`).

Le cœur arithmétique est **sain** : impossible de dépenser plus que son solde, impossible
de fabriquer une remise, impossible d'obtenir un total négatif, impossible de cumuler
coupon + points, remboursement idempotent. **5 attaques sur 7 sont RÉFUTÉES.**

Mais le **couplage entre le pré-rachat et la création de commande est cassé dans les deux
sens** : il produit soit une commande **plein tarif avec les points quand même débités**
(RED-1), soit un **double débit pour une seule remise** (RED-2), et il ouvre un **contournement
de la garde anti-vol IDOR Mission-28** (RED-3). Le seul filet est un cron (`reapOrphanRedemptions`)
qui met **30 à 35 minutes** à rendre les points — et dont la panne en production est un
incident déjà survenu (scheduler VPS mort, réparé le 2026-07-27).

| # | Sévérité | Titre | Preuve |
|---|----------|-------|--------|
| RED-1 | **P1** | Pré-rachat du solde utile → commande **PLEIN TARIF**, points partis, remise perdue | test vert |
| RED-1b | **P1** | L'annulation de cette commande **ne rend pas** les points (`refundPoints` no-op) | test vert |
| RED-1d | **P1** | Le **seal borne ne peut pas rattraper** RED-1 (il recalcule avec la même fonction cassée) | test vert |
| RED-2 | **P1** | Pré-rachat client web/mobile (`source_surface='pos'`) **non rattachable** → **double débit** | test vert |
| RED-3 | **P1 sécu** | La branche « rattachement pending » **court-circuite la garde IDOR Mission-28** | test vert |
| RED-4 | **P2** | `/loyalty/redeem` **ignore `min_redeem_points`** → débit garanti non consommable | test vert |
| RED-5 | **P2** | Le filet unique est un cron à fenêtre 30 min + cadence 5 min (**~35 min** de points perdus) | test vert |
| REFUTED-1..6 | — | sur-dépense, total négatif, coupon+points, remise fabriquée, double remboursement, double dépense séquentielle | tests verts |

---

## RED-1 — [P1] Le client rachète ses points, paie PLEIN TARIF, et ses points sont partis

**`app/Services/FrontendOrderService.php:1035-1053`** (contrôle de solde) **avant**
**`app/Services/FrontendOrderService.php:1055-1086`** (rattachement de la ligne pré-rachetée)
+ **`app/Services/Pricing/DiscountCalculator.php:63-68`**

### Scénario

1. La borne (ou le mobile) rachète les points du client via `POST /api/frontend/loyalty/redeem` :
   le solde est **débité immédiatement** et une ligne `type='redeem', order_id=NULL` est écrite
   (`LoyaltyController.php:397-413`).
2. La commande est postée avec `loyalty_code` + `discount`.
3. `applyKioskLoyaltyDiscount` **vérifie d'abord le solde**, qui est désormais celui d'APRÈS
   le débit. Si le client a racheté plus de la moitié de son solde (cas normal : « j'utilise
   tous mes points »), le solde restant est **inférieur** aux points requis →
   `DiscountCalculator.php:66` renvoie `points=0, discount=0` →
   `FrontendOrderService.php:1045-1053` **`return`** — **la branche de rattachement des lignes
   L1055-1086 n'est jamais atteinte**.
4. La commande est scellée **au plein tarif**, la ligne de rachat reste orpheline.

### Preuve (sortie du test, verbatim)

```
[RED-1] order.discount=0.000000 order.total=20.000000 balance=0 pendingRows=1 attachedRows=0
```

Client à 100 pts → rachat 100 pts (= 1,00 €) accepté (HTTP 200, solde 0) → commande 20,00 €
annoncée à 19,00 € → **`order.discount = 0.00`, `order.total = 20.00`**, solde **0**,
ligne de rachat **toujours orpheline**.

### Le code coupable (extrait exact)

```php
// FrontendOrderService.php:1035
$redemption = $this->discountCalculator->kioskLoyaltyRedemption(
    $validatedCoupon, $loyaltyCode, $requestedDiscount, $realSubtotal, $loyaltyUser
);
$maxDiscount    = (float) $redemption['discount'];
$pointsRequired = (int)   $redemption['points'];

if ($pointsRequired <= 0 || $maxDiscount <= 0.0) {
    Log::warning('[Loyalty] Redemption skipped after locked balance check', [...]);
    return;                       // <-- L1052 : sortie AVANT le rattachement
}

$pendingRedeem = LoyaltyTransaction::query()   // <-- L1055 : jamais atteint dans ce cas
    ->where('user_id', $loyaltyUser->id)
    ...
```

`DiscountCalculator.php:66` :
```php
if ($lockedLoyaltyUser->loyalty_points < $pointsRequired) {
    return ['discount' => 0.0, 'points' => 0];   // le solde a DÉJÀ été débité en amont
}
```

### Piège méthodologique confirmé — un test vert qui encode le bug

`tests/Feature/KioskLoyaltyDoubleRedeemRefusedTest.php:87-117`
(`test_redeem_then_kiosk_order_joins_existing_transaction_without_second_decrement`) est **vert**
et prétend couvrir exactement ce flux. Il ne passe que parce que la fixture donne **500 pts** au
client et n'en rachète que **100** : il reste 400 ≥ 100, donc le contrôle de solde passe par
hasard et la branche de rattachement s'exécute. Le test **encode le seul cas qui marche** et
masque tous les autres. La condition de casse est exactement :
`solde_initial − points_rachetés < points_rachetés`, c'est-à-dire **tout rachat de plus de la
moitié du solde** — le cas d'usage le plus courant.

### Repro

```bash
php vendor/bin/phpunit -c phpunit.xml <annexe A> --filter test_RED1_full_balance_preredeem_then_order_is_charged_full_price
```

---

## RED-1b — [P1] L'annulation de cette commande ne rend PAS les points

**`app/Services/LoyaltyService.php:27-33`**

`refundPoints` cherche les lignes de rachat **par `order_id`** :

```php
$redeemTxns = LoyaltyTransaction::where('order_id', $order->id)->where('type', 'redeem')->get();
if ($redeemTxns->isEmpty()) { return; }
```

Dans le scénario RED-1 la ligne est restée `order_id = NULL` → **aucune ligne trouvée → `return`**.
La commande porte pourtant bien `loyalty_customer_code = 'REDVICTIM1'` (posé par
`FrontendOrderService.php:604-606`), donc le garde-fou d'entrée passe : l'appel **paraît**
avoir travaillé.

### Preuve

```
[RED-1b] loyalty_customer_code='REDVICTIM1' balance_apres_refund=0
```

Tous les chemins d'annulation sont concernés — ils appellent tous le même `refundPoints` :
`FrontendOrderService.php:840` (annulation client), `FrontendOrderService.php:925`
(webhook Mollie échec), `OrderService.php:2274` et `:2487` (caisse),
`CleanupStalePendingKioskOrders.php:288` et `:375`, `PaymentService.php:846`,
`RefundWithCounterEntryService.php:447`. **Aucun ne peut rendre les points de RED-1.**

---

## RED-1d — [P1] Le seal borne ne peut pas rattraper RED-1

**`app/Services/Order/OrderQuoteService.php:111-127`** + **`:345-401`**

On pourrait croire que `sealForCommit` (409 « Order total does not match sealed quote total »)
protège la borne. **Non** : le quote est **recalculé côté serveur** par
`withKioskLoyaltyDiscount` (`OrderQuoteService.php:365`), qui appelle **la même**
`kioskLoyaltyRedemption` sur **le même solde déjà débité**. Le quote conclut donc lui aussi
« remise = 0 » et scelle un total plein tarif **identique** au total de la commande → la
comparaison `abs(quote - order) > 0.000001` est fausse → **aucun 409**.

### Preuve (vrai `OrderQuoteService`, double de test retiré)

```
[RED-1d] quote.discount=0.000000 quote.total_ttc=20.000000
```

La surfacturation passe **en silence** sur la borne comme sur le web. Côté web, la garde
optionnelle `expected_total` (`FrontendOrderService.php:580-589`) ne se déclenche que si le
client daigne l'envoyer.

---

## RED-2 — [P1] Le pré-rachat d'un client web/mobile est structurellement non rattachable → double débit

**`app/Http/Controllers/Frontend/LoyaltyController.php:411`** vs
**`app/Services/FrontendOrderService.php:1059`**

`LoyaltyController::redeem` étiquette la ligne selon l'appelant :

```php
'source_surface' => $isKiosk ? 'kiosk' : 'pos',     // L411
```

`$isKiosk` exige une **vraie ligne `KioskMachine`** (`LoyaltyController.php:349-353`). Un client
authentifié qui rachète **ses propres** points (chemin propriétaire, web/mobile) n'est pas une
borne → sa ligne est marquée **`'pos'`**.

Or le **seul** consommateur de ces lignes filtre `'kiosk'` :

```php
// FrontendOrderService.php:1055-1064
$pendingRedeem = LoyaltyTransaction::query()
    ->where('user_id', $loyaltyUser->id)
    ->where('type', 'redeem')
    ->where('source_surface', 'kiosk')       // <-- L1059 : exclut toute ligne web/mobile
    ->whereNull('order_id')
    ->where('created_at', '>=', now()->subMinutes(10))
```

→ la ligne n'est **jamais** vue → la création de commande effectue un **second débit frais**
(`FrontendOrderService.php:1113-1122`).

### Preuve

```
[RED-2] ligne pré-rachat source_surface=pos order_id=NULL balance=400
[RED-2] apres commande: balance=300 discount=1.000000 redeemRows=2
```

Solde 500 → 400 (pré-rachat 100) → **300** après la commande : **200 points brûlés pour 1,00 €
de remise**, 2 lignes `redeem`. Le premier débit ne revient qu'avec le reaper (~35 min).

### Note d'exploitabilité

Le site web a **neutralisé son propre bouton** le 2026-07-31
(`Site lecayenne/screens.jsx:761-776`, commentaire « FIX MISSION-3 ») — mais :
- l'endpoint `POST /api/frontend/loyalty/redeem` est **toujours en ligne** (`routes/api.php:1587`),
- `api.js:749-769` expose toujours `loyaltyRedeem()`,
- le middleware `idempotency` posé sur cette route (commentaire `LCS-S-002`) documente
  explicitement un **client mobile** qui envoie `Idempotency-Key` sur ce chemin.

Le correctif web a **masqué le symptôme sur une seule surface**, pas la cause dans le backend.

---

## RED-3 — [P1 sécurité] La branche « rattachement pending » court-circuite la garde anti-vol IDOR (Mission-28)

**`app/Services/FrontendOrderService.php:1066-1086`** (rattachement, avec `return` L1085)
**avant** **`app/Services/FrontendOrderService.php:1088-1111`** (garde IDOR).

La garde Mission-28 (2026-07-31) refuse qu'un appelant débite un `loyalty_code` dont il n'est
« ni le propriétaire, ni la borne, ni le staff ». Elle est écrite **après** la branche de
rattachement, qui `return` avant de l'atteindre :

```php
if ($pendingRedeem) {
    ...
    $pendingRedeem->order_id = $this->frontendOrder->id;   // L1073 : la ligne de la VICTIME
    $pendingRedeem->save();
    $calculatedDiscount += $maxDiscount;                   // L1077 : la remise pour l'ATTAQUANT
    $this->loyaltyApplied = true;
    return;                                                // L1085 : sortie AVANT la garde
}

// [SEC MISSION-28/30 2026-07-31] Anti-vol de points (IDOR).   <-- L1088, jamais atteint
$callerId = (int) (Auth::id() ?? 0);
...
if (! $isKioskCaller && ! $isOwnerCaller && ! $isStaffCaller) { throw ... }
```

### Scénario prouvé

1. Un token **borne** (ou staff) rachète 100 pts sur le code de la victime (appel légitime au
   regard de `LoyaltyController.php:349-354`) → ligne pending `source_surface='kiosk'`, victime
   à 400 pts.
2. Dans les **10 minutes** (`FrontendOrderService.php:1061`), un **simple invité web**
   (token `kiosk:order`, **aucune** `KioskMachine`, **pas** staff, **pas** propriétaire du code)
   poste une commande avec le `loyalty_code` **d'autrui** et le montant de remise **exact**.
3. La ligne de la victime est **rattachée à la commande de l'attaquant**, qui encaisse la remise.

### Preuve

```
[RED-3]  http=201
[RED-3]  order.user_id=3  attacker=3  order.discount=1.000000  order.total=19.000000
         txn.user_id=2   txn.order_id=1
[RED-3b] http=422  balance=500  orders=0
```

`order.user_id = 3` (attaquant) ; `txn.user_id = 2` (**victime**) ; la ligne de rachat de la
victime porte l'`order_id` de la commande de l'attaquant. Le **contrôle RED-3b** prouve que la
garde fonctionne partout ailleurs : **sans** ligne pending, la même requête est refusée **422**,
0 point débité, 0 commande créée. La garde existe, elle est simplement **placée après la porte**.

### Limites honnêtes de l'exploitation

- Exige une ligne pending `source_surface='kiosk'` pour la victime, donc un **token borne ou un
  compte staff** (l'UI borne actuelle n'appelle pas `/redeem`, elle envoie `loyalty_code` +
  `discount` directement — `KioskLoyaltyComponent.vue:557-575`).
- Fenêtre de 10 minutes, et le montant de remise doit **égaler exactement** les points pré-rachetés
  (`FrontendOrderService.php:1067-1071`).
- À chaîner avec le backlog connu « identifiants borne publics » (`kiosk123` + apiKey), ce qui
  rend la première étape atteignable.

---

## RED-4 — [P2] `/loyalty/redeem` ignore `min_redeem_points` → débit garanti non consommable

**`app/Http/Controllers/Frontend/LoyaltyController.php:379-395`** (aucune lecture de
`loyalty_min_redeem_points`) vs **`app/Services/Pricing/DiscountCalculator.php:58-65`** (l'applique).

`redeem()` ne contrôle que : `points > 0`, multiple du taux, solde suffisant. Le **plancher**
configuré par l'exploitant n'existe que dans `config()` (`LoyaltyController.php:510`, exposé à
l'UI) et dans le calculateur de la commande. Dès que l'exploitant règle
`loyalty_min_redeem_points` **au-dessus** du taux, `/redeem` accepte et **débite** un montant que
le chemin commande **refusera toujours**.

### Preuve (`min_redeem_points = 500`, taux 100)

```
[RED-4] redeem http=200 balance=400              <-- 100 pts débités sous le plancher
[RED-4] order.discount=0.000000 total=20.000000 balance=400
```

---

## RED-5 — [P2] Le seul filet est un cron : 30 min de fenêtre + 5 min de cadence

**`app/Services/LoyaltyService.php:238-262`** · **`config/loyalty.php:119`** ·
**`app/Console/Kernel.php:136-139`**

```
[RED-1c] reap@29min=0  reap@31min=1  balance=100  window=30min  cron=everyFiveMinutes
```

`reapOrphanRedemptions` ne touche **rien** avant 30 minutes et le job tourne toutes les 5 min :
les points de RED-1/RED-2 sont indisponibles **jusqu'à ~35 minutes**. Pendant cette fenêtre le
client se voit refuser un second usage et n'a aucune trace côté écran.

Aggravant : ce filet **dépend entièrement du scheduler**, dont la panne silencieuse en production
est un incident **déjà survenu** (cron VPS `Permission denied`, jamais exécuté, réparé le
2026-07-27). Scheduler mort ⇒ RED-1 et RED-2 deviennent des **pertes définitives**.

---

## ATTAQUES RÉFUTÉES (garde confirmée par test vert)

### REFUTED-1 — Dépenser plus que le solde : **impossible**
`LoyaltyController.php:369` (`lockForUpdate`) + `:393` (`loyalty_points < $pointsToRedeem` → 400
« Points insuffisants ») ; `DiscountCalculator.php:66` côté commande.
```
[REFUTED-1] discount=0.000000 total=20.000000 balance=500
```
Rachat de 600 sur 500 → **400**, 0 point débité. `points=-100` → **422** (`min:1`,
`LoyaltyController.php:53`). Remise demandée de 50,00 € sur un solde de 5,00 € → remise 0,
solde intact, **jamais de solde négatif**.

**Moment du débit** (question posée) : à la **création de la commande**, dans la transaction de
création (`FrontendOrderService.php:1115-1122`), **avant tout paiement** — ou plus tôt encore via
le pré-rachat. Les chemins d'échec de paiement remboursent bien : Mollie terminal non abouti →
`FrontendOrderService.php:908-955` (`cancelForFailedOnlinePayment` → `refundPoints` L925) ;
abandon → `CleanupStalePendingKioskOrders.php:190-212` (web, TTL 360 min) et `:288`. **Sauf**
dans le cas RED-1b où il n'y a rien à rembourser.

### REFUTED-2 — Remise > total : **plancher à 0, jamais de total négatif**
`DiscountCalculator.php:49` (`min($requestedDiscount, $realSubtotal)`) +
`FrontendOrderService.php:562/564` (`round(max(0, ...), 2)`).
```
[REFUTED-2] subtotal=20.000000 discount=20.000000 total=0.000000 balance=98000
```
Remise demandée 999 € sur 20 € → plafonnée à 20,00 € → total **0,00 €**, exactement 2000 points
débités pour 20 € de valeur. **Le client ne se fait jamais payer.**
Côté caisse, garde équivalente sur la remise **cumulée** : `PosRedemptionService.php:146-164`
(`DISCOUNT_EXCEEDS_SUBTOTAL`).

### REFUTED-3 — Coupon + points : **mutuellement exclusifs, garde backend tenue**
`FrontendOrderService.php:1014-1017` (sortie immédiate si `coupon_id > 0`) +
`DiscountCalculator.php:38-40` (double sécurité).
```
[REFUTED-3] http=201 balance=500 redeemRows=0
[REFUTED-3] discount=2.000000 total=18.000000
```
Payload forgé portant **à la fois** `coupon_id`, `discount=5.00` et `loyalty_code` → **seule** la
remise coupon (2,00 €) s'applique, **0 point débité, 0 ligne de rachat**. Pas de double remise,
pas de total absurde.

### REFUTED-4 — Remise fabriquée par le client : **ignorée**
```
[REFUTED-4] discount=0.000000 total=20.000000
```
`discount=15.00` sans `loyalty_code` → `FrontendOrderService.php:1010` sort immédiatement, le
total reste 100 % SSOT (`PricingService`). Le champ `discount` du client **n'est qu'une demande**,
plafonnée par le solde réel et par le sous-total. La remise appliquée est **toujours** celle
scellée backend.

**TVA/TTC** : les deux sentinelles tiennent — `PosRedemptionService.php:236-240` branche
TTC/HT (double-comptage corrigé) et `OrderQuoteService.php:383-388` aligne le quote sur la même
formule ; `tests/Feature/Loyalty/PosRedemptionTtcTaxDoubleCountSentinelTest.php` et
`KioskLoyaltyQuoteTtcNoDoubleTaxTest.php` restent verts. Aucun double-compte observé
(REFUTED-2 : `20.00 − 20.00 = 0.00`, la TVA n'est pas ré-ajoutée).

### REFUTED-5 — Double remboursement au rejeu : **idempotent**
`LoyaltyService.php:89-100` (détection préalable de la ligne `manual_add` **avant** toute
mutation) + index UNIQUE `(user_id, order_id, type)`.
```
[REFUTED-5] balance=500 manualAdd=1
```
3 appels consécutifs de `refundPoints` → **un seul** crédit, **une seule** ligne de reprise.
Le remboursement **par porteur** (`LoyaltyService.php:40-42`, correctif P0-2 du 2026-08-01) est
confirmé : chaque ligne de rachat retourne à **son** `user_id` issu du grand-livre, jamais en bloc
au dernier `loyalty_customer_code`. Aucun crédit de points jamais débités : le montant provient
exclusivement des lignes `redeem` existantes.

### REFUTED-6 — Double dépense (2 commandes concurrentes) : **verrou tenu**
`LoyaltyController.php:368-369` et `FrontendOrderService.php:1026-1029` prennent tous deux un
`lockForUpdate` sur la ligne client **avant** le contrôle de solde, dans la transaction ;
`PosRedemptionService.php:117-131` de même.
```
[REFUTED-6] A.discount=1.000000 B.discount=0.000000 balance=0 redeemRows=1
```
Deux commandes successives sur le même code avec 100 pts : la première obtient 1,00 €, la
seconde **0,00 €**, solde **0** (jamais négatif), **une seule** ligne de rachat.

---

## SYNTHÈSE DE LA CAUSE RACINE

Un seul défaut de conception produit RED-1, RED-2 et RED-3 :

> `applyKioskLoyaltyDiscount` traite le **pré-rachat déjà payé** comme s'il s'agissait d'un
> **rachat à venir**. Il fait passer, dans l'ordre : (1) le contrôle de solde — qui compte des
> points **déjà** dépensés comme s'ils devaient l'être encore ; (2) le rattachement de la ligne
> pré-rachetée — filtré sur une surface que le producteur réel n'émet pas ; (3) la garde anti-vol
> — que le rattachement a déjà court-circuitée par `return`.

L'ordre correct est l'inverse : **garde d'autorisation → recherche de la ligne pré-rachetée →
contrôle de solde uniquement pour un débit *frais*.**

---

## REMÉDIATION (non appliquée — audit read-only)

`app/Services/FrontendOrderService.php` est une **zone partagée §6** (`SYSTEM_MAP.md:103`,
`CONSTITUTION.md:47`) : toute correction exige un **LOCK doc + gate owner**, pas une voie isolée.

1. **RED-3 d'abord (sécurité)** — remonter le bloc `FrontendOrderService.php:1088-1111` **avant**
   la recherche de `$pendingRedeem` (L1055). Correctif de 4 lignes déplacées, aucun changement de
   logique métier. Sentinelle : le contrôle RED-3b doit rester 422 **et** RED-3 doit passer de
   201 à 422.
2. **RED-1** — inverser l'ordre : chercher `$pendingRedeem` **avant** d'appeler
   `kioskLoyaltyRedemption`, et ne soumettre au contrôle de solde que le **reliquat** à débiter
   frais (0 quand une ligne pending couvre déjà la remise).
3. **RED-2** — supprimer le filtre `->where('source_surface', 'kiosk')` (`:1059`) ou l'élargir à
   `['kiosk','pos']` ; **ou** faire porter à `LoyaltyController::redeem` la surface réelle de
   l'appelant plutôt que le binaire borne/`pos`.
4. **RED-4** — appliquer `loyalty_min_redeem_points` dans `LoyaltyController::redeem` (miroir de
   `DiscountCalculator.php:58-65`).
5. **Sentinelle anti-régression** — remplacer la fixture de
   `KioskLoyaltyDoubleRedeemRefusedTest` (500 pts / 100 rachetés) par un cas où le rachat
   **consomme tout le solde utile**, seul cas qui exerce vraiment la branche de rattachement.
6. **RED-5** — surveiller l'exécution du reaper (le journal doit grossir) et envisager d'abaisser
   `LOYALTY_ORPHAN_REDEEM_REAP_MINUTES` une fois 1-3 corrigés.

**Zéro modification appliquée. Zéro fichier frozen touché. Aucun test du dépôt exécuté en écriture.**

---

## ANNEXE A — Fichier de reproduction (14/14 vert)

À déposer n'importe où et lancer avec :

```bash
php vendor/bin/phpunit -c phpunit.xml <chemin>/RedUtilisationPointsTest.php
```

```php
<?php

namespace Tests\Feature\Loyalty;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\Source;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\KioskMachine;
use App\Models\LoyaltyTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

class RedUtilisationPointsTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $kioskUser;
    protected User $customer;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->bypassSeal();

        Settings::group('order_setup')->set([
            'order_setup_food_preparation_time' => 30,
            'order_setup_delivery' => 5,
            'order_setup_takeaway' => 5,
        ]);
        Settings::group('loyalty_setup')->set([
            'loyalty_points_for_1_euro_discount' => 100,
            'loyalty_min_redeem_points' => 50,
            'loyalty_points_per_euro' => 10,
        ]);
        config(['pos.manual_discount_enabled' => true, 'pos.loyalty_enabled' => true]);

        $this->branch = Branch::factory()->create(['status' => Status::ACTIVE]);
        $this->kioskUser = User::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => Status::ACTIVE,
        ]);
        KioskMachine::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->kioskUser->id,
            'status' => Status::ACTIVE,
            'is_login' => Ask::NO,
        ]);

        $category = ItemCategory::forceCreate([
            'name' => 'Red', 'slug' => 'red', 'status' => Status::ACTIVE,
        ]);
        $this->item = Item::forceCreate([
            'name' => 'Red Menu', 'slug' => 'red-menu', 'price' => 20,
            'status' => Status::ACTIVE, 'item_category_id' => $category->id,
        ]);

        $this->customer = User::factory()->create([
            'branch_id' => 0,
            'status' => Status::ACTIVE,
            'loyalty_code' => 'REDVICTIM1',
            'loyalty_points' => 500,
            'is_guest' => Ask::YES,
        ]);
    }

    private function bypassSeal(): void
    {
        $this->app->instance(\App\Services\Order\OrderQuoteService::class, new class {
            public function sealForCommit(): \App\Models\OrderQuote
            {
                return new \App\Models\OrderQuote();
            }
        });
    }

    private function payload(array $o = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id,
            'subtotal' => 20.00,
            'discount' => 0,
            'delivery_charge' => 0,
            'total' => 20.00,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => Ask::NO,
            'source' => Source::WEB,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'quote_token' => '00000000-0000-4000-8000-000000000001',
            'quote_signature' => str_repeat('a', 64),
            'items' => json_encode([[
                'item_id' => $this->item->id,
                'quantity' => 1,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ], $o);
    }

    // RED-1 : pré-rachat de TOUT le solde -> commande PLEIN TARIF + points partis
    public function test_RED1_full_balance_preredeem_then_order_is_charged_full_price(): void
    {
        $this->customer->forceFill(['loyalty_points' => 100])->save();

        Sanctum::actingAs($this->kioskUser, ['kiosk:order']);
        $this->postJson('/api/frontend/loyalty/redeem', [
            'code' => 'REDVICTIM1', 'points' => 100,
        ])->assertOk();

        $this->assertSame(0, (int) $this->customer->fresh()->loyalty_points);
        $this->assertSame(1, LoyaltyTransaction::where('type', 'redeem')->whereNull('order_id')->count());

        $res = $this->postJson('/api/frontend/order', $this->payload([
            'discount' => 1.00,
            'loyalty_code' => 'REDVICTIM1',
        ]));
        $res->assertCreated();
        $orderId = (int) $res->json('data.id');
        $order = FrontendOrder::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->find($orderId);

        fwrite(STDERR, "\n[RED-1] order.discount=".$order->discount." order.total=".$order->total
            ." balance=".$this->customer->fresh()->loyalty_points
            ." pendingRows=".LoyaltyTransaction::where('type','redeem')->whereNull('order_id')->count()
            ." attachedRows=".LoyaltyTransaction::where('type','redeem')->whereNotNull('order_id')->count()."\n");

        $this->assertEquals(0.0, (float) $order->discount, 'RED-1: remise fidélité PERDUE');
        $this->assertEquals(20.00, (float) $order->total, 'RED-1: client facturé plein tarif');
        $this->assertSame(0, (int) $this->customer->fresh()->loyalty_points, 'RED-1: points toujours débités');
        $this->assertSame(1, LoyaltyTransaction::where('type', 'redeem')->whereNull('order_id')->count(),
            'RED-1: la ligne de rachat reste ORPHELINE');
    }

    // RED-1b : l'annulation ne rend PAS les points
    public function test_RED1b_cancel_does_not_refund_the_unattached_preredeem(): void
    {
        $this->customer->forceFill(['loyalty_points' => 100])->save();
        Sanctum::actingAs($this->kioskUser, ['kiosk:order']);
        $this->postJson('/api/frontend/loyalty/redeem', ['code' => 'REDVICTIM1', 'points' => 100])->assertOk();

        $res = $this->postJson('/api/frontend/order', $this->payload([
            'discount' => 1.00, 'loyalty_code' => 'REDVICTIM1',
        ]));
        $res->assertCreated();

        $order = FrontendOrder::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->find((int) $res->json('data.id'));
        app(\App\Services\LoyaltyService::class)->refundPoints($order, 'kiosk');

        fwrite(STDERR, "\n[RED-1b] loyalty_customer_code=".var_export($order->loyalty_customer_code, true)
            ." balance_apres_refund=".$this->customer->fresh()->loyalty_points."\n");

        $this->assertSame(0, (int) $this->customer->fresh()->loyalty_points,
            'RED-1b: refundPoints ne rend rien');
    }

    // RED-2 : pré-rachat CLIENT (surface pos) -> double débit
    public function test_RED2_customer_selfredeem_is_double_debited_at_order_creation(): void
    {
        Sanctum::actingAs($this->customer, ['kiosk:order']);

        $this->postJson('/api/frontend/loyalty/redeem', [
            'code' => 'REDVICTIM1', 'points' => 100,
        ])->assertOk();

        $pending = LoyaltyTransaction::where('type', 'redeem')->first();
        fwrite(STDERR, "\n[RED-2] ligne pré-rachat source_surface=".$pending->source_surface
            ." order_id=".var_export($pending->order_id, true)
            ." balance=".$this->customer->fresh()->loyalty_points."\n");
        $this->assertSame('pos', $pending->source_surface);
        $this->assertSame(400, (int) $this->customer->fresh()->loyalty_points);

        $res = $this->postJson('/api/frontend/order', $this->payload([
            'discount' => 1.00, 'loyalty_code' => 'REDVICTIM1',
        ]));
        $res->assertCreated();
        $order = FrontendOrder::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->find((int) $res->json('data.id'));

        fwrite(STDERR, "[RED-2] apres commande: balance=".$this->customer->fresh()->loyalty_points
            ." discount=".$order->discount
            ." redeemRows=".LoyaltyTransaction::where('type','redeem')->count()."\n");

        $this->assertSame(300, (int) $this->customer->fresh()->loyalty_points,
            'RED-2: 200 points brûlés pour 1,00 € de remise');
        $this->assertSame(2, LoyaltyTransaction::where('type', 'redeem')->count());
        $this->assertEquals(1.00, (float) $order->discount);
    }

    // RED-3 : le rattachement pending contourne la garde IDOR Mission-28
    public function test_RED3_pending_attach_branch_bypasses_the_idor_guard(): void
    {
        Sanctum::actingAs($this->kioskUser, ['kiosk:order']);
        $this->postJson('/api/frontend/loyalty/redeem', [
            'code' => 'REDVICTIM1', 'points' => 100,
        ])->assertOk();
        $this->assertSame(400, (int) $this->customer->fresh()->loyalty_points);

        $attacker = User::factory()->create([
            'branch_id' => 0, 'status' => Status::ACTIVE, 'is_guest' => Ask::YES,
        ]);
        Sanctum::actingAs($attacker, ['kiosk:order']);

        $res = $this->postJson('/api/frontend/order', $this->payload([
            'discount' => 1.00,
            'loyalty_code' => 'REDVICTIM1',
            'order_type' => OrderType::TAKEAWAY,
        ]));

        fwrite(STDERR, "\n[RED-3] http=".$res->status()."\n");
        $res->assertCreated();
        $orderId = (int) $res->json('data.id');
        $order = FrontendOrder::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->find($orderId);
        $txn = LoyaltyTransaction::where('type', 'redeem')->first();

        fwrite(STDERR, "[RED-3] order.user_id=".$order->user_id." attacker=".$attacker->id
            ." order.discount=".$order->discount." order.total=".$order->total
            ." txn.user_id=".$txn->user_id." txn.order_id=".var_export($txn->order_id, true)."\n");

        $this->assertSame((int) $attacker->id, (int) $order->user_id);
        $this->assertEquals(1.00, (float) $order->discount);
        $this->assertSame((int) $orderId, (int) $txn->order_id);
        $this->assertSame((int) $this->customer->id, (int) $txn->user_id);
    }

    // RED-3b : contrôle — sans ligne pending, la garde refuse bien
    public function test_RED3b_control_without_pending_row_the_idor_guard_refuses(): void
    {
        $attacker = User::factory()->create([
            'branch_id' => 0, 'status' => Status::ACTIVE, 'is_guest' => Ask::YES,
        ]);
        Sanctum::actingAs($attacker, ['kiosk:order']);

        $res = $this->postJson('/api/frontend/order', $this->payload([
            'discount' => 1.00, 'loyalty_code' => 'REDVICTIM1', 'order_type' => OrderType::TAKEAWAY,
        ]));

        fwrite(STDERR, "\n[RED-3b] http=".$res->status()." balance=".$this->customer->fresh()->loyalty_points
            ." orders=".FrontendOrder::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->count()."\n");

        $this->assertSame(500, (int) $this->customer->fresh()->loyalty_points);
        $this->assertNotSame(201, $res->status());
    }

    // REFUTED-1 : sur-dépense
    public function test_REFUTED_overspend_is_rejected_everywhere(): void
    {
        Sanctum::actingAs($this->kioskUser, ['kiosk:order']);
        $this->postJson('/api/frontend/loyalty/redeem', ['code' => 'REDVICTIM1', 'points' => 600])
            ->assertStatus(400);
        $this->assertSame(500, (int) $this->customer->fresh()->loyalty_points);

        $this->postJson('/api/frontend/loyalty/redeem', ['code' => 'REDVICTIM1', 'points' => -100])
            ->assertStatus(422);

        $res = $this->postJson('/api/frontend/order', $this->payload([
            'discount' => 50.00, 'loyalty_code' => 'REDVICTIM1',
        ]));
        $res->assertCreated();
        $order = FrontendOrder::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->find((int) $res->json('data.id'));
        fwrite(STDERR, "\n[REFUTED-1] discount=".$order->discount." total=".$order->total
            ." balance=".$this->customer->fresh()->loyalty_points."\n");
        $this->assertGreaterThanOrEqual(0, (int) $this->customer->fresh()->loyalty_points);
        $this->assertGreaterThanOrEqual(0.0, (float) $order->total);
    }

    // REFUTED-2 : remise > total
    public function test_REFUTED_discount_over_total_is_floored_at_zero(): void
    {
        $this->customer->forceFill(['loyalty_points' => 100000])->save();
        Sanctum::actingAs($this->kioskUser, ['kiosk:order']);

        $res = $this->postJson('/api/frontend/order', $this->payload([
            'discount' => 999.00, 'loyalty_code' => 'REDVICTIM1',
        ]));
        $res->assertCreated();
        $order = FrontendOrder::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->find((int) $res->json('data.id'));

        fwrite(STDERR, "\n[REFUTED-2] subtotal=".$order->subtotal." discount=".$order->discount
            ." total=".$order->total." balance=".$this->customer->fresh()->loyalty_points."\n");

        $this->assertGreaterThanOrEqual(0.0, (float) $order->total);
        $this->assertLessThanOrEqual((float) $order->subtotal, (float) $order->discount);
    }

    // REFUTED-3 : coupon + fidélité
    public function test_REFUTED_coupon_and_loyalty_cannot_stack(): void
    {
        $coupon = \App\Models\Coupon::forceCreate([
            'name' => 'RED10', 'description' => '10 percent', 'code' => 'RED10',
            'discount' => 10, 'discount_type' => \App\Enums\DiscountType::PERCENTAGE,
            'start_date' => now()->subDay(), 'end_date' => now()->addDay(),
            'minimum_order' => 5, 'maximum_discount' => 5, 'limit_per_user' => 5,
            'status' => Status::ACTIVE,
        ]);

        Sanctum::actingAs($this->kioskUser, ['kiosk:order']);
        $res = $this->postJson('/api/frontend/order', $this->payload([
            'coupon_id' => $coupon->id,
            'discount' => 5.00,
            'loyalty_code' => 'REDVICTIM1',
        ]));

        fwrite(STDERR, "\n[REFUTED-3] http=".$res->status()
            ." balance=".$this->customer->fresh()->loyalty_points
            ." redeemRows=".LoyaltyTransaction::where('type','redeem')->count()."\n");
        if ($res->status() === 201) {
            $order = FrontendOrder::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                ->find((int) $res->json('data.id'));
            fwrite(STDERR, "[REFUTED-3] discount=".$order->discount." total=".$order->total."\n");
        }

        $this->assertSame(500, (int) $this->customer->fresh()->loyalty_points);
        $this->assertSame(0, LoyaltyTransaction::where('type', 'redeem')->count());
    }

    // REFUTED-4 : remise fabriquée sans points
    public function test_REFUTED_fabricated_discount_without_loyalty_is_ignored(): void
    {
        Sanctum::actingAs($this->kioskUser, ['kiosk:order']);
        $res = $this->postJson('/api/frontend/order', $this->payload(['discount' => 15.00]));
        $res->assertCreated();
        $order = FrontendOrder::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->find((int) $res->json('data.id'));
        fwrite(STDERR, "\n[REFUTED-4] discount=".$order->discount." total=".$order->total."\n");
        $this->assertEquals(0.0, (float) $order->discount);
        $this->assertEquals(20.00, (float) $order->total);
    }

    // REFUTED-5 : double remboursement au retry
    public function test_REFUTED_refund_is_idempotent_on_retry(): void
    {
        Sanctum::actingAs($this->kioskUser, ['kiosk:order']);
        $res = $this->postJson('/api/frontend/order', $this->payload([
            'discount' => 1.00, 'loyalty_code' => 'REDVICTIM1',
        ]));
        $res->assertCreated();
        $order = FrontendOrder::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->find((int) $res->json('data.id'));
        $this->assertSame(400, (int) $this->customer->fresh()->loyalty_points);

        $svc = app(\App\Services\LoyaltyService::class);
        $svc->refundPoints($order, 'kiosk');
        $svc->refundPoints($order, 'kiosk');
        $svc->refundPoints($order, 'kiosk');

        fwrite(STDERR, "\n[REFUTED-5] balance=".$this->customer->fresh()->loyalty_points
            ." manualAdd=".LoyaltyTransaction::where('type','manual_add')->count()."\n");
        $this->assertSame(500, (int) $this->customer->fresh()->loyalty_points);
        $this->assertSame(1, LoyaltyTransaction::where('type', 'manual_add')->count());
    }

    // RED-1d : le seal borne ne peut pas rattraper RED-1
    public function test_RED1d_kiosk_seal_agrees_with_the_full_price_outcome(): void
    {
        $this->customer->forceFill(['loyalty_points' => 100])->save();
        Sanctum::actingAs($this->kioskUser, ['kiosk:order']);
        $this->postJson('/api/frontend/loyalty/redeem', ['code' => 'REDVICTIM1', 'points' => 100])->assertOk();

        $this->app->forgetInstance(\App\Services\Order\OrderQuoteService::class);
        $real = $this->app->make(\App\Services\Order\OrderQuoteService::class);

        $p = $this->payload(['discount' => 1.00, 'loyalty_code' => 'REDVICTIM1']);
        unset($p['quote_token'], $p['quote_signature']);
        $req = \Illuminate\Http\Request::create('/api/frontend/order', 'POST', $p);
        $req->setUserResolver(fn () => $this->kioskUser);

        $quote = $real->quote($req, 'kiosk');

        fwrite(STDERR, "\n[RED-1d] quote.discount=".$quote->discount." quote.total_ttc=".$quote->total_ttc."\n");

        $this->assertEquals(0.0, (float) $quote->discount);
        $this->assertEquals(20.00, (float) $quote->total_ttc);
    }

    // REFUTED-6 : double dépense séquentielle
    public function test_REFUTED_two_orders_same_code_cannot_overdraw(): void
    {
        $this->customer->forceFill(['loyalty_points' => 100])->save();
        Sanctum::actingAs($this->kioskUser, ['kiosk:order']);

        $a = $this->postJson('/api/frontend/order', $this->payload([
            'discount' => 1.00, 'loyalty_code' => 'REDVICTIM1',
        ]));
        $b = $this->postJson('/api/frontend/order', $this->payload([
            'discount' => 1.00, 'loyalty_code' => 'REDVICTIM1',
        ]));
        $a->assertCreated();
        $b->assertCreated();

        $oa = FrontendOrder::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->find((int) $a->json('data.id'));
        $ob = FrontendOrder::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->find((int) $b->json('data.id'));

        fwrite(STDERR, "\n[REFUTED-6] A.discount=".$oa->discount." B.discount=".$ob->discount
            ." balance=".$this->customer->fresh()->loyalty_points
            ." redeemRows=".LoyaltyTransaction::where('type','redeem')->count()."\n");

        $this->assertSame(0, (int) $this->customer->fresh()->loyalty_points);
        $this->assertEquals(1.00, (float) $oa->discount);
        $this->assertEquals(0.0, (float) $ob->discount);
    }

    // RED-1c : mesure de la fenêtre du reaper
    public function test_RED1c_reaper_is_the_only_safety_net_and_its_window(): void
    {
        $this->customer->forceFill(['loyalty_points' => 100])->save();
        Sanctum::actingAs($this->kioskUser, ['kiosk:order']);
        $this->postJson('/api/frontend/loyalty/redeem', ['code' => 'REDVICTIM1', 'points' => 100])->assertOk();

        $svc = app(\App\Services\LoyaltyService::class);

        LoyaltyTransaction::where('type', 'redeem')->first()
            ->forceFill(['created_at' => now()->subMinutes(29)])->save();
        $n1 = $svc->reapOrphanRedemptions();

        LoyaltyTransaction::where('type', 'redeem')->first()
            ->forceFill(['created_at' => now()->subMinutes(31)])->save();
        $n2 = $svc->reapOrphanRedemptions();

        fwrite(STDERR, "\n[RED-1c] reap@29min=".$n1." reap@31min=".$n2
            ." balance=".$this->customer->fresh()->loyalty_points
            ." window=".config('loyalty.orphan_redeem_reap_minutes')."min cron=everyFiveMinutes\n");

        $this->assertSame(0, $n1);
        $this->assertSame(1, $n2);
        $this->assertSame(100, (int) $this->customer->fresh()->loyalty_points);
    }

    // RED-4 : min_redeem_points ignoré par /loyalty/redeem
    public function test_RED4_redeem_endpoint_ignores_min_redeem_points(): void
    {
        Settings::group('loyalty_setup')->set([
            'loyalty_points_for_1_euro_discount' => 100,
            'loyalty_min_redeem_points' => 500,
        ]);

        Sanctum::actingAs($this->kioskUser, ['kiosk:order']);
        $r = $this->postJson('/api/frontend/loyalty/redeem', ['code' => 'REDVICTIM1', 'points' => 100]);
        fwrite(STDERR, "\n[RED-4] redeem http=".$r->status()." balance=".$this->customer->fresh()->loyalty_points."\n");
        $r->assertOk();
        $this->assertSame(400, (int) $this->customer->fresh()->loyalty_points);

        $res = $this->postJson('/api/frontend/order', $this->payload([
            'discount' => 1.00, 'loyalty_code' => 'REDVICTIM1',
        ]));
        $res->assertCreated();
        $order = FrontendOrder::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->find((int) $res->json('data.id'));
        fwrite(STDERR, "[RED-4] order.discount=".$order->discount." total=".$order->total
            ." balance=".$this->customer->fresh()->loyalty_points."\n");
        $this->assertEquals(0.0, (float) $order->discount);
    }
}
```

## ANNEXE B — Fichiers audités

| Fichier | Lignes clés |
|---|---|
| `app/Services/FrontendOrderService.php` | 525-530, 542, 548, 562-564, 580-589, 604-606, 840, 925, 976-996, **1001-1128**, 1130-1164 |
| `app/Services/Pricing/DiscountCalculator.php` | 36-71 (**49, 56-57, 63, 66**) |
| `app/Http/Controllers/Frontend/LoyaltyController.php` | 49-55, 318-434 (**349-354, 379-395, 397-413**), 505-546, 1006-1015 |
| `app/Services/LoyaltyService.php` | **21-43**, 48-125, 159-217, **238-262**, 270-350 |
| `app/Services/Loyalty/PosRedemptionService.php` | 64-278 (**97-111, 137-144, 146-164, 236-240**), 284-310 |
| `app/Http/Requests/PosLoyaltyRedeemRequest.php` | 22-33 |
| `app/Services/Order/OrderQuoteService.php` | 56-127, **345-401** |
| `app/Services/OrderService.php` | 2263-2275, 2454-2488 |
| `app/Jobs/CleanupStalePendingKioskOrders.php` | 187-224, 241-341, 353-392 |
| `app/Services/Order/RefundWithCounterEntryService.php` | 394-458 |
| `config/loyalty.php` · `app/Console/Kernel.php` | 119 · 136-139 |
| `resources/js/components/frontend/kiosk/KioskLoyaltyComponent.vue` | 383-384, 477, 557-575 |
| `Site lecayenne/api.js` · `screens.jsx` | 749-769 · 755-780, 917-960 |
