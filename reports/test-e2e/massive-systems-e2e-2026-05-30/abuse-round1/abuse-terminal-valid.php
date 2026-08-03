<?php
$ROOT='/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt';
require $ROOT.'/vendor/autoload.php'; $app=require $ROOT.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Http; use App\Models\User; use App\Models\Order;
$base='http://127.0.0.1:8000'; $apiKey=config('app.api_key');
$pos=User::where('email','pos@lecayenne.fr')->first(); $token=$pos->createToken('abuseT',['*'])->plainTextToken;
function hit($base,$apiKey,$token,$oid,$status,$reason){
  return Http::withToken($token)->withHeaders(['x-api-key'=>$apiKey,'Accept'=>'application/json',
    'X-Idempotency-Key'=>'ABUSET-'.$oid.'-'.$status.'-'.uniqid()])
    ->asForm()->post($base.'/api/admin/pos-order/change-status/'.$oid,['status'=>$status,'reason'=>$reason]);
}
function st($o){ return Order::withoutGlobalScopes()->find($o)->status; }

echo "=== TERMINAL WITH VALID WHITELISTED REASON CODE ===\n";
$r=hit($base,$apiKey,$token,946,16,'customer_request'); echo "CANCEL #946 (customer_request): HTTP ".$r->status()." st=".st(946)." ".($r->status()==200?'OK':substr($r->body(),0,80))."\n";
$r=hit($base,$apiKey,$token,947,19,'kitchen_reject');   echo "REJECT #947 (kitchen_reject): HTTP ".$r->status()." st=".st(947)." ".($r->status()==200?'OK':substr($r->body(),0,80))."\n";
// post-terminal: try to revive a CANCELED order (must be blocked)
$r=hit($base,$apiKey,$token,946,7,''); echo "REVIVE canceled #946 ->PREPARING: HTTP ".$r->status()." st_now=".st(946)." (expect 422 blocked, st stays terminal)\n";
$pos->tokens()->where('name','abuseT')->delete();
echo "=== final ===\n"; foreach([946,947] as $o){ echo "  #$o st=".st($o)." (16=CANCELED,19=REJECTED)\n"; }
