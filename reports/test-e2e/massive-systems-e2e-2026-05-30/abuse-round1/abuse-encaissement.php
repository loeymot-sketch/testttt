<?php
// ABUSE the NF525 encaissement (counter-collect) path: encash a PENDING_COUNTER order via the real
// endpoint, verify fiscal_sequence_no allocates gap-free + monotonic, + idempotency on double-confirm.
$ROOT='/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt';
require $ROOT.'/vendor/autoload.php'; $app=require $ROOT.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Http; use App\Models\User; use App\Models\Order;
$base='http://127.0.0.1:8000'; $apiKey=config('app.api_key');
$pos=User::where('email','pos@lecayenne.fr')->first(); $token=$pos->createToken('encash',['*'])->plainTextToken;

$before=Order::withoutGlobalScopes()->max('fiscal_sequence_no');
echo "max_fiscal_before=$before\n";

// pick a fresh PENDING_COUNTER order (status ACCEPT=4)
$target=Order::withoutGlobalScopes()->where('payment_status',15)->where('status',4)->whereNull('fiscal_sequence_no')->first();
echo "encash target #".$target->id." q=".$target->queue_number." total=".$target->total."\n";

function confirm($base,$apiKey,$token,$oid,$mode,$received,$idem){
  return Http::withToken($token)->withHeaders(['x-api-key'=>$apiKey,'Accept'=>'application/json','X-Idempotency-Key'=>$idem])
    ->asForm()->post($base.'/api/admin/pos/counter-collect/'.$oid.'/confirm',['mode'=>$mode,'received'=>$received,'note'=>'abuse-encash']);
}
// ABUSE: cash mode=1, received exact. Then replay SAME idem key (must not double-allocate fiscal).
$k='ENCASH-'.$target->id.'-'.uniqid();
$r1=confirm($base,$apiKey,$token,$target->id,1,(float)$target->total,$k);
echo "confirm CASH: HTTP ".$r1->status()." ".($r1->status()==200?'OK':substr($r1->body(),0,120))."\n";
$r2=confirm($base,$apiKey,$token,$target->id,1,(float)$target->total,$k);
echo "REPLAY same key: HTTP ".$r2->status()." (idempotent — no 2nd fiscal alloc)\n";

$o=Order::withoutGlobalScopes()->find($target->id);
echo "after encash: pay_status=".$o->payment_status." (5=PAID) fiscal_seq=".($o->fiscal_sequence_no??'null')."\n";
$after=Order::withoutGlobalScopes()->max('fiscal_sequence_no');
echo "max_fiscal_after=$after (delta=".($after-$before).", expect +1 single alloc)\n";
$pos->tokens()->where('name','encash')->delete();
