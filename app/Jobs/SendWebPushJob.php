<?php

namespace App\Jobs;

use App\Models\Scopes\BranchScope;
use App\Services\Push\WebPushSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * [NFC / NOTIFICATIONS 2026-11-20] Envoie UNE notification Web Push à un client, hors de la requête qui a changé le statut.
 * Toute exception est journalisée et avalée : la file n'a jamais à rejouer ni à remplir la table des échecs pour une
 * notification (le suivi à l'écran fonctionne sans elle).
 */
class SendWebPushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 40; // pire cas : 5 appareils × 4 s de délai de la bibliothèque = 20 s ; reste sous `retry_after` (90 s)

    /**
     * @param  array<string,mixed>|null  $charge  charge toute faite, OU null + $orderId/$status : le texte est alors construit
     *                                            À L'ENVOI (lecture fraîche : le crédit de points posé entre-temps est dit)
     */
    public function __construct(public int $userId, public ?array $charge = null, public ?int $orderId = null, public ?int $status = null)
    {
    }

    /** @return array<int,int> */
    public function backoff(): array
    {
        return [20];
    }

    public function handle(WebPushSender $sender): void
    {
        try {
            $charge = $this->charge;
            if ($charge === null && $this->orderId !== null && $this->status !== null) {
                // [F-010] cross-branch intentional: tâche de file sans contexte de branche ; la commande est lue par sa CLÉ PRIMAIRE (jamais une liste) et sert seulement à composer le texte pour son propre client.
                $order = \App\Models\Order::query()->withoutGlobalScope(BranchScope::class)->find($this->orderId);
                // Lecture FRAÎCHE : si la commande a déjà dépassé ce statut (ou été annulée), cette notification est périmée.
                if ($order && \App\Services\Push\OrderPushMessage::estPerimee((int) $order->status, $this->status)) {
                    return;
                }
                $charge = $order ? \App\Services\Push\OrderPushMessage::build($order, $this->status) : null;
            }
            if ($charge === null) {
                return;
            }
            $sender->sendToUser($this->userId, $charge);
        } catch (\Throwable $e) {
            Log::warning('[webpush] tâche isolée', ['erreur' => mb_substr($e->getMessage(), 0, 200)]);
        }
    }
}
