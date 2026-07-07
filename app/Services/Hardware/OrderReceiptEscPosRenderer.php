<?php

namespace App\Services\Hardware;

use App\Contracts\BroadcastableOrder;
use App\Services\Receipt\ReceiptDataService;

/**
 * [PRINT-SAGA 2026-06-24] Renders an Order into a raw ESC/POS byte stream for a
 * thermal receipt printer (e.g. the counter SAGA, USB on the caisse PC).
 *
 * Reads the SAME source of truth as the on-screen receipt:
 *   - line items + composition from order_items.composition_snapshot (NF525 SSOT),
 *   - NF525 header (fiscal seq / SIRET / TVA intra / legal footer / operator) via
 *     {@see ReceiptDataService} so the printed ticket cannot drift from the API/HTML one.
 *
 * ENCODING CONTRACT: the EscPosCommandBuilder text methods (lineKV/textLine/...)
 * do UTF-8-safe layout (mb_strlen / mb_strimwidth / preg `/u`). So we assemble the
 * WHOLE ticket in UTF-8 and transcode to the printer code page (CP858) ONCE at the
 * end — never per-string, which would feed invalid-UTF-8 bytes back into the
 * `/u` sanitizer and silently DROP accented labels (« Viande supplémentaire »).
 *
 * Money is formatted FR ("7,90 EUR").
 */
final class OrderReceiptEscPosRenderer
{
    public function __construct(
        private readonly ReceiptDataService $receiptData = new ReceiptDataService,
        private readonly KitchenTicketSymbolicFormatter $symbolic = new KitchenTicketSymbolicFormatter,
    ) {}

    /** Client (fiscal) ticket — prices, totals, TVA, payment, NF525 footer. */
    public function renderClientTicket(BroadcastableOrder $order, array $opts = []): string
    {
        // [TICKET-WIDTH 2026-07-04] Largeur PILOTÉE PAR CONFIG (défaut 48). Cale-la sur la
        // largeur PHYSIQUE de l'imprimante (SAGA=42) via RECEIPT_WIDTH_CHARS : au-delà,
        // l'imprimante ré-enroule les derniers caractères (adresse/prix/en-tête coupés).
        $w = (int) ($opts['width_chars'] ?? 0) ?: ((int) config('printing.receipt.width_chars', 0) ?: 48);
        $codePage = (int) ($opts['code_page'] ?? 19); // 19 = CP858 (FR accents + €)
        $isDuplicata = (bool) ($opts['is_duplicata'] ?? false);
        $counterCopy = (bool) ($opts['counter_copy'] ?? false);
        $head = $this->receiptData->buildForOrderModel($order);
        $branch = $order->branch;

        $b = EscPosCommandBuilder::init();
        $b .= EscPosCommandBuilder::selectCodePage($codePage);

        // ── En-tête établissement (centré) ──────────────────────────────────
        // [TICKET-WIDTHSAFE 2026-07-01] Nom en double HAUTEUR + gras (PAS double largeur :
        // celle-ci débordait le papier 58mm → ré-enroulement). Toutes les lignes sont
        // word-wrappées à la largeur pour ne JAMAIS être coupées par l'imprimante.
        $b .= EscPosCommandBuilder::alignCenter();
        $b .= EscPosCommandBuilder::doubleHeight(true).EscPosCommandBuilder::bold(true);
        $b .= EscPosCommandBuilder::textWrap(optional($branch)->name ?: 'LE CAYENNE', $w);
        $b .= EscPosCommandBuilder::doubleHeight(false).EscPosCommandBuilder::bold(false);
        // [TICKET-ADRESSE 2026-07-03] Adresse = branche si renseignée, SINON défaut config
        // (`printing.receipt.address`) → design pro (nom/adresse/tél) même sans adresse en base.
        $address = (string) (optional($branch)->address ?: config('printing.receipt.address', ''));
        if (trim($address) !== '') {
            $b .= EscPosCommandBuilder::textWrap($address, $w);
        }
        // [TICKET-PHONE 2026-07-03] Téléphone = branche si renseigné, SINON défaut config
        // (`printing.receipt.phone`, ex. 03 65 67 82 91) → le n° apparaît TOUJOURS sur le
        // ticket même quand la branche V1 n'a pas de téléphone en base.
        $phone = (string) (optional($branch)->phone ?: config('printing.receipt.phone', ''));
        if (trim($phone) !== '') {
            $b .= EscPosCommandBuilder::textWrap('Tél : '.$this->formatPhone($phone), $w);
        }
        if (optional($branch)->email) {
            $b .= EscPosCommandBuilder::textWrap('E-mail : '.$branch->email, $w);
        }
        $website = (string) config('printing.receipt.website', '');
        if ($website !== '') {
            $b .= EscPosCommandBuilder::textWrap('Web : '.$website, $w);
        }
        if (! empty($head['pos_siret'])) {
            $b .= EscPosCommandBuilder::textWrap('SIRET '.$head['pos_siret'], $w);
        }
        $b .= EscPosCommandBuilder::separator('-', $w);

        // ── N° de ticket (gros) + date (centrés) ────────────────────────────
        $serial = (string) ($order->order_serial_no ?? $order->id);
        $ticketNo = (string) ($order->queue_number ?: $serial);
        // Le n° de file (« A0004 ») est court → double taille OK s'il tient en largeur/2,
        // sinon double HAUTEUR (jamais de débordement largeur).
        if (mb_strlen($ticketNo) <= max(1, (int) floor($w / 2))) {
            $b .= EscPosCommandBuilder::doubleSize(true).EscPosCommandBuilder::bold(true);
            $b .= EscPosCommandBuilder::textLine($ticketNo);
            $b .= EscPosCommandBuilder::doubleSize(false).EscPosCommandBuilder::bold(false);
        } else {
            $b .= EscPosCommandBuilder::doubleHeight(true).EscPosCommandBuilder::bold(true);
            $b .= EscPosCommandBuilder::textWrap($ticketNo, $w);
            $b .= EscPosCommandBuilder::doubleHeight(false).EscPosCommandBuilder::bold(false);
        }
        $dt = $order->order_datetime ?? $order->created_at;
        if ($dt) {
            $b .= EscPosCommandBuilder::textWrap($this->frenchDateTime($dt), $w);
        }
        $b .= EscPosCommandBuilder::separator('-', $w);

        // ── Bannière type de commande (double HAUTEUR + gras, pas double largeur) ──
        $orderType = $this->orderTypeLabel($order);
        if ($orderType !== '') {
            $b .= EscPosCommandBuilder::doubleHeight(true).EscPosCommandBuilder::bold(true);
            $b .= EscPosCommandBuilder::textWrap('*** '.mb_strtoupper($orderType).' ***', $w);
            $b .= EscPosCommandBuilder::doubleHeight(false).EscPosCommandBuilder::bold(false);
        }
        // [C2-CAISSE 2026-07-05] Nom du client (optionnel) → appeler la commande par le nom.
        $clientName = trim((string) ($order->pos_customer_name ?? ''));
        if ($clientName !== '') {
            $b .= EscPosCommandBuilder::bold(true).EscPosCommandBuilder::textWrap('Client : '.$clientName, $w).EscPosCommandBuilder::bold(false);
        }
        // [C4-CAISSE-TELEPHONE FIX-3 2026-07-07] Téléphone du client (commande téléphone
        // différée surtout) → rappeler/reconnaître le client au comptoir. Ligne propre
        // width-safe (textWrap ≤ $w) ; champ absent = aucune ligne (0 régression ticket normal).
        $clientPhone = trim((string) ($order->pos_customer_phone ?? ''));
        if ($clientPhone !== '') {
            $b .= EscPosCommandBuilder::bold(true).EscPosCommandBuilder::textWrap('Tel : '.$clientPhone, $w).EscPosCommandBuilder::bold(false);
        }
        if ($counterCopy) {
            $b .= EscPosCommandBuilder::bold(true).EscPosCommandBuilder::textLine('*** COMMANDE BORNE - COPIE CAISSE ***').EscPosCommandBuilder::bold(false);
        }
        if ($isDuplicata) {
            $b .= EscPosCommandBuilder::bold(true).EscPosCommandBuilder::textLine('*** DUPLICATA ***').EscPosCommandBuilder::bold(false);
        }

        // ── Articles ────────────────────────────────────────────────────────
        $b .= EscPosCommandBuilder::alignLeft();
        $b .= EscPosCommandBuilder::doubleHeight(true); // [TICKET-BIG] corps grand & lisible (48 col conservées)
        $b .= EscPosCommandBuilder::feed(1);
        $b .= EscPosCommandBuilder::lineKV('QT ARTICLES', 'MONTANT', $w);
        $b .= EscPosCommandBuilder::separator('-', $w);
        foreach ($this->lines($order) as $line) {
            $b .= $this->renderClientItem($line, $w);
        }
        $b .= EscPosCommandBuilder::separator('-', $w);

        // ── Totaux ──────────────────────────────────────────────────────────
        $b .= EscPosCommandBuilder::lineKV('SOUS-TOTAL :', $this->money((float) ($order->subtotal ?? 0)), $w);
        $discount = (float) ($order->discount ?? 0);
        $b .= EscPosCommandBuilder::lineKV('REDUCTION :', ($discount > 0 ? '-' : '').$this->money($discount), $w);
        $delivery = (float) ($order->delivery_charge ?? 0);
        if ($delivery > 0) {
            $b .= EscPosCommandBuilder::lineKV('LIVRAISON :', $this->money($delivery), $w);
        }
        $b .= EscPosCommandBuilder::separator('-', $w);
        // [TICKET-WIDTHSAFE] Total en double HAUTEUR + gras (pas double largeur), montant
        // atomique aligné à droite sur toute la largeur → jamais coupé.
        $b .= EscPosCommandBuilder::bold(true).EscPosCommandBuilder::doubleHeight(true);
        $b .= EscPosCommandBuilder::lineItemKV('MONTANT TOTAL', $this->money((float) ($order->total ?? 0)), $w);
        $b .= EscPosCommandBuilder::bold(false);
        $b .= EscPosCommandBuilder::separator('-', $w);

        // ── TVA (par taux) ──────────────────────────────────────────────────
        $taxLines = $this->taxLines($order);
        if (! empty($taxLines)) {
            foreach ($taxLines as $tl) {
                $rate = rtrim(rtrim(number_format((float) $tl['rate'], 2, ',', ''), '0'), ',');
                $b .= EscPosCommandBuilder::lineKV('TVA '.$rate.'% :', $this->money($tl['tax']), $w);
            }
        } elseif ((float) ($order->total_tax ?? 0) > 0) {
            $b .= EscPosCommandBuilder::lineKV('TVA :', $this->money((float) $order->total_tax), $w);
        }

        // ── Paiement (ou à régler en caisse si non payé) ────────────────────
        $payments = $this->payments($order);
        if (! empty($payments)) {
            foreach ($payments as $p) {
                $b .= EscPosCommandBuilder::lineKV(mb_strtoupper($p['label']).' :', $this->money($p['amount']), $w);
                if (($p['change'] ?? 0) > 0) {
                    $b .= EscPosCommandBuilder::lineKV('RENDU :', $this->money($p['change']), $w);
                }
            }
        } elseif ((int) ($order->payment_status ?? 0) === \App\Enums\PaymentStatus::PAID) {
            // Paid outside the POS tender flow (e.g. online/web order) — never tell the
            // customer to pay again; just confirm it is settled.
            $b .= EscPosCommandBuilder::alignCenter();
            $b .= EscPosCommandBuilder::bold(true).EscPosCommandBuilder::textLine('*** PAYÉ ***').EscPosCommandBuilder::bold(false);
            $b .= EscPosCommandBuilder::alignLeft();
        } elseif ((float) ($order->total ?? 0) > 0) {
            $b .= EscPosCommandBuilder::lineKV('A REGLER TTC :', $this->money((float) $order->total), $w);
            $b .= EscPosCommandBuilder::alignCenter();
            $b .= EscPosCommandBuilder::bold(true).EscPosCommandBuilder::textLine('** A REGLER EN CAISSE **').EscPosCommandBuilder::bold(false);
            $b .= EscPosCommandBuilder::alignLeft();
        }

        // ── Pied : remerciement + mentions fiscales (centré) ────────────────
        $b .= EscPosCommandBuilder::separator('-', $w);
        $b .= EscPosCommandBuilder::alignCenter();
        $b .= EscPosCommandBuilder::bold(true).EscPosCommandBuilder::textWrap('BON APPÉTIT ET À BIENTÔT !', $w).EscPosCommandBuilder::bold(false);
        $b .= EscPosCommandBuilder::doubleHeight(false); // mentions légales en taille normale (fine print)
        $b .= EscPosCommandBuilder::feed(1);
        if (! empty($head['fiscal_sequence_no'])) {
            $b .= EscPosCommandBuilder::textWrap('Ticket fiscal N '.$head['fiscal_sequence_no'], $w);
        }
        if (! empty($head['pos_vat_intra'])) {
            $b .= EscPosCommandBuilder::textWrap('TVA '.$head['pos_vat_intra'], $w);
        }
        if (! empty($head['pos_legal_footer'])) {
            $b .= EscPosCommandBuilder::textWrap((string) $head['pos_legal_footer'], $w);
        }
        $b .= EscPosCommandBuilder::textWrap('Prix nets en euros TTC', $w);

        // ── Bloc opération (gauche, infos techniques discrètes) ─────────────
        $b .= EscPosCommandBuilder::separator('-', $w);
        $b .= EscPosCommandBuilder::alignLeft();
        $b .= EscPosCommandBuilder::textWrap('Operation : VENTE', $w);
        if (! empty($head['operator_name'])) {
            $b .= EscPosCommandBuilder::textWrap('Caissier : '.$head['operator_name'], $w);
        }
        $b .= EscPosCommandBuilder::textWrap('Ticket : '.$serial, $w);
        if ($dt) {
            $b .= EscPosCommandBuilder::textWrap($this->frenchDateTime($dt), $w);
        }
        // [TICKET-FEED 2026-06-30] Queue plus longue à hauteur NORMALE avant la coupe
        // (le ticket tombait : feed(3) ≈ 10 mm ne dégageait pas la barre de coupe
        // ~15-20 mm au-dessus de la tête). Longueur + mode (full/partial) pilotés par
        // config/printing.php → réglables sur la vraie SAGA sans redéployer le code.
        // [TICKET-BORNE-LONG 2026-07-02] Feed + coupe surchargeables par le contexte (la borne
        // passe une queue plus longue + coupe partielle pour que le ticket ne tombe pas).
        $feedLines = (int) ($opts['feed_lines'] ?? $this->feedLinesBeforeCut());
        $partial = array_key_exists('cut_partial', $opts) ? (bool) $opts['cut_partial'] : $this->cutIsPartial();
        $b .= EscPosCommandBuilder::doubleHeight(false);
        $b .= EscPosCommandBuilder::feed($feedLines);
        $b .= EscPosCommandBuilder::cut($partial);

        // Transcode the WHOLE assembled stream once (control bytes are <0x80 and
        // pass through iconv unchanged; only the UTF-8 text becomes CP858).
        return EscPosCommandBuilder::encodeForPrinter($b, $this->encodingFor($codePage));
    }

    /** Kitchen (production) ticket — composition + instruction, NO prices. */
    public function renderKitchenTicket(BroadcastableOrder $order, array $opts = []): string
    {
        // [TICKET-WIDTH 2026-07-04] Largeur PILOTÉE PAR CONFIG (défaut 48). Cale-la sur la
        // largeur PHYSIQUE de l'imprimante (SAGA=42) via RECEIPT_WIDTH_CHARS : au-delà,
        // l'imprimante ré-enroule les derniers caractères (adresse/prix/en-tête coupés).
        $w = (int) ($opts['width_chars'] ?? 0) ?: ((int) config('printing.receipt.width_chars', 0) ?: 48);
        $codePage = (int) ($opts['code_page'] ?? 19);

        $b = EscPosCommandBuilder::init();
        $b .= EscPosCommandBuilder::selectCodePage($codePage);
        $b .= EscPosCommandBuilder::alignCenter();
        $b .= EscPosCommandBuilder::bold(true);
        $b .= EscPosCommandBuilder::textLine('CUISINE');
        $b .= EscPosCommandBuilder::bold(false);
        // [AUDIT F1] Same call number as the client ticket (queue, not the long serial),
        // big so the cook can match it when handing the order over.
        $callNo = (string) ($order->queue_number ?: ($order->order_serial_no ?? $order->id));
        // [TICKET-WIDTHSAFE] n° court → double taille ; sinon double hauteur (jamais déborder).
        if (mb_strlen($callNo) <= max(1, (int) floor($w / 2))) {
            $b .= EscPosCommandBuilder::doubleSize(true).EscPosCommandBuilder::bold(true);
            $b .= EscPosCommandBuilder::textLine($callNo);
            $b .= EscPosCommandBuilder::doubleSize(false).EscPosCommandBuilder::bold(false);
        } else {
            $b .= EscPosCommandBuilder::doubleHeight(true).EscPosCommandBuilder::bold(true);
            $b .= EscPosCommandBuilder::textWrap($callNo, $w);
            $b .= EscPosCommandBuilder::doubleHeight(false).EscPosCommandBuilder::bold(false);
        }
        // [AUDIT F2] Order type — packaging/prep differs (sur place / à emporter / livraison).
        $orderType = $this->orderTypeLabel($order);
        if ($orderType !== '') {
            $b .= EscPosCommandBuilder::bold(true).EscPosCommandBuilder::textWrap('*** '.mb_strtoupper($orderType).' ***', $w).EscPosCommandBuilder::bold(false);
        }
        // [C2-CAISSE 2026-07-05] Nom du client aussi en cuisine (appel de commande).
        $kClientName = trim((string) ($order->pos_customer_name ?? ''));
        if ($kClientName !== '') {
            $b .= EscPosCommandBuilder::bold(true).EscPosCommandBuilder::textWrap('Client : '.$kClientName, $w).EscPosCommandBuilder::bold(false);
        }
        // [C4-CAISSE-TELEPHONE FIX-3 2026-07-07] Téléphone client aussi en cuisine (rappel/
        // reconnaissance d'une commande téléphone). Width-safe ; absent = rien.
        $kClientPhone = trim((string) ($order->pos_customer_phone ?? ''));
        if ($kClientPhone !== '') {
            $b .= EscPosCommandBuilder::bold(true).EscPosCommandBuilder::textWrap('Tel : '.$kClientPhone, $w).EscPosCommandBuilder::bold(false);
        }
        $dt = $order->order_datetime ?? $order->created_at;
        if ($dt) {
            $b .= EscPosCommandBuilder::textLine($dt->format('H:i'));
        }
        $b .= EscPosCommandBuilder::separator('=', $w);
        $b .= EscPosCommandBuilder::alignLeft();
        $b .= EscPosCommandBuilder::doubleHeight(true); // [TICKET-BIG] cuisine grand & lisible

        // [KITCHEN-SYMBOLS 2026-06-28 / KITCHEN-2LINES 2026-06-30] Le cuisinier lit du
        // symbolique en 2 lignes CLAIRES par produit :
        //   L1 : qty x [Support] | [Produit] | [Taille] | [Viande] | [Crudités] | [Sauce]
        //   L2 : MENU (: sauce frites en symbole)  + suppléments
        // Un item « Menu/Formule » séparé est FUSIONNÉ comme ligne 2 du produit précédent
        // (pas de bloc « MENU » isolé) → 2 lignes nettes, jamais le détail « Frites+Boisson ».
        $blocks = [];
        foreach (($order->orderItems ?? collect()) as $oi) {
            $name = (string) ($oi->name ?? optional($oi->orderItem)->name ?? 'Article');
            $snap = is_array($oi->composition_snapshot) ? $oi->composition_snapshot : [];
            $qty = max(1, (int) ($oi->quantity ?? 1));
            $instruction = (string) ($oi->instruction ?? '');

            if ($this->symbolic->isMenuItem($name)) {
                // Item Menu/Formule = SKU séparé → ligne propre, claire et symbolique :
                // « N x MENU : <sauce frites> », jamais le détail « Frites + Boisson » ni un prix.
                // (Pas de fusion devinée : le menu n'est pas forcément adjacent à son produit.)
                $sym = $this->symbolic->fritesSauceSymbol($instruction);
                $menuLine = $sym !== '' ? 'MENU : '.$sym : 'MENU';
                // [W3-FIX-C 2026-07-06] La boisson de la formule sort AUSSI en cuisine
                // (owner : le cuisinier prépare les boissons).
                $blocks[] = ['head' => $qty.' x '.$menuLine, 'menu' => null, 'supps' => [], 'drinks' => $this->symbolic->drinkLines($snap), 'notes' => []];

                continue;
            }

            $menu = $this->symbolic->menuLine($snap);
            if ($menu === 'MENU') {
                $sym = $this->symbolic->fritesSauceSymbol($instruction);
                $menu = $sym !== '' ? 'MENU : '.$sym : 'MENU';
            }
            $note = $this->symbolic->cleanInstruction($instruction, $name);
            // [W3-FIX-C 2026-07-06] Item BOISSON (Coca standalone) → NOM COMPLET :
            // « 1 x COC » ne dit pas au cuisinier QUELLE boisson préparer. Jumeau
            // écran : kdsSymbolic.js renderItemSymbolic (categorize==='drink').
            $head = $this->symbolic->isDrinkItem($name)
                ? $qty.' x '.trim($name)
                : $qty.' x '.$this->symbolic->mainLine($name, $snap);
            $blocks[] = [
                'head' => $head,
                'menu' => $menu !== '' ? $menu : null,
                'supps' => $this->symbolic->supplementLines($snap),
                'drinks' => $this->symbolic->drinkLines($snap),
                'notes' => array_values(array_filter(array_map('trim', explode("\n", $note)))),
            ];
        }

        $halfW = (int) max(8, floor($w / 2));
        foreach ($blocks as $blk) {
            // [T2-CUISINE 2026-07-05] Ligne produit en DOUBLE TAILLE (2×2) → grosse & lisible de
            // loin (owner : « ça sort trop petit »). Avec les codes 3 lettres (CAY/TER) elle tient
            // en général sur une ligne. Enroulée à la MOITIÉ de la largeur (double-largeur = 2 col
            // physiques/caractère) → JAMAIS coupée par l'imprimante, elle passe à la ligne proprement.
            $b .= EscPosCommandBuilder::doubleSize(true).EscPosCommandBuilder::bold(true);
            foreach (EscPosCommandBuilder::wrapIndented($blk['head'], $halfW, '  ') as $headLine) {
                $b .= EscPosCommandBuilder::textLine($headLine);
            }
            // Retour en double HAUTEUR (grand mais pleine largeur) pour le détail menu/suppléments.
            $b .= EscPosCommandBuilder::bold(false).EscPosCommandBuilder::doubleSize(false).EscPosCommandBuilder::doubleHeight(true);
            if ($blk['menu'] !== null) {
                $b .= EscPosCommandBuilder::bold(true);
                foreach (EscPosCommandBuilder::wrapIndented($blk['menu'], $w - 2, '  ') as $menuLine) {
                    $b .= EscPosCommandBuilder::textLine('  '.$menuLine);
                }
                $b .= EscPosCommandBuilder::bold(false);
            }
            // [T3-CUISINE 2026-07-05] Suppléments en GRAS + étoile « * » → le cuisinier voit tout
            // de suite qu'il y a un extra (owner). Étoile ASCII (les emojis ne s'encodent pas CP858).
            foreach ($blk['supps'] as $sup) {
                $star = preg_replace('/^\+\s*/', '* ', (string) $sup);
                $b .= EscPosCommandBuilder::bold(true);
                foreach (EscPosCommandBuilder::wrapIndented($star, $w - 2, '  ') as $supLine) {
                    $b .= EscPosCommandBuilder::textLine('  '.$supLine);
                }
                $b .= EscPosCommandBuilder::bold(false);
            }
            // [W3-FIX-C 2026-07-06] Boissons (addon drink / menu_boisson) en GRAS,
            // même gabarit width-safe que menu/suppléments (jamais coupées à 32 col).
            foreach (($blk['drinks'] ?? []) as $drink) {
                $b .= EscPosCommandBuilder::bold(true);
                foreach (EscPosCommandBuilder::wrapIndented((string) $drink, $w - 2, '  ') as $drinkLine) {
                    $b .= EscPosCommandBuilder::textLine('  '.$drinkLine);
                }
                $b .= EscPosCommandBuilder::bold(false);
            }
            foreach ($blk['notes'] as $n) {
                foreach (EscPosCommandBuilder::wrapIndented('** '.$n, $w - 2, '  ') as $noteLine) {
                    $b .= EscPosCommandBuilder::textLine('  '.$noteLine);
                }
            }
            $b .= EscPosCommandBuilder::textLine('');
        }
        // [TICKET-FEED 2026-06-30] Queue plus longue à hauteur NORMALE avant la coupe
        // (le ticket tombait : feed(3) ≈ 10 mm ne dégageait pas la barre de coupe
        // ~15-20 mm au-dessus de la tête). Longueur + mode (full/partial) pilotés par
        // config/printing.php → réglables sur la vraie SAGA sans redéployer le code.
        $b .= EscPosCommandBuilder::doubleHeight(false);
        $b .= EscPosCommandBuilder::feed($this->feedLinesBeforeCut());
        $b .= EscPosCommandBuilder::cut($this->cutIsPartial());

        return EscPosCommandBuilder::encodeForPrinter($b, $this->encodingFor($codePage));
    }

    /** [TICKET-FEED] Nb de lignes vierges (hauteur normale) avancées avant la coupe. */
    private function feedLinesBeforeCut(): int
    {
        return max(1, (int) config('printing.cut.feed_lines_before_cut', 8));
    }

    /** [TICKET-FEED] true = coupe partielle (GS V 1, le ticket reste accroché). */
    private function cutIsPartial(): bool
    {
        return strtolower((string) config('printing.cut.mode', 'full')) === 'partial';
    }

    /**
     * @return array<int, array{name:string, qty:int, total:float, comps:array<int,string>, extras:array<int,array{name:string,amount:float}>, addons:array<int,array{name:string,amount:float}>, instruction:string}>
     */
    private function lines(BroadcastableOrder $order): array
    {
        $items = $order->orderItems ?? collect();
        $out = [];
        foreach ($items as $oi) {
            $name = (string) ($oi->name ?? optional($oi->orderItem)->name ?? 'Article');
            $snap = is_array($oi->composition_snapshot) ? $oi->composition_snapshot : [];
            $comps = [];
            foreach (($snap['lines'] ?? []) as $l) {
                $group = trim((string) ($l['attribute_name'] ?? ''));
                $value = trim((string) ($l['variation_name'] ?? $l['name'] ?? ''));
                if ($value === '') {
                    continue;
                }
                $comps[] = $group !== '' ? "$group: $value" : $value;
            }
            $extras = [];
            foreach (($snap['extras'] ?? []) as $e) {
                $en = trim((string) ($e['extra_name'] ?? $e['name'] ?? ''));
                if ($en === '') {
                    continue;
                }
                $extras[] = ['name' => $en, 'amount' => (float) ($e['line_total'] ?? $e['unit_price'] ?? 0)];
            }
            $addons = [];
            foreach (($snap['addons'] ?? []) as $a) {
                $an = trim((string) ($a['addon_name'] ?? $a['name'] ?? ''));
                if ($an === '') {
                    continue;
                }
                $addons[] = ['name' => $an, 'amount' => (float) ($a['line_total'] ?? 0)];
            }
            $out[] = [
                'name' => $name,
                'qty' => max(1, (int) ($oi->quantity ?? 1)),
                'total' => (float) ($oi->total_price ?? 0),
                'comps' => $comps,
                'extras' => $extras,
                'addons' => $addons,
                'instruction' => trim((string) ($oi->instruction ?? '')),
            ];
        }

        return $out;
    }

    /**
     * One article block on the client ticket (VAZY-GOOD style) :
     *   "1  Tacos M ................ 9,40 EUR"
     *   "   Cordon Bleu, Fricadelle, Samouraï"     (compo + free crudités, compact)
     *   "   + Cheddar ............... 0,90 EUR"     (paid supplements, with price)
     */
    private function renderClientItem(array $line, int $w): string
    {
        $qty = (int) $line['qty'];
        $b = EscPosCommandBuilder::bold(true);
        // [TICKET-WIDTHSAFE] nom long → enroulé sous le prix (jamais tronqué), prix atomique.
        $b .= EscPosCommandBuilder::lineItemKV($qty.'  '.$line['name'], $this->money((float) $line['total']), $w);
        $b .= EscPosCommandBuilder::bold(false);
        if ($qty > 1 && (float) $line['total'] > 0) {
            $unit = round((float) $line['total'] / $qty, 2);
            $b .= EscPosCommandBuilder::textLine('   ('.$qty.' x '.$this->money($unit).')');
        }

        // Compo values (strip the "Group: " prefix) + free (0-price) extras → one compact line.
        $compo = [];
        foreach ($line['comps'] as $c) {
            $pos = mb_strpos($c, ': ');
            $compo[] = $pos !== false ? mb_substr($c, $pos + 2) : $c;
        }
        $paid = [];
        foreach ($line['extras'] as $e) {
            if (($e['amount'] ?? 0) > 0) {
                $paid[] = $e;
            } else {
                $compo[] = $e['name'];
            }
        }
        if (! empty($compo)) {
            // Word-wrap with hanging indent so a long ingredient list never overflows
            // the printer width (e.g. 67-col compo on a 48-col SAGA would wrap raw).
            foreach (EscPosCommandBuilder::wrapIndented(implode(', ', $compo), $w, '   ') as $compoLine) {
                $b .= EscPosCommandBuilder::textLine($compoLine);
            }
        }
        foreach ($paid as $e) {
            $b .= EscPosCommandBuilder::lineItemKV('   + '.$e['name'], $this->money((float) $e['amount']), $w);
        }
        foreach ($line['addons'] as $a) {
            if (($a['amount'] ?? 0) > 0) {
                $b .= EscPosCommandBuilder::lineItemKV('   + '.$a['name'], $this->money((float) $a['amount']), $w);
            } else {
                foreach (EscPosCommandBuilder::wrapIndented('+ '.$a['name'], $w, '   ') as $addonLine) {
                    $b .= EscPosCommandBuilder::textLine($addonLine);
                }
            }
        }
        // Note client assainie : on retire le nom-produit échoé, le blob de compo
        // (déjà affiché) et les lignes menu/sauce-frites (le menu est sa propre ligne).
        $note = $this->symbolic->cleanInstruction((string) ($line['instruction'] ?? ''), (string) $line['name']);
        foreach (array_filter(explode("\n", $note)) as $noteLine) {
            // [TICKET-WIDTHSAFE 2026-07-01] Wrap la note client (miroir de la cuisine) — une note
            // libre longue débordait la largeur (asymétrie client/cuisine relevée à l'audit).
            foreach (EscPosCommandBuilder::wrapIndented('** '.trim($noteLine), $w - 3, '   ') as $wrapped) {
                $b .= EscPosCommandBuilder::textLine('   '.$wrapped);
            }
        }

        return $b;
    }

    /** "0365678291" → "03 65 67 82 91" (French 10-digit); otherwise unchanged. */
    private function formatPhone(string $p): string
    {
        $digits = preg_replace('/\D+/', '', $p);
        if (strlen($digits) === 10 && $digits[0] === '0') {
            return trim(chunk_split($digits, 2, ' '));
        }

        return trim($p);
    }

    /** "27 juin 2026 23:49" — French long date without a locale dependency. */
    private function frenchDateTime($dt): string
    {
        if (! $dt) {
            return '';
        }
        $months = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        $m = (int) $dt->format('n');

        return (int) $dt->format('j').' '.($months[$m] ?? '').' '.$dt->format('Y').' '.$dt->format('H:i');
    }

    /** @return array<int, array{label:string, amount:float, change:float}> */
    private function payments(BroadcastableOrder $order): array
    {
        // [SELF-AUDIT R3 P3 2026-07-05 — libellé tender faux sur ticket fiscal] La map était périmée :
        // PosPaymentMethod définit MOBILE_BANKING=3 et OTHER=4, mais le ticket imprimait 3='Mixte'
        // (valeur enum inexistante) et 4='Carte' — un tender NON-carte annoncé CARTE sur un document
        // fiscal NF525. Alignement sur l'enum + le reçu à l'écran (ReceiptComponent).
        $labels = [1 => 'Espèces', 2 => 'Carte', 3 => 'Mobile banking', 4 => 'Autre', 5 => 'Ticket Resto'];
        $method = $order->pos_payment_method ?? null;
        if ($method === null || $method === '') {
            return [];
        }
        // [SYNC-BORNE 2026-06-30] COUNTER_DEFERRED (6) = commande borne Plan B PAS encore
        // encaissée au comptoir (received=0, payment_status=PENDING_COUNTER). Ce n'est PAS un
        // règlement réel : on retourne [] pour que le ticket affiche « ** A REGLER EN CAISSE ** »
        // au lieu de « PAIEMENT 6 : 0,00 € ». À l'encaissement, confirmCounterPayment() écrase
        // pos_payment_method par la vraie méthode (1/2…) → le ticket final montre le vrai paiement.
        if ((int) $method === \App\Enums\PosPaymentMethod::COUNTER_DEFERRED) {
            return [];
        }
        $amount = $order->pos_received_amount !== null && $order->pos_received_amount !== ''
            ? (float) $order->pos_received_amount
            : (float) ($order->total ?? 0);

        return [[
            'label' => $labels[(int) $method] ?? ('Paiement '.$method),
            'amount' => $amount,
            'change' => (float) ($order->cash_back_amount ?? 0),
        ]];
    }

    private function orderTypeLabel(BroadcastableOrder $order): string
    {
        return match ((int) ($order->order_type ?? 0)) {
            \App\Enums\OrderType::DELIVERY => 'Livraison',
            \App\Enums\OrderType::TAKEAWAY => 'À emporter',
            \App\Enums\OrderType::DINING_TABLE => 'Sur place',
            \App\Enums\OrderType::KIOSK => 'Commande borne',
            \App\Enums\OrderType::POS => 'Sur place',
            default => '',
        };
    }

    /**
     * Ventilation de la TVA par taux — mirror of OrderDetailsResource::buildTaxLines
     * (groupe par taux, base HT = total TTC ligne - TVA ligne).
     *
     * @return array<int, array{name:string, rate:string, ht:float, tax:float}>
     */
    private function taxLines(BroadcastableOrder $order): array
    {
        $groups = [];
        foreach (($order->orderItems ?? collect()) as $oi) {
            $rate = (string) (0 + (float) ($oi->tax_rate ?? 0));
            $name = (string) ($oi->tax_name ?? 'TVA');
            $type = (int) ($oi->tax_type ?? 0);
            $key = $type.'|'.$rate.'|'.$name;
            if (! isset($groups[$key])) {
                $groups[$key] = ['name' => $name, 'rate' => $rate, 'ht' => 0.0, 'tax' => 0.0];
            }
            $tax = (float) ($oi->tax_amount ?? 0);
            $ttc = (float) ($oi->total_price ?? 0);
            $groups[$key]['tax'] += $tax;
            $groups[$key]['ht'] += max(0.0, $ttc - $tax);
        }

        $groups = array_values(array_filter($groups, fn ($g) => $g['tax'] > 0));

        // [AUDIT P2-1] An order-level discount is NOT allocated back to per-line tax,
        // so the gross ventilation would overstate the VAT and not reconcile with the
        // (discounted) total. Prorate each rate by the net/gross ratio on the goods so
        // Σ(base HT + TVA) matches what the customer actually pays.
        $discount = (float) ($order->discount ?? 0);
        if ($discount > 0 && ! empty($groups)) {
            $gross = array_sum(array_map(fn ($g) => $g['ht'] + $g['tax'], $groups));
            if ($gross > 0) {
                $ratio = max(0.0, ($gross - $discount) / $gross);
                foreach ($groups as &$g) {
                    $g['ht'] = round($g['ht'] * $ratio, 2);
                    $g['tax'] = round($g['tax'] * $ratio, 2);
                }
                unset($g);
            }
        }

        return $groups;
    }

    private function money(float $v): string
    {
        // Owner: symbole € (pas « EUR »). CP858 (code page 19) contient € → encodé
        // correctement par encodeForPrinter en fin de flux.
        return number_format(round($v, 2), 2, ',', ' ').' €';
    }

    private function encodingFor(int $codePage): string
    {
        return $codePage === 16 ? 'CP1252' : 'CP858';
    }
}
