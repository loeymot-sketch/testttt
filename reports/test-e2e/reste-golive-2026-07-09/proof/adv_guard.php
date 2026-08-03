<?php
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
$threw = false; $msg='';
DB::beginTransaction();
try {
  $oi = OrderItem::withoutGlobalScopes()->find(5395);
  $orig = $oi->composition_snapshot;
  $mut = $orig; $mut['extras'][0]['line_total'] = 99.99; // tamper price
  $oi->composition_snapshot = $mut;
  $oi->save();
  echo 'GUARD NO-OP: save succeeded (BAD)'.PHP_EOL;
} catch (\RuntimeException $e) {
  $threw = true; $msg = $e->getMessage();
}
DB::rollBack();
echo 'GUARD THREW='.($threw?'YES':'NO').PHP_EOL;
echo 'MSG='.$msg.PHP_EOL;
// Confirm no persisted change
$after = OrderItem::withoutGlobalScopes()->find(5395);
echo 'POST-ROLLBACK extras[0].line_total='.$after->composition_snapshot['extras'][0]['line_total'].PHP_EOL;
