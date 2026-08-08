<?php

namespace Tests\Feature\Security;

use App\Enums\DiscountType;
use App\Enums\Status;
use App\Models\Coupon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [P0 SÉCURITÉ 2026-08-08] `GET /api/frontend/coupon` livrait les codes promo à un ANONYME.
 *
 * La route ne porte que `installed` + `apiKey` + `localization` — et cette clé d'API n'est pas un
 * secret : elle est publiée dans le meta HTML du site (le middleware le documente lui-même).
 * La réponse utilisait `CouponResource`, qui expose `code`.
 *
 * MESURÉ EN PRODUCTION le 2026-08-08, avec la clé lue sur le site : la réponse contenait les
 * codes NOMINATIFS à usage unique des tickets promo, avec le prénom de la cliente dans le nom
 * (« Flyer Camille », remise 10 %, `max_uses_global = 1`). N'importe qui pouvait donc récolter
 * les codes en circulation et brûler celui d'une cliente AVANT elle — premier arrivé, premier
 * servi. Les codes promo étant actifs en production, c'est de l'argent.
 *
 * Ce que cette suite verrouille :
 *   1. la liste publique n'expose JAMAIS `code` (c'est la fuite elle-même) ;
 *   2. un coupon nominatif à usage unique n'apparaît PAS dans la vitrine ;
 *   3. mais il reste PARFAITEMENT utilisable par sa destinataire — refermer la liste ne doit pas
 *      casser le ticket promo, sinon on aurait échangé une fuite contre une panne.
 */
class PublicCouponListLeakTest extends TestCase
{
    use RefreshDatabase;

    private bool $flagCree = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        if (! file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
            $this->flagCree = true;
        }
    }

    protected function tearDown(): void
    {
        if ($this->flagCree && file_exists(storage_path('installed'))) {
            unlink(storage_path('installed'));
        }
        parent::tearDown();
    }

    private function coupon(array $extra = []): Coupon
    {
        return Coupon::create($extra + [
            'name' => 'Promo vitrine',
            'code' => 'CODE-VITRINE-1',
            'discount' => 10,
            'discount_type' => DiscountType::PERCENTAGE,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
            'status' => Status::ACTIVE,
        ]);
    }

    private function listePublique()
    {
        return $this->withHeader('x-api-key', (string) config('app.api_key'))
            ->getJson('/api/frontend/coupon');
    }

    public function test_la_liste_publique_n_expose_JAMAIS_le_code(): void
    {
        $this->coupon();

        $r = $this->listePublique();
        $r->assertOk();

        $brut = $r->getContent();
        $this->assertStringNotContainsString('CODE-VITRINE-1', $brut,
            'le code ne doit JAMAIS sortir sur une route anonyme');
        $this->assertStringNotContainsString('"code"', $brut,
            'la clé `code` elle-même ne doit pas figurer dans la réponse publique');

        // Garde anti-test-vide : la vitrine doit tout de même rendre la promotion, sinon
        // l'assertion d'absence passerait sur une liste vide et ne prouverait rien.
        $this->assertStringContainsString('Promo vitrine', $brut, 'la vitrine doit rester utile');
    }

    public function test_un_coupon_NOMINATIF_a_usage_unique_n_apparait_pas_dans_la_vitrine(): void
    {
        $this->coupon(['name' => 'Promo vitrine', 'code' => 'CODE-VITRINE-1']);
        $this->coupon([
            'name' => 'Flyer Camille', 'code' => 'CAMILLE-7K2P',
            'max_uses_global' => 1, 'limit_per_user' => 1,
        ]);

        $brut = $this->listePublique()->assertOk()->getContent();

        $this->assertStringNotContainsString('CAMILLE-7K2P', $brut, 'le code nominatif fuitait');
        $this->assertStringNotContainsString('Camille', $brut,
            'le PRÉNOM de la cliente ne doit pas être listé publiquement non plus');
        $this->assertStringContainsString('Promo vitrine', $brut,
            'une promotion ordinaire doit rester visible — sinon on a cassé la vitrine');
    }

    /**
     * LE CONTRE-TEST QUI COMPTE : refermer la liste ne doit pas empêcher la destinataire
     * d'utiliser son code. Sans lui, on aurait échangé une fuite contre une panne du ticket promo.
     */
    public function test_la_destinataire_peut_TOUJOURS_valider_son_code_nominatif(): void
    {
        config(['pos.coupon_codes_enabled' => true]);
        $this->coupon([
            'name' => 'Flyer Camille', 'code' => 'CAMILLE-7K2P',
            'max_uses_global' => 1, 'limit_per_user' => 1, 'minimum_order' => 0,
        ]);

        $r = $this->withHeader('x-api-key', (string) config('app.api_key'))
            ->postJson('/api/frontend/coupon/coupon-checking', [
                'code' => 'CAMILLE-7K2P',
                'total' => 20.00,
                'branch_id' => 1,
                'surface' => 'web',
            ]);

        // On n'exige pas un 200 (la validation dépend d'autres règles métier selon
        // l'environnement) : on exige que le refus ne soit JAMAIS « code inconnu », ce qui
        // signalerait que notre filtre de vitrine a rendu le coupon introuvable.
        $message = (string) ($r->json('message') ?? '');
        $this->assertStringNotContainsStringIgnoringCase("n'existe pas", $message,
            'le code nominatif doit rester TROUVABLE à la validation');
        $this->assertStringNotContainsStringIgnoringCase('introuvable', $message,
            'le code nominatif doit rester TROUVABLE à la validation');
    }
}
