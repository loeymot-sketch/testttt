<?php
use App\Models\Order;
use App\Models\OrderItem;
$o = Order::withoutGlobalScopes()->with('orderItems')->find(5622);
echo 'ORDER 5622 total='.$o->total.PHP_EOL;
foreach($o->orderItems as $oi){
  echo '--- order_item id='.$oi->id.' item_id='.$oi->item_id.' qty='.$oi->quantity.' price='.$oi->price.' unit='.$oi->unit_price.PHP_EOL;
  $snap = $oi->composition_snapshot;
  echo 'snapshot_type='.gettype($snap).PHP_EOL;
  echo 'snapshot_json='.json_encode($snap, JSON_UNESCAPED_UNICODE).PHP_EOL;
}
// Immutability guard check: attempt to detect the guard exists
$ref = new ReflectionClass(OrderItem::class);
echo 'HAS booted updating guard file lines: ';
$src = file(app_path('Models/OrderItem.php'));
foreach($src as $n=>$line){ if(stripos($line,'composition_snapshot')!==false && stripos($line,'isDirty')!==false){ echo ($n+1).' '; } }
echo PHP_EOL;
