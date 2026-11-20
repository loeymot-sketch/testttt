<?php

namespace Tests\Feature\Onboarding;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\PurchaseDocument;
use App\Models\PurchaseLine;
use App\Models\RawMaterial;
use App\Models\RawMaterialStock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [ONB-08 2026-08-28] Le refus de réception doit ATTEINDRE le commerçant.
 *
 * ═══ CE BANC EXISTE PARCE QUE LE MIEN ÉTAIT AU MAUVAIS PÉRIMÈTRE ═══
 *
 * `UneFactureEnKilosNeCreditePasDesGrammesTest` prouve que la conversion est juste.
 * Il appelle la méthode PRIVÉE par réflexion — donc il mesure le calcul, jamais ce
 * que le commerçant reçoit. Un audit adverse a trouvé l'écart :
 *
 *   `InvalidArgumentException` n'est ni `HttpException` ni `QueryException`.
 *   `Handler::render` la laissait filer vers `parent::render` → **HTTP 500**, et
 *   `PurchaseScanComponent.vue:347-352` affichait « Server Error » en anglais.
 *
 * Le message soigné qui nomme la matière et les deux unités n'était donc lu par
 * PERSONNE. Le garde-fou fonctionnait ; sa raison d'être, non. Un refus qu'on ne
 * comprend pas ne vaut guère mieux qu'une corruption silencieuse : dans les deux
 * cas le commerçant est bloqué sans savoir quoi corriger.
 *
 * Ce banc passe par la ROUTE — `POST /api/admin/purchasing/{document}/validate` —
 * c'est-à-dire par le bouton que le commerçant presse.
 */
class LeRefusDeReceptionEstLisibleParLeCommercantTest extends TestCase
{
    use RefreshDatabase;

    private User $karim;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        if (! Branch::query()->whereKey(1)->exists()) {
            Branch::factory()->create(['id' => 1]);
        }

        $this->karim = User::factory()->create(['branch_id' => 1]);
        $this->karim->assignRole('Admin');
        Permission::findOrCreate('items_create', 'sanctum');
        $this->karim->givePermissionTo(['items_create']);
    }

    private function documentAvecUneLigne(string $uniteFacture, string $uniteMatiere): array
    {
        $matiere = RawMaterial::create([
            'branch_id' => 1,
            'name'      => 'Poulet frais',
            'unit'      => $uniteMatiere,
            'avg_cost'  => null,
            'is_active' => true,
        ]);

        $document = PurchaseDocument::create([
            'branch_id' => 1,
            'doc_date'  => '2026-08-28',
            'source'    => PurchaseDocument::SOURCE_FACTURE,
            'status'    => PurchaseDocument::STATUS_DRAFT,
            'doc_hash'  => 'onb08-' . $uniteFacture . '-' . $uniteMatiere,
        ]);

        $ligne = PurchaseLine::create([
            'purchase_document_id' => $document->id,
            'raw_label'            => 'POULET FRAIS',
            'qty'                  => 3,
            'unit'                 => $uniteFacture,
            'unit_price'           => 7.90,
            'target_type'          => PurchaseLine::TARGET_RAW_MATERIAL,
            'target_id'            => $matiere->id,
            'status'               => PurchaseLine::STATUS_VALIDATED,
        ]);

        return [$document, $ligne, $matiere];
    }

    private function valider(PurchaseDocument $document, PurchaseLine $ligne): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->karim, 'sanctum')->postJson(
            '/api/admin/purchasing/' . $document->id . '/validate',
            ['lines' => [[
                'id'          => $ligne->id,
                'target_type' => PurchaseLine::TARGET_RAW_MATERIAL,
                'target_id'   => $ligne->target_id,
                'qty'         => 3,
                'unit_price'  => 7.90,
            ]]]
        );
    }

    public function test_une_unite_impossible_rend_un_422_en_francais_et_non_un_500(): void
    {
        // « 3 kg » vers une matière comptée en pièces : aucune conversion n'existe,
        // et en inventer une créditerait 3 pièces pour 3 kilos.
        [$document, $ligne] = $this->documentAvecUneLigne('kg', 'piece');

        $reponse = $this->valider($document, $ligne);

        $this->assertSame(
            422,
            $reponse->status(),
            "Le refus sortait en HTTP 500 : l'écran affichait « Server Error » en\n"
            . "anglais, sans nommer la ligne à corriger. Réponse reçue : "
            . mb_substr((string) $reponse->getContent(), 0, 300)
        );

        $message = (string) ($reponse->json('message') ?? '');

        $this->assertStringContainsString('Poulet frais', $message, 'Le message doit NOMMER la matière.');
        $this->assertStringContainsString('kg', $message, 'Le message doit nommer les deux unités.');
        $this->assertStringContainsString('piece', $message);
        $this->assertStringNotContainsString('Server Error', $message);
        $this->assertStringNotContainsString('Exception', $message);
    }

    public function test_le_document_reste_intact_apres_un_refus(): void
    {
        [$document, $ligne, $matiere] = $this->documentAvecUneLigne('kg', 'piece');

        $this->valider($document, $ligne);

        // L'exception traverse `DB::transaction` : rien ne doit avoir été écrit.
        // Un document à moitié réceptionné serait pire que pas de réception — le
        // commerçant ne saurait plus ce qui est entré en stock.
        $this->assertSame(
            PurchaseDocument::STATUS_DRAFT,
            $document->fresh()->status,
            'Le document ne doit pas être marqué validé après un refus.'
        );

        $this->assertSame(
            0,
            RawMaterialStock::query()->where('raw_material_id', $matiere->id)->count(),
            'Aucun stock ne doit avoir été crédité.'
        );
    }

    public function test_une_facture_ecrite_en_francais_normal_est_bien_receptionnee(): void
    {
        /*
         * LE CONTRÔLE POSITIF, et il n'est pas décoratif.
         *
         * La première version du garde-fou comparait des chaînes passées à
         * `mb_strtolower`, qui ne dépouille pas les accents : « kilo » et « pièce »
         * ne correspondaient à rien et faisaient échouer la réception ENTIÈRE. Sans
         * ce banc, on ne mesurerait que les refus — et un garde-fou qui refuse tout
         * passe tous les tests de refus.
         */
        [$document, $ligne, $matiere] = $this->documentAvecUneLigne('kilo', 'grammes');

        $reponse = $this->valider($document, $ligne);

        $this->assertSame(
            200,
            $reponse->status(),
            'Une facture en « kilo » vers une matière en « grammes » est parfaitement '
            . 'légitime : ' . mb_substr((string) $reponse->getContent(), 0, 300)
        );

        $this->assertEqualsWithDelta(
            3000.0,
            (float) RawMaterialStock::query()
                ->where('raw_material_id', $matiere->id)
                ->where('branch_id', 1)
                ->value('on_hand'),
            0.0001,
            '3 kilos font 3 000 grammes — c\'est le défaut d\'origine, facteur mille.'
        );
    }
}
