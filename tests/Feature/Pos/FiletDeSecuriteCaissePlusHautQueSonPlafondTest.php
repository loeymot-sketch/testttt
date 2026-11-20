<?php

namespace Tests\Feature\Pos;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * UN FILET PLUS SERRÉ QUE CE QU'IL PROTÈGE N'EST PAS UN FILET : C'EST LE PLAFOND.
 *
 * Défaut mesuré à la ronde 3 de l'audit superviseur (2026-08-25). Les branches « caisse »,
 * « KDS » et « changement de statut » du limiteur `admin-mutation` se décrivent comme
 * « the safety net against accidental burst, not the primary cap » — et rendaient un 120
 * ÉCRIT EN DUR, insensible à toute configuration.
 *
 * Pendant ce temps, les plafonds primaires qu'elles doublent (`pos-order-create`,
 * `pos-order-update`, `kds-bump`) sont réglables et montés à 1000/min sur ce poste. Le
 * « filet » était donc huit fois plus serré que le plafond : c'est LUI qui coupait.
 *
 * Conséquence concrète : l'exploitant règle `ADMIN_MUTATION_RATE_LIMIT=1000`, croit avoir
 * desserré la caisse, et la caisse reste à 120/min par appareil. C'est ce qui a produit les
 * deux 429 mesurés sur l'afficheur client — même famille que la plainte de production du
 * 2026-08-13 (« beaucoup d'erreur trop de request »).
 *
 * 120/min est atteignable en coup de feu : un article par seconde émet déjà le calcul du
 * prix ET la poussée vers l'afficheur — 120/min à deux requêtes — avant les sondages
 * d'impression (24/min) et le suivi (12/min).
 */
class FiletDeSecuriteCaissePlusHautQueSonPlafondTest extends TestCase
{
    /** Interroge le limiteur exactement comme le fait le middleware. */
    private function plafondPour(string $chemin): int
    {
        $resolveur = RateLimiter::limiter('admin-mutation');
        $this->assertNotNull($resolveur, 'le limiteur admin-mutation a disparu');

        $requete = Request::create($chemin, 'POST');
        $requete->setUserResolver(fn () => \App\Models\User::factory()->make(['id' => 1]));

        $limite = $resolveur($requete);
        if (is_array($limite)) {
            $limite = $limite[0];
        }
        $this->assertInstanceOf(Limit::class, $limite);

        return (int) $limite->maxAttempts;
    }

    /**
     * LE DÉFAUT LIVRÉ NE BOUGE PAS : sans réglage, la caisse reste exactement à 120/min.
     *
     * @test
     */
    public function sans_reglage_le_comportement_livre_est_inchange(): void
    {
        Config::set('app.admin_mutation_rate_limit', 60); // la valeur livrée

        $this->assertSame(120, $this->plafondPour('api/admin/pos/quote'));
        $this->assertSame(120, $this->plafondPour('api/admin/kds-order/change-status/1'));
        $this->assertSame(120, $this->plafondPour('api/admin/online-order/change-status/1'));
    }

    /**
     * LE DÉFAUT CORRIGÉ : le filet suit le réglage qu'il double.
     *
     * @test
     */
    public function le_filet_remonte_avec_le_reglage_de_l_exploitant(): void
    {
        Config::set('app.admin_mutation_rate_limit', 1000);

        foreach ([
            'api/admin/pos/quote' => 'la caisse',
            'api/admin/kds-order/change-status/1' => 'le KDS',
            'api/admin/online-order/change-status/1' => 'les commandes en ligne',
        ] as $chemin => $quoi) {
            $this->assertSame(
                1000,
                $this->plafondPour($chemin),
                "RÉGRESSION : {$quoi} reste bloquée à un plafond écrit en dur alors que "
                . 'l\'exploitant a réglé 1000/min. Un filet plus serré que le plafond qu\'il '
                . 'protège devient le plafond — c\'est lui qui coupe, en plein service.'
            );
        }
    }

    /**
     * Le plancher tient : baisser le réglage CRUD ne doit pas étrangler la caisse.
     *
     * @test
     */
    public function baisser_le_reglage_crud_n_etrangle_pas_la_caisse(): void
    {
        Config::set('app.admin_mutation_rate_limit', 10);

        $this->assertSame(
            120,
            $this->plafondPour('api/admin/pos/quote'),
            'la caisse doit garder son plancher de 120/min même si le CRUD administrateur '
            . 'est serré très bas : ce sont deux usages sans rapport.'
        );
    }

    /** Le CRUD ordinaire, lui, suit le réglage sans plancher. */
    public function test_le_crud_ordinaire_suit_le_reglage(): void
    {
        Config::set('app.admin_mutation_rate_limit', 42);

        $this->assertSame(
            42,
            $this->plafondPour('api/admin/item'),
            'le CRUD administrateur ordinaire doit rester sur son réglage — le plancher de '
            . 'la caisse ne doit pas déborder sur lui.'
        );
    }
}
