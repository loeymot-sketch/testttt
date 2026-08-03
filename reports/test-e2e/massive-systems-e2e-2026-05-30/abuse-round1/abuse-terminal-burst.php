<?php
$ROOT='/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt';
require $ROOT.'/vendor/autoload.php'; $app=require $ROOT.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Http; use App\Models\User; use App\Models\Order;
$base='http://127.0.0.1:8000'; $apiKey=config('app.api_key');
$pos=User::where('email','pos@lecayenne.fr')->first();
$token=$pos->createToken('abuse2',['*'])->plainTextToken;
function hit($base,$apiKey,$token,$oid,$status,$reason){
  return Http::withToken($token)->withHeaders(['x-api-key'=>$apiKey,'Accept'=>'application/json',
    'X-Idempotency-Key'=>'ABUSE2-'.$oid.'-'.$status.'-'.uniqid()])
    ->asForm()->post($base.'/api/admin/pos-order/change-status/'.$oid,['status'=>$status,'reason'=>$reason]);
}
function st($oid){ return Order::withoutGlobalScopes()->find($oid)->status; }

echo "=== TERMINAL WITH REASON ===\n";
$r=hit($base,$apiKey,$token,946,16,'Client parti / abuse'); echo "CANCEL #946 w/reason: HTTP ".$r->status()." st=".st(946)."\n";
$r=hit($base,$apiKey,$token,947,19,'Rupture stock / abuse'); echo "REJECT #947 w/reason: HTTP ".$r->status()." st=".st(947)."\n";

echo "=== CONCURRENCY BURST: #942 PENDING->ACCEPT x5 distinct keys ===\n";
$codes=[]; for($i=0;$i<5;$i++){ $codes[]=hit($base,$apiKey,$token,942,4,'')->status(); }
echo "  codes=[".implode(',',$codes)."] final_st=".st(942)." (expect ACCEPT=4, no dup/crash)\n";

$pos->tokens()->where('name','abuse2')->delete();
echo "=== final ===\n";
foreach([942,946,947] as $o){ echo "  #$o st=".st($o)."\n"; }
