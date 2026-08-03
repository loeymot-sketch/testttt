<?php
// ABUSE the order state machine via the REAL change-status endpoint — record how the system defends.
$ROOT='/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt';
require $ROOT.'/vendor/autoload.php'; $app=require $ROOT.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Http; use App\Models\User; use App\Models\Order;
$base='http://127.0.0.1:8000'; $apiKey=config('app.api_key');
$pos=User::where('email','pos@lecayenne.fr')->first();
$token=$pos->createToken('abuse',['*'])->plainTextToken;

function hit($base,$apiKey,$token,$oid,$status,$idem=null){
  $r=Http::withToken($token)->withHeaders(['x-api-key'=>$apiKey,'Accept'=>'application/json',
    'X-Idempotency-Key'=>$idem??('ABUSE-'.$oid.'-'.$status.'-'.uniqid())])
    ->asForm()->post($base.'/api/admin/pos-order/change-status/'.$oid,['status'=>$status]);
  return [$r->status(), trim(substr(preg_replace('/\s+/',' ',$r->body()),0,90))];
}
function order_status($oid){ return Order::withoutGlobalScopes()->find($oid)->status; }

echo "=== ABUSE 1: invalid FORWARD skip PENDING(1)->PREPARED(8) [#942] ===\n";
[$c,$b]=hit($base,$apiKey,$token,942,8); printf("  HTTP %d | st_now=%d | %s\n",$c,order_status(942),$b);

echo "=== ABUSE 2: invalid BACKWARD #943: ACCEPT then ->PENDING(1) ===\n";
[$c,$b]=hit($base,$apiKey,$token,943,4); printf("  ->ACCEPT HTTP %d st=%d\n",$c,order_status(943));
[$c,$b]=hit($base,$apiKey,$token,943,1); printf("  ->PENDING(backward) HTTP %d st_now=%d | %s\n",$c,order_status(943),$b);

echo "=== ABUSE 3: idempotency — SAME A->B twice w/ SAME key [#944 ->ACCEPT] ===\n";
$k='ABUSE-IDEM-944-'.uniqid();
[$c1,$b1]=hit($base,$apiKey,$token,944,4,$k);
[$c2,$b2]=hit($base,$apiKey,$token,944,4,$k);
printf("  call1 HTTP %d | call2(replay same key) HTTP %d | st=%d\n",$c1,$c2,order_status(944));

echo "=== ABUSE 4: double-bump distinct keys A->A (already ACCEPT, ->ACCEPT again) [#944] ===\n";
[$c,$b]=hit($base,$apiKey,$token,944,4); printf("  HTTP %d st=%d | %s\n",$c,order_status(944),$b);

echo "=== ABUSE 5: garbage status=999 [#945] ===\n";
[$c,$b]=hit($base,$apiKey,$token,945,999); printf("  HTTP %d st_now=%d | %s\n",$c,order_status(945),$b);

echo "=== ABUSE 6: terminal CANCELED(16) [#946] then try to revive ->PREPARING(7) ===\n";
[$c,$b]=hit($base,$apiKey,$token,946,16); printf("  ->CANCELED HTTP %d st=%d | %s\n",$c,order_status(946),$b);
[$c,$b]=hit($base,$apiKey,$token,946,7); printf("  ->PREPARING(revive canceled) HTTP %d st_now=%d | %s\n",$c,order_status(946),$b);

echo "=== ABUSE 7: REJECTED(19) [#947] ===\n";
[$c,$b]=hit($base,$apiKey,$token,947,19); printf("  ->REJECTED HTTP %d st=%d | %s\n",$c,order_status(947),$b);

$pos->tokens()->where('name','abuse')->delete();
echo "\n=== final cohort states ===\n";
foreach([942,943,944,945,946,947] as $o){ $x=Order::withoutGlobalScopes()->find($o); printf("  #%d q=%s st=%d\n",$o,$x->queue_number,$x->status); }
