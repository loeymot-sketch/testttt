<?php

namespace Tests\Feature\Onboarding;

use Tests\TestCase;

/**
 * [ONB-09 2026-08-28] Le QR de la roue n'envoie plus les clients chez un autre.
 *
 * ═══ LE DÉFAUT ═══
 *
 * `config/wheel.php` livrait `env('WHEEL_PUBLIC_URL', 'https://www.lecayenne.fr')`,
 * et aucun `WHEEL_PUBLIC_URL` n'existe dans `.env`. Le QR affiché au comptoir et
 * sur l'écran de validation composait donc, pour **tout** commerçant installant le
 * produit :
 *
 *     https://www.lecayenne.fr/roue.html?t=…
 *
 * ═══ CE QUI REND CE DÉFAUT INSTRUCTIF ═══
 *
 * Le commentaire posé **trois lignes plus haut** dans le même fichier dit :
 *
 * > « Vide = pas de QR : on préfère ne rien afficher qu'un QR qui mène nulle part. »
 *
 * L'intention était juste, et le garde-fou existait — `WheelCounterController:82`
 * et `:124` affichent « L'adresse publique de la roue n'est pas configurée ».
 *
 * Mais la valeur n'était **jamais vide**. Le garde-fou ne pouvait donc jamais se
 * déclencher, et le commerçant n'était même pas averti.
 *
 * *Un garde-fou qu'une valeur par défaut rend inatteignable est pire qu'absent :
 * il donne l'impression que le cas est couvert.*
 */
class LeQrDeLaRoueNeMenePasChezUnAutreTest extends TestCase
{
    public function test_aucune_adresse_d_un_autre_etablissement_n_est_livree_par_defaut(): void
    {
        $fichier = file_get_contents(config_path('wheel.php'));

        // On lit le FICHIER et non `config()` : un `.env` local pourrait masquer le
        // défaut et rendre ce banc vert sur une machine, rouge sur une autre. C'est
        // la valeur LIVRÉE qui compte, celle que reçoit une installation neuve.
        $this->assertMatchesRegularExpression(
            "/'public_url'\s*=>\s*rtrim\(\(string\)\s*env\('WHEEL_PUBLIC_URL',\s*''\)/",
            $fichier,
            "`wheel.public_url` livre de nouveau une adresse par défaut.\n\n"
            . "Le QR affiché au comptoir enverrait les clients de tout commerçant\n"
            . "vers le site de quelqu'un d'autre — et le garde-fou « vide = pas de\n"
            . "QR », posé trois lignes plus haut, redeviendrait inatteignable."
        );

        $this->assertStringNotContainsString(
            "env('WHEEL_PUBLIC_URL', 'https://",
            $fichier,
            "Une adresse en dur est revenue comme valeur par défaut."
        );
    }

    public function test_le_garde_fou_du_comptoir_peut_enfin_se_declencher(): void
    {
        // Il testait `$base === ''`, un état que le défaut rendait inatteignable.
        // Ce banc vérifie que la condition existe toujours ET qu'elle est désormais
        // atteignable — sans quoi on aurait retiré le défaut sans rien gagner.
        $controleur = file_get_contents(
            app_path('Http/Controllers/Admin/Wheel/WheelCounterController.php')
        );

        $this->assertStringContainsString(
            "\$base === ''",
            $controleur,
            "Le garde-fou du comptoir a disparu : plus rien n'avertit le commerçant\n"
            . "que son adresse publique n'est pas configurée."
        );

        $this->assertStringContainsString(
            'WHEEL_PUBLIC_URL',
            $controleur,
            "Le message d'avertissement ne nomme plus la variable à renseigner :\n"
            . 'le commerçant sait que ça ne marche pas, mais pas quoi faire.'
        );

        // Et la valeur par défaut doit bien être vide, sinon la condition ci-dessus
        // reste du code mort — c'est exactement l'état d'avant ce correctif.
        config()->set('wheel.public_url', rtrim((string) env('WHEEL_PUBLIC_URL', ''), '/'));

        $this->assertSame(
            '',
            (string) config('wheel.public_url'),
            "Sans `WHEEL_PUBLIC_URL` renseigné, l'adresse doit être vide — et le\n"
            . 'comptoir affiche alors la marche à suivre au lieu d\'un QR erroné.'
        );
    }
}
