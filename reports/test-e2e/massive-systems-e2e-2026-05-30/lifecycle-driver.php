<?php
// Command-driven lifecycle: walk my fresh cohort (#934-941) through PENDING->ACCEPT->PREPARING->
// PREPARED->DELIVERED via the REAL change-status endpoint, populating every status cohort so each
// system page renders. Real HTTP through PosOrderController::changeStatus (state-machine enforced).
$ROOT='/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt';
require $ROOT.'/vendor/autoload.php';
$app=require $ROOT.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Http; use App\Models\User; use App\Models\Order;

$base='http://127.0.0.1:8000'; $apiKey=config('app.api_key');
$pos=User::where('email','pos@lecayenne.fr')->first();
$token=$pos->createToken('lifecycle-driver',['*'])->plainTextToken;

function step($base,$apiKey,$token,$orderId,$status,$label){
  $r=Http::withToken($token)->withHeaders([
    'x-api-key'=>$apiKey,'Accept'=>'application/json',
    'X-Idempotency-Key'=>'LIFECYCLE-'.$orderId.'-'.$status.'-'.uniqid(),
  ])->asForm()->post($base.'/api/admin/pos-order/change-status/'.$orderId,['status'=>$status]);
  printf("  #%d -> %s(%d) : HTTP %d %s\n",$orderId,$label,$status,$r->status(),
    $r->successful()?'OK':substr($r->body(),0,120));
  return $r->successful();
}

// cohort plan (my fresh orders #934-941): populate every status
$plan=[
  934=>[],                         // stays PENDING (KDS new order)
  935=>[],                         // stays PENDING
  936=>[[4,'ACCEPT']],             // accepted (in kitchen)
  937=>[[4,'ACCEPT']],             // accepted
  938=>[[4,'ACCEPT'],[7,'PREPARING']],  // preparing
  939=>[[4,'ACCEPT'],[7,'PREPARING']],  // preparing
  940=>[[4,'ACCEPT'],[7,'PREPARING'],[8,'PREPARED']],  // prepared (ready, OSS)
  941=>[[4,'ACCEPT'],[7,'PREPARING'],[8,'PREPARED'],[13,'DELIVERED']], // full -> archived
];
echo "=== DRIVING COHORT THROUGH LIFECYCLE (real change-status endpoint) ===\n";
foreach($plan as $oid=>$steps){
  $o=Order::withoutGlobalScopes()->find($oid);
  if(!$o){ echo "  #$oid not found\n"; continue; }
  echo "Order #$oid (q=".$o->queue_number.", start st=".$o->status."):\n";
  if(empty($steps)){ echo "  (stays PENDING)\n"; continue; }
  foreach($steps as [$st,$lbl]){ if(!step($base,$apiKey,$token,$oid,$st,$lbl)) break; usleep(300000); }
}
$pos->tokens()->where('name','lifecycle-driver')->delete();

echo "\n=== COHORT FINAL STATES ===\n";
foreach(array_keys($plan) as $oid){
  $o=Order::withoutGlobalScopes()->find($oid);
  printf("  #%d q=%s status=%d pay=%d fiscal=%s\n",$oid,$o->queue_number,$o->status,$o->payment_status,$o->fiscal_sequence_no??'-');
}
