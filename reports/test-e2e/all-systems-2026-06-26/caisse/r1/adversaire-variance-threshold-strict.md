# Vérification adversaire — CAISSE / Tiroir-caisse / cash / Z

## Finding candidate
[P3] `app/Services/Cash/CashDrawerService.php:276` — Seuil variance strict `> 2,00 EUR` : un écart EXACTEMENT au seuil ne déclenche ni raison ni approbation manager (by-design documenté)

## VERDICT : REFUTED (réduit à informatif by-design — non nuisible en V1-LOCAL)

Le mécanisme décrit existe bien (le `>` strict est réel et vérifié), mais :
1. il est **explicitement documenté et volontaire** (tolérance métier NF525-référencée),
2. la **« preuve indirecte live » citée RÉFUTE elle-même** la thèse de skim non-gardé,
3. l'**atténuation est totale** (variance persistée + auditée + visible au rapport).

Ce n'est ni une violation NF525, ni une perte d'argent cachée, ni une fuite. Le finder lui-même conclut « décision owner, pas un fix auto. Statut informatif ». Défaut REFUTED maintenu.

---

## Re-Read du code exact (confirmé)
`CashDrawerService.php:266-312` :
```php
$threshold = (float) Config::get('cash.variance_threshold_eur', 2.00);   // :266
...
if (abs($variance) > $threshold) {                                       // :276  ← strict '>'
    if ($trimmedReason === null) { throw CashVarianceRequiresApproval... } // :277-288 (CODE_REASON_REQUIRED)
    if (mb_strlen($trimmedReason) > $maxReasonLength) { throw 422; }        // :290-295
    if ($approvalRequired) {                                                // :297
        if ($actor === null || ! actorCanOverrideVariance(...)) throw ...;  // :298 (CODE_MANAGER_APPROVAL)
    }
}
```
→ À `|variance| == 2.00` exactement, `2.00 > 2.00` est `false` → le bloc gate (raison + permission) est sauté. **La mécanique décrite est exacte.**

## Le `>` est documenté + intentionnel (config/cash.php)
- `config/cash.php:22` : « Any |variance| **>** threshold requires both [reason + permission] »
- `config/cash.php:27` : « The default 2.00 euros covers **normal coin-counting rounding noise**. »
- En-tête `config/cash.php:4-9` : référence NF525 / Loi Finance 2018 — la tolérance est la conception assumée, pas un oubli.
→ Ce n'est pas un edge-case oublié : c'est la bande de tolérance métier choisie par l'owner. Le `>=` re-déclencherait le gate sur du bruit de pièces (faux positifs à chaque clôture).

## La « preuve live » citée RÉFUTE la thèse (point décisif)
Repro re-exécutée :
```
$ mysql -u root foodking_e2e -e \
  "SELECT id,status,variance,variance_reason,reconciled_by_user_id,branch_id \
   FROM cash_drawer_sessions WHERE ABS(variance)=2.00 ORDER BY id;"
id  status      variance  variance_reason       reconciled_by_user_id  branch_id
17  reconciled  2.00      Ecart test W-B +2     1                      1
18  reconciled  2.00      Ecart test W-B +2     1                      1
```
Les sessions #17/#18 (variance=2.00) **ONT une `variance_reason`** (« Ecart test W-B +2 ») ET un `reconciled_by_user_id=1`. Ce ne sont donc PAS des exemples de skim 2,00€ « sans raison ni approbation » — au contraire, ce sont des réconciliations RAISONNÉES. La « preuve indirecte live » du finding démontre l'inverse de la nuisance prétendue. (Ce sont aussi des données de test W-B, pas une fraude réelle.)

## Atténuation = totale (non invisible)
- `CashDrawerService.php:315` : `$session->variance = $variance;` — la variance est TOUJOURS persistée, quel que soit le seuil.
- `CashDrawerService.php:339-346` : audit `cash.session.reconciled` inclut `variance`, `variance_reason`, `threshold`, et `over_threshold` (flag). → tout écart est mesuré, enregistré, et exposé au `CashSessionReportController` (`:139` lit `variance_reason`).
→ NF525 exige que l'écart soit (a) mesuré, (b) traçable. Les deux sont satisfaits même sous le seuil. Le gap n'est pas caché.

## Couverture de test (verte, by-design)
`tests/Feature/Cash/CashVarianceGateTest.php` :
- `:88` under-threshold +1.50 → OK sans reason (bande de tolérance assumée)
- `:100` +5.00 sans reason → `CODE_REASON_REQUIRED`
- `:123` -5.00 + reason mais caissier → `CODE_MANAGER_APPROVAL`
- `:146` +10.00 + reason + manager → OK
- `:218` under-threshold +1.00 + note → reason persistée
→ La sémantique `>` (tolérance jusqu'au seuil inclus) est le comportement testé et attendu. Aucun test n'attend un rejet à 2.00 exact.

## Permission réellement câblée (contre-preuve de garde existante)
- `database/seeders/PermissionTableSeeder.php:694` : permission `cash.reconcile.variance.override` définie.
- `database/seeders/RolePermissionTableSeeder.php:81` : accordée (Admin + Branch Manager).
- `CashDrawerService.php:298,490` : `actorCanOverrideVariance()` résout la permission Spatie.
→ Le gate au-DELÀ du seuil est solide et opérationnel ; seule la borne exacte est en tolérance, par conception.

## Sévérité V1-LOCAL
Le Cayenne = mono-poste, mono-caissier. Le scénario « écrémer pile 2,00€/clôture » est : borné au bruit de pièces, intégralement persisté + audité + visible au rapport caisse, et correspond à la tolérance métier documentée que l'owner accepte. Pas de violation NF525 (écart mesuré + tracé), pas de perte d'argent cachée, pas de fuite. → **REFUTED** (au mieux P3-informatif by-design ; aucun fix auto justifié).

## Reco
Aucun fix. Le passage en `>=` est un **changement de politique métier** (re-déclenche le gate sur bruit de pièces normal) → décision owner uniquement, hors-scope d'un heal automatique. Statut : informatif, by-design, déjà documenté `config/cash.php:22-29`.

## Lentille
Verify-before-report : la « preuve live » censée étayer la nuisance la contredit (les rows à 2.00 ont une raison). Tolérance NF525 documentée + atténuation audit = non-finding opérationnel.
