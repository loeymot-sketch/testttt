<?php

namespace Tests\Feature\Hardware;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Printer;
use App\Services\Hardware\EscPosPrinterService;
use App\Services\Hardware\PrinterTransport\NullPrinterTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [ONB-10 2026-08-27] Archiver une imprimante doit vouloir dire la même chose partout.
 *
 * Trouvé en auditant l'écran Paramètres > Imprimantes (les deux imprimantes du
 * Cayenne s'y affichaient « Archivé » à tort — c'est un défaut distinct, traité par
 * ImprimanteCreeeDepuisEcranImprimeTest) : `EscPosPrinterService::openDrawer()`
 * cherchait l'imprimante de la station `receipt` SANS filtrer sur le statut, alors que les
 * trois autres chemins d'impression du produit le filtrent —
 * KitchenTicketAutoPrinter::kitchenPrinter (dont le commentaire dit même
 * « imprimante cuisine ACTIVE »), PosReceiptPrintController, et
 * PrintFiscalReceiptAndOpenDrawerOnCounterPaid. Trois contre un.
 *
 * Le scénario qui fait mal n'est pas « zéro imprimante active » — c'est le
 * remplacement de matériel, le cas le plus banal qui soit. Le commerçant change son
 * imprimante de caisse et archive l'ancienne. Ses tickets partent correctement sur
 * la nouvelle (ces chemins-là filtrent). Mais `orderBy('id')` sans filtre de statut
 * renvoie l'ANCIENNE, qui porte l'identifiant le plus petit : la commande
 * d'ouverture du tiroir continue d'être envoyée à une imprimante débranchée. Le
 * tiroir ne s'ouvre plus, sans message, au comptoir, en plein service — et rien à
 * l'écran ne relie la panne à l'archivage fait la semaine d'avant.
 *
 * Le test existant EscPosOpenDrawerTest ne créait qu'une imprimante ACTIVE : il ne
 * pouvait pas voir le défaut. C'est pour ça qu'il a survécu.
 */
class TiroirCaisseIgnoreImprimanteArchiveeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    private function imprimante(int $brancheId, string $nom, int $statut, string $hote): Printer
    {
        return Printer::query()->create([
            'branch_id'   => $brancheId,
            'name'        => $nom,
            'type'        => 'escpos_tcp',
            'host'        => $hote,
            'port'        => 9100,
            'station'     => 'receipt',
            'width_chars' => 48,
            'status'      => $statut,
            'options'     => null,
        ]);
    }

    /**
     * Le cas du remplacement de matériel. L'ancienne imprimante a l'identifiant le
     * plus petit : sans filtre de statut, c'est elle que `orderBy('id')` renvoie.
     */
    public function test_le_tiroir_vise_la_nouvelle_imprimante_pas_l_ancienne_archivee(): void
    {
        $transport = new NullPrinterTransport();
        $service = new EscPosPrinterService($transport);

        $branche = Branch::factory()->create();

        // Créée EN PREMIER : identifiant le plus petit, donc gagnante de orderBy('id').
        $ancienne = $this->imprimante($branche->id, 'Caisse (remplacée)', Status::ACTIVE, '10.0.0.8');
        $ancienne->forceFill(['status' => Status::INACTIVE])->save(); // le commerçant l'archive

        $nouvelle = $this->imprimante($branche->id, 'Caisse', Status::ACTIVE, '10.0.0.9');

        $this->assertLessThan(
            $nouvelle->id,
            $ancienne->id,
            "Le scénario n'a de sens que si l'archivée porte l'identifiant le plus petit."
        );

        $resultat = $service->openDrawer(null, (int) $branche->id);

        $this->assertTrue($resultat['success'], 'Une imprimante active existe : le tiroir doit s\'ouvrir.');

        $this->assertSame(
            $nouvelle->id,
            $resultat['printer_id'],
            "Le tiroir a été commandé sur l'imprimante ARCHIVÉE plutôt que sur celle en\n"
            . "service. Le commerçant a remplacé son matériel, ses tickets sortent bien,\n"
            . "et son tiroir ne s'ouvre plus — sans message, au comptoir."
        );

        $this->assertCount(1, $transport->sent);
        $this->assertSame(
            '10.0.0.9',
            $transport->sent[0]['config']['host'] ?? null,
            "Les octets sont partis vers l'ancienne adresse."
        );
    }

    /**
     * Aucune imprimante active : refus franc, plutôt qu'un envoi vers du matériel
     * retiré qui « réussit » sans que le tiroir bouge.
     */
    public function test_sans_imprimante_active_le_tiroir_refuse_au_lieu_d_emettre_dans_le_vide(): void
    {
        $transport = new NullPrinterTransport();
        $service = new EscPosPrinterService($transport);

        $branche = Branch::factory()->create();

        $archivee = $this->imprimante($branche->id, 'Caisse (retirée)', Status::ACTIVE, '10.0.0.8');
        $archivee->forceFill(['status' => Status::INACTIVE])->save();

        $resultat = $service->openDrawer(null, (int) $branche->id);

        $this->assertFalse(
            $resultat['success'],
            "Toutes les imprimantes sont archivées : le service doit le dire, pas émettre\n"
            . "vers une adresse qui ne répond plus et retourner un succès."
        );
        $this->assertCount(0, $transport->sent);
    }

    /**
     * Même règle quand l'appelant désigne une imprimante précise : un identifiant
     * archivé, venu d'une liste périmée à l'écran, ne doit pas passer.
     */
    public function test_un_identifiant_archive_explicite_est_refuse(): void
    {
        $transport = new NullPrinterTransport();
        $service = new EscPosPrinterService($transport);

        $branche = Branch::factory()->create();

        $archivee = $this->imprimante($branche->id, 'Caisse (retirée)', Status::ACTIVE, '10.0.0.8');
        $archivee->forceFill(['status' => Status::INACTIVE])->save();

        $resultat = $service->openDrawer((int) $archivee->id, (int) $branche->id);

        $this->assertFalse($resultat['success']);
        $this->assertCount(0, $transport->sent);
    }

    /**
     * Contrôle négatif : le filtre ne doit pas casser le cas normal. Sans cette
     * assertion, on « réparerait » les trois tests ci-dessus en refusant tout.
     */
    public function test_une_imprimante_active_seule_ouvre_toujours_le_tiroir(): void
    {
        $transport = new NullPrinterTransport();
        $service = new EscPosPrinterService($transport);

        $branche = Branch::factory()->create();
        $active = $this->imprimante($branche->id, 'Caisse', Status::ACTIVE, '127.0.0.1');

        $resultat = $service->openDrawer(null, (int) $branche->id);

        $this->assertTrue($resultat['success']);
        $this->assertSame($active->id, $resultat['printer_id']);
        $this->assertCount(1, $transport->sent);
    }
}
