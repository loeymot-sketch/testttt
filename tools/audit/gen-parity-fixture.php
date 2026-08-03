<?php
// [W6-ADV B-2 2026-07-06] Régénère tests/fixtures/parity_php.json depuis la DB RÉELLE.
// Rows = (name, snap, php=mainLine, supps, menu) + NOUVEAUX champs (instr, note=cleanInstruction,
// drink=isDrinkItem, drinks=drinkLines) pour la parité PHP↔JS des shapes du range :
// O̲ (oignons cuits), notes/instructions, tête boisson nom complet, addons boisson.
// Force-include : commandes 5171 (addon role=drink), 5506/5528/5529/5530/5532 (notes+O̲),
// 5531 (boissons standalone), 5533 (formule borne → extraction BOISSON).
// Run : php artisan tinker --execute="require 'tools/audit/gen-parity-fixture.php';"

$f = app(\App\Services\Hardware\KitchenTicketSymbolicFormatter::class);
$rows = [];
$seen = [];

$push = function ($oi) use (&$rows, &$seen, $f) {
    $name = (string) ($oi->name ?? optional($oi->orderItem)->name ?? '');
    if ($name === '') {
        return;
    }
    $snap = is_array($oi->composition_snapshot) ? $oi->composition_snapshot : null;
    if (! $snap) {
        return;
    }
    $instr = (string) ($oi->instruction ?? '');
    $key = md5($name.'|'.json_encode($snap).'|'.$instr);
    if (isset($seen[$key])) {
        return;
    }
    $seen[$key] = true;
    $rows[] = [
        'name'   => $name,
        'snap'   => $snap,
        'php'    => $f->mainLine($name, $snap),
        'supps'  => $f->supplementLines($snap),
        'menu'   => $f->menuLine($snap),
        'instr'  => $instr,
        'note'   => $f->cleanInstruction($instr, $name),
        'drink'  => $f->isDrinkItem($name),
        'drinks' => $f->drinkLines($snap),
    ];
};

$force = [5171, 5506, 5528, 5529, 5530, 5531, 5532, 5533];
foreach (\App\Models\OrderItem::withoutGlobalScopes()->whereIn('order_id', $force)->with('orderItem')->orderBy('id')->get() as $oi) {
    $push($oi);
}
$forcedCount = count($rows);

foreach (\App\Models\OrderItem::withoutGlobalScopes()->whereNotNull('composition_snapshot')->orderByDesc('id')->with('orderItem')->limit(900)->get() as $oi) {
    if (count($rows) >= 220) {
        break;
    }
    $push($oi);
}

$path = base_path('tests/fixtures/parity_php.json');
file_put_contents($path, json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n");

$withNote = count(array_filter($rows, fn ($r) => $r['note'] !== ''));
$withDrink = count(array_filter($rows, fn ($r) => $r['drink']));
$withDrinkAddon = count(array_filter($rows, fn ($r) => count($r['drinks']) > 0));
$withO = count(array_filter($rows, fn ($r) => str_contains($r['php'], "O\u{0332}")));
echo 'rows='.count($rows)." (forced=$forcedCount) note>0=$withNote drink=$withDrink drinkAddons=$withDrinkAddon O̲=$withO\n";
