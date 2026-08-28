<?php

namespace Tests\Feature\Onboarding;

use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [ONB-12 2026-08-28] Le tampon « 100 % Halal » devient une donnée déclarée.
 *
 * ═══ LE DÉFAUT ═══
 *
 * `KioskIdleScreenComponent.vue` affichait, en dur dans son gabarit :
 *
 *     <span class="cay-stamp-num">100%</span>
 *     <span class="cay-stamp-lab">Halal</span>
 *
 * C'est une affirmation sur la nourriture servie — vérifiable, engageante, et
 * propre à chaque établissement. Tout commerçant installant le produit la portait
 * sur son écran client sans l'avoir dite, et sans aucun moyen de la retirer.
 *
 * ═══ LE POINT DÉLICAT, QUI EST L'OBJET DE CE BANC ═══
 *
 * Le réglage est éteint par défaut : on n'affirme rien que le commerçant n'ait
 * déclaré. Mais un établissement **déjà en service** affiche ce tampon aujourd'hui,
 * et il est vrai pour lui. Basculer à 0 le lui retirerait sans prévenir — une
 * régression visible sur un écran client, pour un défaut qui n'est pas le sien.
 *
 * La migration déclare donc la valeur qui correspond à la réalité actuelle plutôt
 * que d'imposer le défaut à tout le monde. Le critère retenu — la présence d'une
 * carte — est un choix assumé, écrit dans la migration, et c'est lui que ce banc
 * vérifie dans les deux sens. Sans les deux sens, on ne saurait pas si la migration
 * décide vraiment ou si elle écrit toujours la même chose.
 */
class LeTamponHalalDevientUneDonneeTest extends TestCase
{
    use RefreshDatabase;

    private const GROUPE = 'kiosk_setup';
    private const CLE = 'kiosk_halal_stamp';

    private function tableDesReglages(): string
    {
        return config('settings.repositories.database.table', 'settings');
    }

    private function rejouerLaMigration(): void
    {
        DB::table($this->tableDesReglages())
            ->where('group', self::GROUPE)
            ->where('key', self::CLE)
            ->delete();

        require base_path(
            'database/migrations/2026_08_28_120000_declare_le_tampon_halal_des_installations_existantes.php'
        );

        $migration = require base_path(
            'database/migrations/2026_08_28_120000_declare_le_tampon_halal_des_installations_existantes.php'
        );

        $migration->up();
    }

    private function valeurDeclaree(): ?int
    {
        $brut = DB::table($this->tableDesReglages())
            ->where('group', self::GROUPE)
            ->where('key', self::CLE)
            ->value('payload');

        return $brut === null ? null : (int) json_decode($brut, true);
    }

    public function test_une_installation_vierge_n_affirme_rien(): void
    {
        $this->assertSame(0, Item::query()->count(), 'On part bien d\'une base vierge.');

        $this->rejouerLaMigration();

        $this->assertSame(
            0,
            $this->valeurDeclaree(),
            "Un nouveau commerçant porterait sur son écran client une affirmation\n"
            . "sur sa nourriture qu'il n'a jamais faite."
        );
    }

    public function test_une_installation_deja_en_service_garde_son_tampon(): void
    {
        // La réalité d'aujourd'hui : un établissement qui tourne, avec une carte.
        Item::factory()->create();

        $this->rejouerLaMigration();

        $this->assertSame(
            1,
            $this->valeurDeclaree(),
            "Le tampon a été retiré à un établissement qui l'affiche aujourd'hui.\n"
            . "C'est une régression visible sur un écran client, pour un défaut qui\n"
            . "n'est pas le sien. La migration doit DÉCLARER sa réalité, pas la nier."
        );
    }

    public function test_la_migration_ne_recrit_pas_un_choix_deja_fait(): void
    {
        // Un commerçant qui a explicitement éteint le tampon ne doit pas le voir
        // revenir au prochain déploiement. C'est le piège classique d'une migration
        // de valeur par défaut : elle écrase le choix qu'elle prétend initialiser.
        Item::factory()->create();

        // `RefreshDatabase` a DÉJÀ joué la migration au démarrage du test : il faut
        // effacer sa ligne avant d'écrire le choix explicite, sinon c'est le banc
        // lui-même qui fabrique le doublon qu'il prétend surveiller.
        DB::table($this->tableDesReglages())
            ->where('group', self::GROUPE)->where('key', self::CLE)->delete();

        DB::table($this->tableDesReglages())->insert([
            'key'        => self::CLE,
            'payload'    => json_encode(0),
            'group'      => self::GROUPE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require base_path(
            'database/migrations/2026_08_28_120000_declare_le_tampon_halal_des_installations_existantes.php'
        );
        $migration->up();

        $this->assertSame(
            0,
            $this->valeurDeclaree(),
            "La migration a écrasé un choix explicite du commerçant."
        );

        $this->assertSame(
            1,
            DB::table($this->tableDesReglages())
                ->where('group', self::GROUPE)->where('key', self::CLE)->count(),
            'La migration a créé un doublon du réglage.'
        );
    }
}
