<?php

namespace App\Support;

/**
 * [ONB-07 2026-08-28] Le mode de paiement, en français, côté serveur — une seule fois.
 *
 * Le produit avait DEUX correspondances pour la même chose, et un endroit qui n'en
 * avait aucune :
 *   · `resources/js/helpers/paymentMethodLabel.js` — extraite en helper partagé le
 *     2026-07-07 précisément pour cesser de la dupliquer par composant ;
 *   · `resources/views/pdf/sales_report.blade.php:114-137` — une fonction GLOBALE
 *     déclarée en ligne dans un gabarit, posée le même jour pour « la fin de la fuite
 *     d'enum brut COUNTER_CASH » sur le chemin PDF ;
 *   · `app/Exports/TransactionExport.php` — rien du tout. Le tableur sortait
 *     « COUNTER_CASH » là où l'écran affiche « Espèces (Caisse) ».
 *
 * Le correctif de juillet a couvert les écrans, puis les tickets, puis le PDF. Le
 * tableur n'a jamais été fait — et c'est le seul document que le commerçant transmet
 * à son comptable.
 *
 * Cette classe est la version PHP unique. Le libellé rendu est IDENTIQUE à celui du
 * gabarit PDF, mot pour mot : ce commit corrige le tableur, il ne renomme rien de ce
 * qui est déjà imprimé.
 *
 * ⚠️ Divergence CONNUE et NON tranchée ici, à arbitrer par le propriétaire : le
 * fichier de langue et cette table ne disent pas la même chose pour trois modes —
 * `ticket_restaurant` (« Ticket Restaurant » à l'écran, « Titre-restaurant » sur le
 * PDF), `mobile_banking` (« MFS » à l'écran, « Paiement mobile » sur le PDF) et
 * `split` (« Multi-paiement » à l'écran, « Mixte » sur le PDF). Les unifier change le
 * vocabulaire visible en caisse ; ce n'est pas une décision d'implémentation.
 */
final class LibellePaiement
{
    /**
     * Correspondance française, reprise VERBATIM du gabarit PDF pour ne rien changer
     * à ce qui s'imprime déjà.
     */
    private const LIBELLES = [
        // Encaissé au comptoir : le qualificatif « (Caisse) » distingue une commande
        // borne réglée à la caisse d'un paiement direct.
        'counter_cash'              => 'Espèces (Caisse)',
        'counter_card'              => 'Carte (Caisse)',
        'counter_mobile_banking'    => 'Paiement mobile (Caisse)',
        'counter_ticket_restaurant' => 'Titre-restaurant (Caisse)',
        'counter_other'             => 'Autre (Caisse)',
        // Paiements directs.
        'cash'                      => 'Espèces',
        'card'                      => 'Carte',
        'credit'                    => 'Carte',
        'ticket_restaurant'         => 'Titre-restaurant',
        'mobile_banking'            => 'Paiement mobile',
        'split'                     => 'Mixte',
        'other'                     => 'Autre',
        'cash_on_delivery'          => 'Espèces',
    ];

    /**
     * @param string|null $slug identifiant machine renvoyé par le back (toute casse)
     * @param string      $defaut ce qu'on rend quand il n'y a pas de mode du tout
     */
    public static function pour(?string $slug, string $defaut = '—'): string
    {
        $slug = strtolower(trim((string) $slug));

        if ($slug === '') {
            return $defaut;
        }

        // Un identifiant inconnu est HUMANISÉ, jamais rendu brut : une passerelle
        // ajoutée demain sortira « My Gateway », pas « MY_GATEWAY ».
        return self::LIBELLES[$slug] ?? ucwords(str_replace('_', ' ', $slug));
    }
}
