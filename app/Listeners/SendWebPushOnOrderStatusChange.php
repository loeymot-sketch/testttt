<?php

namespace App\Listeners;

use App\Events\OrderStatusChanged;
use App\Jobs\SendWebPushJob;
use App\Models\WebPushSubscription;
use App\Services\Push\OrderPushMessage;
use App\Services\Push\WebPushSender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * [NFC / NOTIFICATIONS 2026-11-20] Prévient le CLIENT (Web Push) quand sa commande avance. Voir OrderPushMessage pour la table.
 *
 * Ce listener ne fait que : filtrer, dédoublonner, puis mettre UNE tâche en file. Il n'écrit rien dans la commande, ne
 * touche ni la machine à états ni les points, et toute erreur est absorbée ici (jamais de remontée vers la caisse, la
 * cuisine ou le paiement — même doctrine que SendFcmOnOrderStatusChange).
 * Plusieurs chemins émettent le même événement (voir OrderService / PaymentService / UberWebhookController) : le
 * dédoublonnage par (commande, statut) garantit UNE notification.
 */
class SendWebPushOnOrderStatusChange
{
    public function handle(OrderStatusChanged $event): void
    {
        try {
            if (! WebPushSender::actif()) {
                return;
            }
            $status = (int) $event->newStatus;
            if (! in_array($status, OrderPushMessage::STATUTS, true)) {
                return;
            }
            $userId = (int) ($event->order->user_id ?? 0);
            $orderId = (int) ($event->order->id ?? 0);
            if ($userId <= 0 || $orderId <= 0) {
                return;
            }
            if (! WebPushSubscription::query()->where('user_id', $userId)->exists()) {
                return;
            }
            // Commande de la CAISSE ou de la BORNE : jamais de notification (contrat T-1.3.1) — même si le compte porté par la
            // commande a un appareil abonné (le compte d'une borne, par exemple, est celui d'un membre de l'équipe).
            if (in_array(strtolower((string) ($event->order->source_surface ?? '')), ['pos', 'kiosk'], true)) {
                return;
            }
            $client = \App\Models\User::query()->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->find($userId);
            // Même règle d'EXCLUSION que le crédit de points : un client ancien sans rôle n'est pas écarté ; l'équipe et les comptes de borne le sont.
            if (! $client || \App\Listeners\AwardLoyaltyPointsOnDelivery::estExcluDuCredit($client)) {
                return;
            }
            $ttl = now()->addMinutes((int) config('webpush.dedupe_minutes', 360));
            $cleDoublon = 'webpush:order:'.$orderId.':'.$status;
            if (! Cache::add($cleDoublon, 1, $ttl)) {
                return;
            }
            $job = (new SendWebPushJob($userId, null, $orderId, $status))->afterCommit();
            // « Validée » part 5 s plus tard : laisse le crédit de points se poser pour le dire dans la notification.
            if ($status === \App\Enums\OrderStatus::ACCEPT) {
                $job->delay(now()->addSeconds(5));
            }
            // Envoi EXPLICITE, dans le try : un PendingDispatch ne part qu'à sa destruction, hors de ce try (panne de file = exception remontée).
            app(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatch($job);
        } catch (\Throwable $e) {
            // La mise en file a échoué : la clé de dédoublonnage ne doit pas survivre (le même changement, ré-émis par un autre chemin,
            // pourrait encore notifier — sinon le client est privé de cette notification pendant 6 h).
            if (isset($cleDoublon)) {
                try {
                    Cache::forget($cleDoublon);
                } catch (\Throwable) {
                    // Cache injoignable : rien à libérer, et l'écouteur ne doit JAMAIS remonter d'exception à la caisse ou à la cuisine
                    // (audit adverse tour 3, S3-01 : sinon le dernier écouteur de l'événement ne s'exécutait pas).
                }
            }
            Log::warning('[webpush] listener isolé', ['erreur' => mb_substr($e->getMessage(), 0, 200)]);
        }
    }
}
