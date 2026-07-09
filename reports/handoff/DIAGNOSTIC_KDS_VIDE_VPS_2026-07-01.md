# DIAGNOSTIC — « KDS vide » sur le VPS (la vraie cause, PAS kds_station)

> Retour cowork : borne OK (multi-viande 2/2 ✅, ticket imprimé ✅, VPS déployé `258f74722` ✅),
> mais **KDS vide** attribué à `kds_station="none"`. **Cette théorie est FAUSSE et prouvée impossible.**

## 1. Débunk définitif de `kds_station`
```
La table `orders` n'a AUCUNE colonne `kds_station`.
```
`kds_station` est un attribut PRODUIT (sur `items`, label de poste, défaut « none »), **jamais** un
filtre du board KDS. Une requête `WHERE kds_station` sur `orders` échoue « Unknown column ». **Arrêter
de chercher de ce côté** — c'est mécaniquement impossible.

## 2. Le VRAI filtre du KDS (SSOT `KitchenReleaseRule`)
Une commande est sur le KDS **si et seulement si** :
```
status ∈ {4=ACCEPT, 7, 8}   ET   payment_status ∈ {5=PAID, 15=PENDING_COUNTER}
ET   branch_id == branche de l'utilisateur qui regarde le KDS
```

## 3. Pourquoi une commande BORNE peut NE PAS y être (code `FrontendOrderService:219-290`)
La borne met `payment_status = PENDING_COUNTER` (→ visible) **UNIQUEMENT si** :
```
order_type ∈ {KIOSK, TAKEAWAY}   ET   payment_method == 1 (CASH_ON_DELIVERY)
```
Sinon (carte / ticket-resto) → `payment_status = UNPAID (10)` → **INVISIBLE au KDS** (attendu : une
commande carte non encore payée ne doit pas polluer la cuisine). Donc : **si la borne n'envoie pas
`payment_method=1` (Plan B « payer en caisse »), la commande est UNPAID et n'apparaît jamais.**

---

## 4. LA COMMANDE DE DIAGNOSTIC À LANCER SUR LE VPS (copier-coller)
```bash
ssh lecayenne
cd /var/www/lecayenne
php artisan tinker
```
```php
$o = \App\Models\Order::withoutGlobalScopes()->latest('id')->first();
$chef = \App\Models\User::where('email','chef@lecayenne.fr')->first();
echo "Dernière commande #{$o->id}\n";
echo "  status          = {$o->status}        (attendu 4/7/8)\n";
echo "  payment_status  = {$o->payment_status} (attendu 5 ou 15 ; si 10=UNPAID = LA CAUSE)\n";
echo "  payment_method  = {$o->payment_method} (attendu 1=CASH_ON_DELIVERY pour Plan B)\n";
echo "  order_type      = {$o->order_type}     (attendu 25=KIOSK ou 10=TAKEAWAY)\n";
echo "  source_surface  = {$o->source_surface}\n";
echo "  branch_id       = {$o->branch_id}      vs chef branch_id = ".($chef->branch_id ?? 'null')."\n";
$visible = in_array((int)$o->status,[4,7,8]) && in_array((int)$o->payment_status,[5,15]) && ((int)$o->branch_id === (int)($chef->branch_id ?? -1) || (int)($chef->branch_id ?? -1) === 0);
echo "  => VISIBLE au KDS du chef ? ".($visible ? "OUI" : "NON")."\n";
exit
```

## 5. LIRE LE RÉSULTAT → CAUSE → FIX

| Ce que tu vois | Cause | Fix |
|---|---|---|
| `payment_status = 10` (UNPAID) + `payment_method != 1` | La borne n'a PAS routé le paiement en caisse (Plan B). Elle a envoyé carte/ticket → UNPAID. | Sur le VPS : `.env` **`KIOSK_PAYMENT_ROUTE_ALL_TO_COUNTER=true`** + `php artisan config:clear && config:cache` + **rebuild frontend** (`npm run production`) pour que la borne envoie `payment_method=1`. Reboot borne (vider cache/SW). |
| `branch_id` de la commande **≠** `branch_id` du chef (et chef ≠ 0) | Mismatch de branche : le chef ne voit pas la branche de la borne. | Aligner : la `KioskMachine` et le compte chef doivent être sur la **même branche** (branch_id=1). `KioskMachine::...->update(['branch_id'=>1])` ; chef branch_id=1. |
| `status < 4` (ex. 1=PENDING) | La commande n'a pas été auto-acceptée. | Vérifier que `isCounterDeferredKioskCash` est vrai (donc payment_method=1) — même fix que ligne 1. |
| Tout est bon (`VISIBLE = OUI`) mais l'écran reste vide | Le KDS n'a pas rafraîchi (WebSocket off, polling). | Recharger la page KDS (F5). Activer Echo/`BROADCAST_DRIVER` pour le temps-réel, sinon le polling met ~10-20 s. Vérifier que le chef regarde bien `/admin/kitchen-display-system` de la MÊME branche. |

## 6. Confirmation attendue après fix
Passer une commande borne → le diagnostic (§4) doit montrer `payment_status=15` + `status=4` +
`branch_id=1` + `VISIBLE = OUI`, et la commande apparaît sur le KDS avec badge « EN ATTENTE ENCAISSEMENT ».

> ⚠️ Le plus probable = **§5 ligne 1** (routage paiement) : la borne doit envoyer `payment_method=1`
> (Plan B). C'est un réglage `.env` + rebuild, PAS un bug de code (le code met correctement
> PENDING_COUNTER quand la borne route en caisse — prouvé en local : 442 commandes borne visibles au KDS).
