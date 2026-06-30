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
    | Ticket BORNE — taille de police (pilotée serveur)
    |--------------------------------------------------------------------------
    |
    | Le ticket borne est imprimé par le pont local `bridge.js` (PC borne). La
    | taille de police est désormais envoyée par le serveur dans le payload
    | (`bodySize` / `titleSize`) : le pont applique `GS ! n` (ESC/POS). Octet n =
    | (largeur×16) | hauteur, multiplicateurs 0–7 (1×–8×).
    |   0x00 = normal | 0x01 = double HAUTEUR | 0x11 = double largeur+hauteur (2×2)
    | Owner: « bien grande » → défaut 2×2. `wrap_width` = caractères par ligne à
    | cette taille (32 normal → 16 en double largeur) pour que la compo ne déborde
    | pas. Modifiable sans toucher la borne (config serveur → redeploy bundle).
    |
    */
    'borne_ticket' => [
        // 0x01 = double HAUTEUR (grand & propre, garde 32 car/ligne). Pour ÉNORME
        // (double largeur+hauteur) passer à 0x11 — le front réduit alors la largeur
        // automatiquement (16 car/ligne) pour ne rien couper.
        'body_size' => (int) env('BORNE_TICKET_BODY_SIZE', 0x01),
        'title_size' => (int) env('BORNE_TICKET_TITLE_SIZE', 0x11), // n° commande 2×2 (ressort)
    ],

    /*
    |--------------------------------------------------------------------------
    | Coupe & avance papier (ticket qui « tombe par terre »)
    |--------------------------------------------------------------------------
    |
    | Owner 2026-06-30 : « ~3 cm sortent puis ça coupe → le ticket tombe ». Cause :
    | la queue (feed avant coupe) ne dégageait pas la barre de coupe (~15-20 mm
    | au-dessus de la tête d'impression). On avance désormais `feed_lines_before_cut`
    | lignes vierges à HAUTEUR NORMALE (8 ≈ 28 mm) pour que le ticket soit assez long
    | à attraper avant la coupe. Réglable sur la VRAIE SAGA sans redéployer le code.
    |   mode = 'full'    → GS V 0 : coupe totale (le ticket se détache).
    |   mode = 'partial' → GS V 1 : coupe partielle (le ticket reste accroché au
    |                       rouleau, ne tombe JAMAIS ; le client le détache à la main).
    | NB : la borne-CLIENT imprime via bridge.js (PC borne, hors repo) qui applique
    | SA propre coupe → ce réglage agit sur la CAISSE (chemin RAW) et la borne-CUISINE.
    |
    */
    'cut' => [
        'feed_lines_before_cut' => (int) env('PRINT_FEED_LINES_BEFORE_CUT', 8),
        'mode' => env('PRINT_CUT_MODE', 'full'), // 'full' | 'partial'
    ],

    /*
    |--------------------------------------------------------------------------
    | Customer pole display (SAGA 2x20 VFD, CD5220 over serial)
    |--------------------------------------------------------------------------
    |
    | Affiche le TOTAL au client à chaque ajout produit, et un message d'accueil
    | en veille. USB→série sur la caisse Windows (driver 'windows_serial').
    |
    */
    'customer_display' => [
        'enabled' => env('CUSTOMER_DISPLAY_ENABLED', false),
        'driver' => env('CUSTOMER_DISPLAY_DRIVER', 'windows_serial'), // windows_serial | none (dev/no-hardware)
        'port' => env('CUSTOMER_DISPLAY_PORT', 'COM3'),
        'baud' => (int) env('CUSTOMER_DISPLAY_BAUD', 9600),
        'code_page' => (int) env('CUSTOMER_DISPLAY_CODE_PAGE', 19), // 19 = CP858
        'welcome_line1' => env('CUSTOMER_DISPLAY_WELCOME1', 'LE CAYENNE'),
        'welcome_line2' => env('CUSTOMER_DISPLAY_WELCOME2', 'Soyez le bienvenu !'),
        'total_label' => env('CUSTOMER_DISPLAY_TOTAL_LABEL', 'TOTAL'),
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
