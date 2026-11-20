<?php
/**
 * [ETIQUETTES 2026-10-16] Fabrique/retire des commandes de test VISIBLES sur l'écran cuisine pour le
 * test E2E des étiquettes. Copie des commandes réelles de la base locale (lignes + composition scellée),
 * marquées `token = ETQ-LBL-…` pour que le nettoyage les retrouve sans toucher à autre chose.
 *
 *   php seed-label-orders.php seed 7593,6101,6277   → JSON [{id, queue_number, source_id}]
 *   php seed-label-orders.php clean                 → supprime toutes les commandes ETQ-LBL-*
 *
 * Uniquement sur la base LOCALE (garde : APP_ENV doit être local/testing).
 */
chdir(dirname(__DIR__, 3));
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (! in_array(app()->environment(), ['local', 'testing'], true)) {
    fwrite(STDERR, "refus : base non locale\n");
    exit(2);
}

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Illuminate\Support\Facades\DB;

$mode = $argv[1] ?? '';

if ($mode === 'clean') {
    $ids = DB::table('orders')->where('token', 'like', 'ETQ-LBL-%')->pluck('id')->all();
    if ($ids) {
        DB::table('label_print_logs')->whereIn('order_id', $ids)->delete();
        DB::table('order_items')->whereIn('order_id', $ids)->delete();
        DB::table('orders')->whereIn('id', $ids)->delete();
    }
    echo json_encode(['cleaned' => count($ids)]);
    exit(0);
}

if ($mode === 'seed') {
    $out = [];
    foreach (array_map('intval', explode(',', $argv[2] ?? '')) as $sourceId) {
        $src = DB::table('orders')->where('id', $sourceId)->first();
        if (! $src) { fwrite(STDERR, "source $sourceId introuvable\n"); continue; }
        $row = (array) $src;
        unset($row['id']);
        $now = now();
        $queue = 'ZT'.str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT);
        $row = array_merge($row, [
            'token' => 'ETQ-LBL-'.bin2hex(random_bytes(4)),
            'queue_number' => $queue,
            'status' => OrderStatus::PREPARING,
            'payment_status' => PaymentStatus::PAID,
            'fiscal_sequence_no' => null,
            'parent_order_id' => null,
            'idempotency_key' => null,
            'tracking_token' => null,
            'branch_id' => 1,
            'business_date' => $now->toDateString(),
            'order_datetime' => $now,
            'created_at' => $now,
            'updated_at' => $now,
            'scheduled_at' => null,
            'is_advance_order' => Ask::NO,
        ]);
        foreach (['accepted_at', 'preparing_at', 'prepared_at'] as $col) {
            if (array_key_exists($col, $row)) { $row[$col] = null; }
        }
        $newId = DB::table('orders')->insertGetId($row);
        foreach (DB::table('order_items')->where('order_id', $sourceId)->get() as $item) {
            $r = (array) $item;
            unset($r['id']);
            $r['order_id'] = $newId;
            $r['created_at'] = $now;
            $r['updated_at'] = $now;
            if (array_key_exists('kitchen_bumped_at', $r)) { $r['kitchen_bumped_at'] = null; }
            DB::table('order_items')->insert($r);
        }
        $out[] = ['id' => $newId, 'queue_number' => $queue, 'source_id' => $sourceId];
        usleep(20000);
    }
    echo json_encode($out);
    exit(0);
}

fwrite(STDERR, "usage: seed <ids> | clean\n");
exit(1);
