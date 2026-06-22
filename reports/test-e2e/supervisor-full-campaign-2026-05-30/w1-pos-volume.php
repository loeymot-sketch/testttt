<?php
// W1 POS volume generator — real HTTP through /api/admin/pos with a working token.
// Varied: all 45 items cycled, qty 1-3, multi-line orders, rotating payment methods.
$ROOT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt';
require $ROOT.'/vendor/autoload.php';
$app = require $ROOT.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Http;
use App\Enums\OrderType; use App\Enums\Source; use App\Enums\PaymentGateway;
use App\Enums\PosPaymentMethod; use App\Models\User; use App\Models\Order;

$base = 'http://127.0.0.1:8000';
$apiKey = config('app.api_key');
$u = User::where('email','pos@lecayenne.fr')->first();
$token = $u->createToken('w1-pos-volume', ['*'])->plainTextToken;

$N = 80;
$ITEM_IDS = [1,2,3,12,13,14,15,17,18,19,20,21,22,23,24,25,26,27,33,34,35,36,37,38,39,40,41,42,43,44,45,46,47,48,49,50,51,52,53,54,55,56,57,58,59];
$TERMINAL_ID = 1;
$status = []; $fiscalSeqs = []; $errs = []; $okIds = [];
$payMethods = [PosPaymentMethod::CASH, PosPaymentMethod::CARD];
// add TR/MOBILE if enum has them
foreach (['TICKET_RESTAURANT','TR','MOBILE','OTHER'] as $c) {
    if (defined(PosPaymentMethod::class.'::'.$c)) { $payMethods[] = constant(PosPaymentMethod::class.'::'.$c); }
}
$t0 = microtime(true);
for ($i=1; $i<=$N; $i++) {
    // variety: 1-3 line items, cycling all 45 item ids, qty 1-3
    $nLines = ($i % 3) + 1;
    $items = [];
    for ($l=0; $l<$nLines; $l++) {
        $itemId = $ITEM_IDS[(($i + $l*7) % count($ITEM_IDS))];
        $items[] = ['item_id' => $itemId, 'quantity' => (($i+$l) % 3) + 1];
    }
    $pm = $payMethods[$i % count($payMethods)];
    $isCash = ($pm === PosPaymentMethod::CASH);
    $isCard = ($pm === PosPaymentMethod::CARD);
    $payload = [
        'branch_id' => 1,
        'order_type' => OrderType::POS,
        'is_advance_order' => 0,
        'source' => Source::POS,
        'payment_method' => PaymentGateway::CARD,
        'pos_payment_method' => $pm,
        'items' => json_encode($items),
        'total' => 0, 'subtotal' => 0, 'discount' => 0,
    ];
    if ($isCash) { $payload['pos_received_amount'] = 10000; }
    else { $payload['pos_payment_note'] = 'w1-volume-'.$i; }
    if ($isCard) { $payload['terminal_id'] = $TERMINAL_ID; }

    try {
        $resp = Http::withToken($token)
            ->withHeaders(['x-api-key'=>$apiKey, 'Accept'=>'application/json',
                'X-Idempotency-Key'=>'W1-POS-'.$i.'-'.uniqid()])
            ->asForm()->post($base.'/api/admin/pos', $payload);
        $sc = $resp->status();
        $status[$sc] = ($status[$sc] ?? 0) + 1;
        if ($sc >= 200 && $sc < 300) {
            $j = $resp->json();
            $oid = $j['data']['id'] ?? $j['id'] ?? ($j['data']['order']['id'] ?? null);
            if ($oid) { $okIds[] = $oid; $o = Order::withoutGlobalScopes()->find($oid); if ($o && $o->fiscal_sequence_no) $fiscalSeqs[] = (int)$o->fiscal_sequence_no; }
        } elseif ($sc >= 400) {
            if (count($errs) < 4) $errs[] = $sc.': '.substr($resp->body(),0,180);
        }
    } catch (\Throwable $e) {
        $status['EXC'] = ($status['EXC'] ?? 0) + 1;
        if (count($errs) < 4) $errs[] = 'EXC: '.$e->getMessage();
    }
}
$dur = round(microtime(true)-$t0, 2);
$u->tokens()->where('name','w1-pos-volume')->delete();

echo "=== W1 POS VOLUME ($N orders, real HTTP) ===\n";
echo "duration_s=$dur\n";
echo "status_breakdown=".json_encode($status)."\n";
sort($fiscalSeqs);
echo "fiscal_seqs_count=".count($fiscalSeqs)."\n";
if ($fiscalSeqs) {
    $min=$fiscalSeqs[0]; $max=end($fiscalSeqs);
    $gaps=0; $dups=0; $prev=null;
    foreach($fiscalSeqs as $s){ if($prev!==null){ if($s===$prev)$dups++; elseif($s>$prev+1)$gaps += ($s-$prev-1);} $prev=$s; }
    echo "fiscal_min=$min fiscal_max=$max contiguous_dups=$dups internal_gaps=$gaps\n";
}
echo "sample_errors=".json_encode($errs)."\n";
