<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [ONB-12 2026-08-28] Le tampon « 100 % Halal » devient une donnée.
 *
 * Il était écrit en dur dans `KioskIdleScreenComponent.vue`. Le nouveau réglage
 * `kiosk_setup.kiosk_halal_stamp` vaut 0 par défaut : on n'affirme rien sur la
 * nourriture d'un établissement tant qu'il ne l'a pas déclaré lui-même.
 *
 * Mais un établissement DÉJÀ EN SERVICE affiche ce tampon aujourd'hui, et il est
 * vrai pour lui. Le passer à 0 le lui retirerait sans prévenir — une régression
 * visible sur un écran client, pour un défaut qui n'est pas le sien.
 *
 * Cette migration déclare donc la valeur qui correspond à la réalité actuelle :
 *   - installation qui a déjà des produits actifs → tampon déclaré (1)
 *   - installation vierge                          → rien de déclaré (0)
 *
 * Le critère est un choix assumé : la présence d'une carte est le signe le plus
 * fiable, dans ce schéma, qu'un établissement tourne déjà. Il est écrit ici plutôt
 * que deviné plus tard.
 */
return new class extends Migration
{
    private const GROUPE = 'kiosk_setup';
    private const CLE = 'kiosk_halal_stamp';

    public function up(): void
    {
        $table = config('settings.repositories.database.table', 'settings');

        if (! Schema::hasTable($table) || ! Schema::hasTable('items')) {
            return;
        }

        $dejaPose = DB::table($table)
            ->where('group', self::GROUPE)
            ->where('key', self::CLE)
            ->exists();

        if ($dejaPose) {
            return;
        }

        // Le critere : un etablissement DEJA EN SERVICE a une carte active. Le
        // filtre `status` manquait dans la premiere version alors que le docblock
        // disait « actifs » — un commentaire qui affirmait plus que le code.
        $etablissementEnService = DB::table('items')
            ->whereNull('deleted_at')
            ->where('status', \App\Enums\Status::ACTIVE)
            ->exists();

        // [corrige apres audit adverse] On passe par l'API du paquet plutot que
        // par un `insert` brut. Le paquet enveloppe les valeurs
        // (`{"$value": .., "$cast": null}`) et les desenveloppe a la lecture :
        // ecrire un scalaire nu marchait PAR ACCIDENT, en s'appuyant sur le fait
        // que le desenveloppage est sans effet sur un non-tableau. Une migration
        // ne doit pas dependre d'un detail d'implementation qu'elle ne controle
        // pas — et le premier enregistrement admin reecrivait la ligne au bon
        // format, donc deux formats coexistaient selon l'historique de l'install.
        $aDeclarer = [self::CLE => $etablissementEnService ? 1 : 0];

        // [ONB-12 2026-08-28] Meme principe pour le logo d'accueil.
        //
        // L'ecran d'accueil servait un logo EN DUR depuis `/images/kiosk-attract/`,
        // concu pour son fond orange. Le sortir vers la donnee est juste — mais le
        // remplacer par le logo GENERAL degraderait l'ecran de l'etablissement qui
        // tourne, dont le logo general est sur fond blanc.
        //
        // On DECLARE donc le visuel qu'il utilise deja, s'il existe sur le disque.
        // Une installation vierge n'herite de rien.
        $logoLivre = public_path('images/kiosk-attract/logo.webp');

        if ($etablissementEnService && is_file($logoLivre)) {
            $aDeclarer['kiosk_attract_logo'] = '/images/kiosk-attract/logo.webp';
        }

        \Settings::group(self::GROUPE)->set($aDeclarer);
    }

    public function down(): void
    {
        $table = config('settings.repositories.database.table', 'settings');

        if (! Schema::hasTable($table)) {
            return;
        }

        DB::table($table)
            ->where('group', self::GROUPE)
            ->where('key', self::CLE)
            ->delete();
    }
};
