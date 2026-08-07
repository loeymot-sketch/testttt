<?php

namespace Tests\Feature\Promo;

use App\Enums\DiscountType;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\PromoFlyer;
use App\Models\Scopes\BranchScope;
use App\Services\Promo\PromoCodeGenerator;
use App\Services\Promo\PromoFlyerEscPosRenderer;
use App\Services\Promo\PromoFlyerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * [FLYER PROMO UBER 2026-08-07] Le ticket promotionnel nominatif.
 *
 * Besoin owner : les plateformes de livraison prélèvent 30-35 %. On glisse
 * dans le sac un ticket au prénom du client, portant un code de réduction à
 * USAGE UNIQUE, pour le ramener commander en direct.
 *
 * Les points réellement risqués, et donc testés ici :
 *   - l'unicité du code entre homonymes (« il y a plein de Camille ») ;
 *   - la lisibilité du code sur papier thermique (pas de 0/O, 1/I/L) ;
 *   - l'usage unique effectif ;
 *   - la non-duplication de l'impression quand deux écrans caisse sont ouverts ;
 *   - la présence réelle du QR ET de l'URL en clair dans les octets envoyés.
 */
class PromoFlyerTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private PromoFlyerService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->service = app(PromoFlyerService::class);
    }

    private function createFlyer(string $name = 'Camille'): PromoFlyer
    {
        return $this->service->create($name, (int) $this->branch->id, null, 'device-test');
    }

    /**
     * L'exigence owner formulée mot pour mot : deux clients du même prénom ne
     * doivent jamais repartir avec le même code.
     */
    /** @test */
    public function test_two_customers_with_the_same_first_name_get_different_codes(): void
    {
        $a = $this->createFlyer('Camille');
        $b = $this->createFlyer('Camille');

        $this->assertNotSame($a->code, $b->code, 'Deux « Camille » ont reçu le même code.');
        $this->assertStringStartsWith('CAMILLE-', $a->code);
        $this->assertStringStartsWith('CAMILLE-', $b->code);
    }

    /**
     * Le code est lu sur un ticket thermique puis retapé sur un téléphone :
     * chaque caractère ambigu est un client perdu au moment de payer.
     */
    /** @test */
    public function test_code_avoids_ambiguous_characters(): void
    {
        $generator = app(PromoCodeGenerator::class);

        for ($i = 0; $i < 60; $i++) {
            $suffix = substr($generator->generate('Test'), strlen('TEST-'));

            $this->assertSame(
                4,
                strlen($suffix),
                'Le suffixe doit faire 4 caractères.'
            );
            $this->assertDoesNotMatchRegularExpression(
                '/[01OILUV]/',
                $suffix,
                "Caractère ambigu dans le suffixe « {$suffix} » — illisible sur papier thermique."
            );
        }
    }

    /**
     * Les accents ne passent pas l'encodage CP858 de l'imprimante : un code
     * accentué sortirait avec des « ? » sur le papier.
     */
    /** @test */
    public function test_accented_and_non_latin_names_produce_printable_codes(): void
    {
        $generator = app(PromoCodeGenerator::class);

        $this->assertStringStartsWith('CHLOE-', $generator->generate('Chloé'));
        $this->assertStringStartsWith('JEANLUC-', $generator->generate('Jean-Luc'));

        // Un prénom entièrement non-latin ne doit pas produire un code amputé
        // commençant par un tiret.
        $code = $generator->generate('علي');
        $this->assertMatchesRegularExpression('/^[A-Z0-9]+-[A-Z0-9]{4}$/', $code);
    }

    /**
     * Le coupon créé doit être verrouillé : une seule utilisation, sur le site
     * uniquement, avec une date de fin.
     */
    /** @test */
    public function test_created_coupon_is_single_use_web_only_and_expires(): void
    {
        $flyer = $this->createFlyer('Camille');

        $coupon = Coupon::withoutGlobalScopes()->find($flyer->coupon_id);

        $this->assertNotNull($coupon);
        $this->assertSame($flyer->code, $coupon->code);
        $this->assertSame((int) DiscountType::PERCENTAGE, (int) $coupon->discount_type);
        $this->assertEquals(10.0, (float) $coupon->discount);
        $this->assertSame(1, (int) $coupon->max_uses_global, 'Le code doit être à usage unique.');
        $this->assertSame(1, (int) $coupon->limit_per_user);
        $this->assertSame(['web'], $coupon->surfaces, 'Le code ne doit valoir que sur le site.');
        $this->assertNotNull($coupon->end_date, 'Un code sans échéance est une dette ouverte.');
        $this->assertTrue($coupon->end_date->isFuture());
    }

    /**
     * La base doit REFUSER un doublon de code, pas seulement le code applicatif :
     * deux appareils peuvent créer au même instant.
     */
    /** @test */
    public function test_database_refuses_a_duplicate_code(): void
    {
        $flyer = $this->createFlyer('Camille');

        $this->expectException(\Illuminate\Database\QueryException::class);

        PromoFlyer::withoutGlobalScope(BranchScope::class)->create([
            'branch_id'     => $this->branch->id,
            'customer_name' => 'Autre',
            'code'          => $flyer->code,
            'status'        => PromoFlyer::STATUS_PENDING,
        ]);
    }

    /**
     * Deux écrans caisse ouverts ne doivent pas sortir le même ticket deux fois.
     */
    /** @test */
    public function test_a_pending_flyer_is_claimed_by_only_one_device(): void
    {
        $this->createFlyer('Camille');

        $first  = $this->service->claimPending((int) $this->branch->id, 'ecran-1');
        $second = $this->service->claimPending((int) $this->branch->id, 'ecran-2');

        $this->assertCount(1, $first, 'Le premier écran doit obtenir le ticket.');
        $this->assertCount(0, $second, 'Le second écran ne doit RIEN obtenir — sinon double impression.');
    }

    /**
     * Une impression ratée doit revenir dans la file (papier épuisé), mais pas
     * indéfiniment.
     */
    /** @test */
    public function test_failed_print_returns_to_queue_then_gives_up(): void
    {
        $flyer = $this->createFlyer('Camille');

        for ($i = 1; $i < PromoFlyer::MAX_ATTEMPTS; $i++) {
            $claimed = $this->service->claimPending((int) $this->branch->id, 'ecran-1');
            $this->assertCount(1, $claimed, "Tentative {$i} : le ticket doit être re-proposé.");
            $this->service->acknowledge($claimed[0], false, 'Imprimante hors ligne');
        }

        // Dernière tentative : le plafond est atteint, on abandonne visiblement.
        $claimed = $this->service->claimPending((int) $this->branch->id, 'ecran-1');
        $this->assertCount(1, $claimed);
        $this->service->acknowledge($claimed[0], false, 'Imprimante hors ligne');

        $this->assertSame(PromoFlyer::STATUS_FAILED, $flyer->fresh()->status);
        $this->assertCount(
            0,
            $this->service->claimPending((int) $this->branch->id, 'ecran-1'),
            'Un ticket épuisé ne doit plus faire boucler la caisse.'
        );
    }

    /** @test */
    public function test_successful_print_leaves_the_queue(): void
    {
        $flyer = $this->createFlyer('Camille');

        $claimed = $this->service->claimPending((int) $this->branch->id, 'ecran-1');
        $this->service->acknowledge($claimed[0], true);

        $this->assertSame(PromoFlyer::STATUS_PRINTED, $flyer->fresh()->status);
        $this->assertNotNull($flyer->fresh()->printed_at);
        $this->assertCount(0, $this->service->claimPending((int) $this->branch->id, 'ecran-1'));
    }

    /**
     * Le ticket imprimé doit contenir ce qui fait sa raison d'être.
     */
    /** @test */
    public function test_printed_ticket_contains_name_code_qr_and_plain_url(): void
    {
        $flyer = $this->createFlyer('Camille');
        $bytes = $this->service->renderBytes($flyer);

        $this->assertStringContainsString('Camille', $bytes, 'Le prénom rend le ticket personnel.');
        $this->assertStringContainsString($flyer->code, $bytes);
        $this->assertStringContainsString('-10%', $bytes);

        // QR natif ESC/POS : GS ( k … fonction 181 (impression du symbole).
        $this->assertStringContainsString("\x1D(k", $bytes, 'Aucune commande QR dans les octets.');
        $this->assertStringContainsString("\x31\x51\x30", $bytes, 'Le symbole QR n\'est jamais imprimé.');

        // L'URL en clair est le filet de sécurité : toutes les thermiques ne
        // savent pas dessiner un QR, et elles échouent EN SILENCE.
        $this->assertStringContainsString('lecayenne.fr', $bytes, 'URL en clair absente — ticket inutilisable si le QR ne sort pas.');

        // Le ticket doit se couper, sinon il reste accroché à la commande suivante.
        $this->assertStringContainsString("\x1DV", $bytes);
    }

    /**
     * Le QR doit porter le code, sinon le client scanne puis doit le retaper —
     * exactement la friction qu'on cherche à supprimer.
     */
    /** @test */
    public function test_qr_url_carries_the_code(): void
    {
        $flyer = $this->createFlyer('Camille');

        $qrUrl = $flyer->rendered_payload['qr_url'] ?? '';

        $this->assertStringContainsString('promo=' . $flyer->code, $qrUrl);
    }

    /**
     * Si l'exploitant change son message demain, on doit toujours savoir ce qui
     * est réellement parti chez ce client-là.
     */
    /** @test */
    public function test_ticket_text_is_frozen_at_creation(): void
    {
        $flyer = $this->createFlyer('Camille');
        $original = $flyer->rendered_payload['intro'];

        \Smartisan\Settings\Facades\Settings::group(PromoFlyerService::SETTINGS_GROUP)
            ->set(['intro' => 'Un tout autre message']);

        $this->assertSame($original, $flyer->fresh()->rendered_payload['intro']);
    }

    /**
     * Le rendu ne doit pas exploser sur un instantané incomplet (ticket ancien,
     * réglage supprimé) — un ticket dégradé vaut mieux qu'une erreur 500 en
     * plein service.
     */
    /** @test */
    public function test_renderer_survives_a_partial_payload(): void
    {
        $bytes = app(PromoFlyerEscPosRenderer::class)->render(['code' => 'X-1234']);

        $this->assertStringContainsString('X-1234', $bytes);
    }

    /**
     * L'identité d'appareil posée pour le multi-terminaux est réutilisée ici :
     * chaque écran réclame ses impressions séparément.
     */
    /** @test */
    public function test_device_id_is_read_from_the_multi_device_header(): void
    {
        $request = Request::create('/', 'POST');
        $request->headers->set('X-Device-Id', 'caisse-comptoir-01');

        $this->assertSame('caisse-comptoir-01', $this->service->deviceIdFrom($request));
        $this->assertSame('inconnu', $this->service->deviceIdFrom(Request::create('/', 'POST')));
    }
}
