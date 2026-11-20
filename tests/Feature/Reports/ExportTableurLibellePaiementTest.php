<?php

namespace Tests\Feature\Reports;

use App\Support\LibellePaiement;
use Tests\TestCase;

/**
 * [ONB-07 2026-08-28] Le tableur des transactions sortait des identifiants machine.
 *
 * L'écran affiche « Espèces (Caisse) » ; le tableur écrivait `COUNTER_CASH`. Le
 * correctif du 2026-07-07 — « fin de la fuite d'enum brut COUNTER_CASH » — a couvert
 * les écrans, puis les tickets, puis le PDF. Jamais le tableur, qui est pourtant le
 * seul document que le commerçant transmet à son comptable.
 *
 * La cause est structurelle : la correspondance existait en TROIS exemplaires
 * potentiels — un helper JS partagé, une fonction globale déclarée en ligne dans un
 * gabarit PDF, et rien du tout côté export. Elle vit désormais dans une seule classe
 * PHP, avec les libellés REPRIS VERBATIM du gabarit : ce banc protège la correction
 * du tableur, il ne renomme rien de ce qui s'imprime déjà.
 *
 * RÉFUTATION, à verser au dossier. L'audit signalait aussi « des montants sans
 * devise » dans ce tableur. Ce n'en est pas un : `flatAmountFormat` rend `6.90`, un
 * NOMBRE que le tableur sait additionner. Y coller « 6,90 € » transformerait la
 * colonne en texte et empêcherait le commerçant de faire ses totaux. La divergence
 * avec l'écran est ici volontaire et correcte.
 */
class ExportTableurLibellePaiementTest extends TestCase
{
    public function test_les_modes_encaisses_au_comptoir_portent_le_qualificatif_caisse(): void
    {
        $this->assertSame('Espèces (Caisse)', LibellePaiement::pour('COUNTER_CASH'));
        $this->assertSame('Carte (Caisse)', LibellePaiement::pour('counter_card'));
    }

    public function test_les_paiements_directs_sont_en_francais(): void
    {
        $this->assertSame('Espèces', LibellePaiement::pour('CASH'));
        $this->assertSame('Carte', LibellePaiement::pour('CREDIT'));
        $this->assertSame('Mixte', LibellePaiement::pour('SPLIT'));
    }

    /**
     * Un identifiant inconnu doit être HUMANISÉ, jamais rendu brut : une passerelle
     * ajoutée demain ne doit pas réintroduire la fuite d'enum que ce banc referme.
     */
    public function test_un_identifiant_inconnu_est_humanise_pas_rendu_brut(): void
    {
        $this->assertSame('My Gateway', LibellePaiement::pour('MY_GATEWAY'));
        $this->assertSame('Nexi', LibellePaiement::pour('nexi'));
    }

    public function test_l_absence_de_mode_rend_un_tiret_pas_une_chaine_vide(): void
    {
        $this->assertSame('—', LibellePaiement::pour(null));
        $this->assertSame('—', LibellePaiement::pour(''));
        $this->assertSame('—', LibellePaiement::pour('   '));
    }

    /**
     * Le banc qui compte : l'export doit passer par le libellé. Sans cette
     * assertion, on pourrait remettre l'identifiant brut sans qu'aucun test ne bouge.
     */
    public function test_l_export_tableur_passe_par_le_libelle(): void
    {
        $source = (string) file_get_contents(app_path('Exports/TransactionExport.php'));

        $this->assertStringContainsString('LibellePaiement::pour(', $source);
        $this->assertStringNotContainsString(
            '$transaction->payment_method,',
            $source,
            "L'identifiant machine est réécrit tel quel dans le tableur."
        );
    }

    /**
     * Et le gabarit PDF doit utiliser la MÊME source, sinon les deux documents
     * recommenceraient à diverger — c'est la duplication qui a créé le défaut.
     */
    public function test_le_gabarit_pdf_partage_la_meme_source(): void
    {
        $source = (string) file_get_contents(
            resource_path('views/pdf/sales_report.blade.php')
        );

        $this->assertStringContainsString('LibellePaiement::pour(', $source);
        $this->assertStringNotContainsString(
            "'counter_cash'              => 'Espèces (Caisse)'",
            $source,
            'La table est revenue en ligne dans le gabarit : quatrième copie.'
        );
    }
}
