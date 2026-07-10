<?php

namespace App\Services\Hardware;

use App\Models\Order;
use App\Models\Printer;
use App\Models\Scopes\BranchScope;

/**
 * [TICKET-UNIFY 2026-07-01] Source unique des octets ESC/POS d'un ticket (client ou cuisine).
 *
 * AVANT : la CAISSE rendait via PosTicketBytesController::renderTicketBytes (renderer serveur)
 * tandis que la BORNE reconstruisait un ticket côté client (kioskPrinter.js buildEscPosReceipt,
 * format différent, €→EUR). Résultat : deux formes différentes.
 *
 * MAINTENANT : caisse ET borne appellent CE service → mêmes octets, même renderer width-safe,
 * même largeur d'imprimante → ticket papier IDENTIQUE des deux côtés.
 */
final class EscPosTicketBytesService
{
    public function __construct(private OrderReceiptEscPosRenderer $renderer)
    {
    }

    /**
     * Rend les octets ESC/POS. Largeur/code-page lus de l'imprimante station si configurée,
     * sinon défauts (48 car, CP858). Retourne null si branche/commande introuvable.
     */
    public function render(int $branchId, int $orderId, string $ticket, bool $isDuplicata = false, bool $kioskClient = false): ?string
    {
        if ($branchId <= 0) {
            return null;
        }
        $order = Order::withoutGlobalScope(BranchScope::class)->with(['branch', 'user'])->find($orderId);
        if (! $order) {
            return null;
        }
        $order->setRelation('orderItems', $order->orderItems()->withoutGlobalScope(BranchScope::class)->get());

        $ticket = $ticket === 'kitchen' ? 'kitchen' : 'client';
        $stations = $ticket === 'kitchen' ? ['kitchen_hot', 'kitchen_cold', 'receipt'] : ['receipt'];
        $printer = null;
        foreach ($stations as $station) {
            $printer = Printer::withoutGlobalScope(BranchScope::class)
                ->where('branch_id', $branchId)->where('station', $station)
                ->where('status', \App\Enums\Status::ACTIVE)->orderBy('id')->first();
            if ($printer) {
                break;
            }
        }
        // [TICKET-WIDTH 2026-07-04] La largeur est désormais PILOTABLE par config
        // (RECEIPT_WIDTH_CHARS) qui a PRIORITÉ sur la row Printer — c'était le vrai bug de la
        // photo IMG_1709 : le ticket rendait à 48 col (fallback) alors que la SAGA n'imprime
        // que ~42 col → chaque ligne « retournait ». Priorité : config .env → Printer.width_chars
        // → 48. Ainsi on cale la vraie largeur physique en 1 réglage .env (config:clear), sans
        // dépendre d'une row Printer correcte en base.
        // [TICKET-WIDTH-BORNE 2026-07-05] Largeur DÉCOUPLÉE caisse ↔ borne. La caisse (SAGA)
        // imprime ~42 col (RECEIPT_WIDTH_CHARS=42) MAIS la borne (SK1-31) est plus large (48).
        // Appliquer 42 à la borne laissait une MARGE BLANCHE à droite. On choisit donc la config
        // selon la surface : borne → RECEIPT_BORNE_WIDTH_CHARS, caisse → RECEIPT_WIDTH_CHARS ;
        // fallback commun Printer.width_chars → 48. Chaque imprimante remplit sa largeur.
        // [HEAL 2026-07-09] Fallback borne CORRIGÉ : la borne NON configurée retombe sur la
        // largeur CAISSE prouvée (RECEIPT_WIDTH_CHARS=42), plus sur 48 en dur. L'hypothèse du
        // 05/07 (« SK1-31 plus large → 48 ») était FAUSSE : la photo owner (IMG_1729) montre la
        // SK1-31 58 mm ré-enrouler « 15,\n00 € » à 48. RECEIPT_BORNE_WIDTH_CHARS reste prioritaire
        // si l'on veut explicitement une largeur borne distincte de la caisse.
        $cfgWidth = $kioskClient
            ? ((int) config('printing.receipt.borne_width_chars', 0)
                ?: (int) config('printing.receipt.width_chars', 0))
            : (int) config('printing.receipt.width_chars', 0);
        $opts = [
            'width_chars'  => $cfgWidth ?: ((int) ($printer->width_chars ?? 0) ?: 48),
            'is_duplicata' => $isDuplicata,
        ];
        $pOpts = ($printer && is_array($printer->options)) ? $printer->options : [];
        if (! empty($pOpts['code_page'])) {
            $opts['code_page'] = (int) $pOpts['code_page'];
        }
        // [TICKET-BORNE-EURO 2026-07-09] La SK1-31 (borne) n'affiche « € » que sur SA page de code
        // (≠ SAGA caisse, qui rend CP858/0xD5 correctement). Knob dédié RECEIPT_BORNE_CODE_PAGE
        // (0 = non défini → options imprimante puis défaut renderer 19/CP858). À renseigner après
        // le test `tools/borne/test-euro-codepages.js`. Ne touche QUE la borne — la caisse garde
        // son € CP858.
        if ($kioskClient) {
            $borneCp = (int) config('printing.receipt.borne_code_page', 0);
            if ($borneCp > 0) {
                // Page de code € VÉRIFIÉE sur la SK1-31 → on garde le vrai symbole « € ».
                $opts['code_page'] = $borneCp;
            } else {
                // [BORNE-EURO 2026-07-09] Aucune page € fiable calée → repli « EUR » texte (ASCII,
                // toujours lisible) plutôt que « ⌐ ». Zéro charabia dès le déploiement ; passer au
                // vrai « € » = renseigner RECEIPT_BORNE_CODE_PAGE après tools/borne/test-euro-codepages.js.
                $opts['euro_as_text'] = true;
            }
        }

        // [TICKET-BORNE-LONG 2026-07-02] Ticket CLIENT imprimé par la BORNE : queue longue +
        // coupe partielle (ne tombe pas). N'affecte QUE la borne (le caissier tend le ticket).
        if ($kioskClient && $ticket === 'client') {
            // [TICKET-BORNE-WHITE 2026-07-05] Défaut de repli 8 (≈27 mm) et non 30 (≈10 cm) :
            // 30 lignes laissaient une longue queue BLANCHE si la config manquait. 8 dégage la
            // barre de coupe (partielle) sans marge blanche. Le pont bridge.js applique aussi
            // son propre défaut compact (8).
            $opts['feed_lines'] = max(1, min(12, (int) config('printing.cut.kiosk_client_feed_lines', 8)));
            $opts['cut_partial'] = strtolower((string) config('printing.cut.kiosk_client_mode', 'partial')) === 'partial';
        }

        return $ticket === 'kitchen'
            ? $this->renderer->renderKitchenTicket($order, $opts)
            : $this->renderer->renderClientTicket($order, $opts);
    }
}
