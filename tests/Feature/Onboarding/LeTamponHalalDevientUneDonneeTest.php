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

        if ($brut === null) {
            return null;
        }

        $decode = json_decode($brut, true);

        // [corrige apres audit adverse] Le paquet enveloppe les valeurs :
        // `{"$value": 0, "$cast": null}`. Un `(int)` direct sur ce tableau rend
        // **1**, parce qu'un tableau non vide caste a 1. Mon lecteur annoncait
        // donc « tampon declare » pour un commercant qui l'avait ETEINT — le banc
        // aurait valide l'inverse de ce qu'il pretendait mesurer.
        if (is_array($decode)) {
            $decode = $decode['$value'] ?? reset($decode);
        }

        return (int) $decode;
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

        // On ecrit par l'API du paquet, comme le fait l'ecran d'administration :
        // inserer une ligne a la main figeait un format que l'application ne
        // produit jamais, et le banc verrouillait alors le mauvais format.
        \Settings::group(self::GROUPE)->set([self::CLE => 0]);

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

    public function test_une_installation_en_service_garde_aussi_son_logo_d_accueil(): void
    {
        // MÊME PRINCIPE QUE LE TAMPON, et né de la même erreur.
        //
        // L'audit visuel après correction a montré que remplacer le logo EN DUR par
        // le logo GÉNÉRAL dégradait l'écran de l'établissement en service : son logo
        // général est sur fond blanc, et l'accueil borne est orange plein cadre.
        // J'avais réglé un problème de marque pour les futurs commerçants et abîmé
        // l'écran de l'actuel.
        //
        // La migration déclare donc le visuel qu'il utilise DÉJÀ.
        Item::factory()->create(['status' => \App\Enums\Status::ACTIVE]);

        $this->rejouerLaMigration();

        $declare = \Settings::group(self::GROUPE)->get('kiosk_attract_logo');

        if (is_file(public_path('images/kiosk-attract/logo.webp'))) {
            $this->assertSame(
                '/images/kiosk-attract/logo.webp',
                $declare,
                "L'établissement en service perd son logo d'accueil au profit d'un\n"
                . 'logo générique mal adapté au fond de la borne.'
            );
        } else {
            $this->assertNull(
                $declare,
                "Le visuel n'existe pas sur le disque : rien ne doit être déclaré,\n"
                . 'sinon la borne pointerait vers une image absente.'
            );
        }
    }

    public function test_une_installation_vierge_n_herite_d_aucun_logo(): void
    {
        $this->assertSame(0, Item::query()->count());

        $this->rejouerLaMigration();

        $this->assertNull(
            \Settings::group(self::GROUPE)->get('kiosk_attract_logo'),
            "Un nouveau commerçant hérite du logo d'un autre établissement sur son\n"
            . "écran client — c'est précisément ce que ce chantier corrige."
        );
    }
}
