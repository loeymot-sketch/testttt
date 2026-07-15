<?php
require __DIR__.'/../../../../vendor/autoload.php';
$app = require_once __DIR__.'/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\Order;
$id=5622;
$o=Order::with('orderItems')->find($id);
foreach($o->orderItems as $oi){
  echo "--- order_item id=".$oi->id." item_id=".$oi->item_id." qty=".$oi->quantity." price=".$oi->price." ---\n";
  echo "item_variations(raw)=".$oi->item_variations."\n";
  echo "item_extras(raw)=".$oi->item_extras."\n";
  $snap = $oi->composition_snapshot;
  echo "composition_snapshot=\n".json_encode(is_string($snap)?json_decode($snap,true):$snap, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
}
