<?php

namespace App\Http\Controllers\Admin\Wheel;

use App\Http\Controllers\Controller;
use App\Services\Wheel\WheelDeliveryService;
use Illuminate\Http\Request;

/**
 * L'écran comptoir de REMISE. Le maillon dont trois audits ont montré l'absence : la roue tirait,
 * mais aucune surface ne disait à l'équipe qu'un client avait un lot à recevoir.
 *
 * Réservé aux comptes caisse (`pos`), et la branche vient du COMPTE : remettre un lot, c'est sortir
 * quelque chose du stock ou créditer des points — ça n'a rien à faire dans les mains d'un compte
 * qui n'est pas au comptoir, ni pour le comptoir d'un autre.
 */
class WheelPrizeController extends Controller
{
    public function __construct(private readonly WheelDeliveryService $delivery) {}

    public function show(Request $request)
    {
        $branchId = $this->branchId($request);
        $phone = trim((string) $request->query('phone', ''));

        if ($phone === '') {
            return view('admin.wheel.lot', ['phone' => '', 'spin' => null, 'history' => []]);
        }

        $spin = $this->delivery->pending($branchId, $phone);
        $history = $this->delivery->history($branchId, $phone);

        $message = null;
        $type = 'info';
        if (! $spin) {
            // On distingue les deux cas : « rien à ce numéro » et « lot déjà remis ». Un seul
            // message pour les deux ferait passer l'équipe pour désorganisée devant le client.
            $message = $history->isEmpty()
                ? 'Aucun tour à ce numéro. Vérifie le numéro, ou fais-le jouer d\'abord.'
                : 'Rien à remettre : ses lots sont déjà remis, ou ce sont des codes à utiliser sur le site.';
        }

        return view('admin.wheel.lot', [
            'phone' => $phone,
            'spin' => $spin,
            'history' => $history,
            'message' => $message,
            'messageType' => $type,
        ]);
    }

    public function deliver(Request $request)
    {
        $branchId = $this->branchId($request);
        $data = $request->validate([
            'spin_id' => ['required', 'integer'],
            'phone'   => ['nullable', 'string', 'max:32'],
        ]);

        $r = $this->delivery->deliver((int) $data['spin_id'], (int) $request->user()?->id);

        $phone = trim((string) ($data['phone'] ?? ''));

        return view('admin.wheel.lot', [
            'phone' => $phone,
            // Après une remise on ne réaffiche PAS de bouton : le geste est fait, et un second
            // bouton juste après un succès est la meilleure façon de provoquer un double clic.
            'spin' => $r['ok'] ? null : $this->delivery->pending($branchId, $phone),
            'history' => $phone !== '' ? $this->delivery->history($branchId, $phone) : [],
            'message' => $r['message'],
            'messageType' => $r['ok'] ? 'ok' : 'err',
        ]);
    }

    private function branchId(Request $request): int
    {
        $u = $request->user();

        return (int) (($u && $u->branch_id) ? $u->branch_id : 1);
    }
}
