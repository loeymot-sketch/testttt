<?php

/**
 * [BYPASS-P1 / GATE_BYPASS_PRINTING_2026-05-08] Printing configuration —
 * NF525 receipts (POS + kiosk) + cash drawer + duplicata marker.
 *
 * Le mode bypass court-circuite l'envoi TCP/IP vers l'imprimante thermique
 * tout en préservant : audit log (`pos.receipt.print` / `pos.receipt.reprint`),
 * receipt print count atomic increment, duplicata marker (count >= 2),
 * rendering Vue ReceiptComponent à l'écran.
 *
 * GARDE-FOU PROD : voir AppServiceProvider qui abort 500 si APP_ENV=production
 * et bypass actif. Voir aussi BYPASS_MODE_OPERATIONAL.md runbook.
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Real-mode transport driver
    |--------------------------------------------------------------------------
    |
    | Which PrinterTransport to use when bypass is OFF (production / real mode):
    |   'tcp'         → network ESC/POS (TcpPrinterTransport, port 9100) [default]
    |   'windows_raw' → USB thermal printer on a Windows caisse (winspool RAW;
    |                    requires Laravel running ON that Windows box, single-box V1).
    |                    Set the Windows printer queue name in Printer.host.
    |
    | [PRINT-SAGA 2026-06-24] The counter SAGA is USB on Windows → 'windows_raw'.
    |
    */
    'driver' => env('PRINT_DRIVER', 'tcp'),

    /*
    |--------------------------------------------------------------------------
    | Receipt header extras (single-restaurant V1 — no per-branch column)
    |--------------------------------------------------------------------------
    |
    | Affichés en en-tête du ticket client. Le téléphone et l'e-mail viennent
    | de la branche ; le site web n'a pas de colonne dédiée en V1 → ici.
    |
    */
    'receipt' => [
        'website' => env('RECEIPT_WEBSITE', 'lecayenne.fr'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Bypass Mode — TCP/IP transport short-circuit
    |--------------------------------------------------------------------------
    |
    | Quand activé, AppServiceProvider bind PrinterTransportInterface vers
    | NullPrinterTransport (au lieu de TcpPrinterTransport). Aucun fsockopen,
    | aucun byte envoyé sur le réseau. Le rendu Vue ReceiptComponent reste
    | visible à l'écran (avec marqueur "MODE TEST — IMPRESSION BYPASSÉE")
    | pour validation visuelle E2E.
    |
    */
    'bypass' => [
        'enabled' => env('PRINTING_BYPASS_MODE', false),
        'gate' => 'GATE_BYPASS_PRINTING_2026-05-08',
        'forbidden_environments' => ['production', 'prod', 'live'],
        'keep_screen_render' => true,
        'screen_marker_text' => env('PRINTING_BYPASS_SCREEN_MARKER', '🔧 MODE TEST — IMPRESSION BYPASSÉE'),
        'log_channel' => env('PRINTING_BYPASS_LOG_CHANNEL', 'stack'),
    ],
];
