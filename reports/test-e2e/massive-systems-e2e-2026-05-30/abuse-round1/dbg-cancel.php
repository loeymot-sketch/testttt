<?php
$ROOT='/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt';
require $ROOT.'/vendor/autoload.php'; $app=require $ROOT.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Http; use App\Models\User; use App\Models\Order;
$pos=User::where('email','pos@lecayenne.fr')->first(); $t=$pos->createToken('dbg',['*'])->plainTextToken; $k=config('app.api_key');
echo "#946 current status=".Order::withoutGlobalScopes()->find(946)->status."\n";
$r=Http::withToken($t)->withHeaders(['x-api-key'=>$k,'Accept'=>'application/json','X-Idempotency-Key'=>'DBG-'.uniqid()])
  ->asForm()->post('http://127.0.0.1:8000/api/admin/pos-order/change-status/946',['status'=>16,'reason'=>'test reason here long enough']);
echo "CANCEL HTTP ".$r->status()."\nBODY: ".$r->body()."\n";
$pos->tokens()->where('name','dbg')->delete();
