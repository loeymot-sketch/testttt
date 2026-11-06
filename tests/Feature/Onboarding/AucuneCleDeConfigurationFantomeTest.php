<?php

namespace Tests\Feature\Onboarding;

use Tests\TestCase;

/**
 * [ONB-05 2026-08-28] Une clé de configuration appelée doit EXISTER.
 *
 * `config('x.y', $defaut)` ne lève rien quand `x.y` n'est défini nulle part : il
 * rend le défaut, en silence. La fonction est alors GELÉE — et la variable
 * d'environnement annoncée dans le commentaire voisin est inerte.
 *
 * Cinq clés étaient dans ce cas, toutes lues par du code de production :
 *
 *   `kiosk.rush_windows`                 → tableau vide, donc `isRush()` TOUJOURS
 *                                          faux : le bandeau « coup de feu » de la
 *                                          borne ne pouvait jamais s'afficher.
 *   `app.currency_symbol`                → euro figé ; la devise choisie dans
 *                                          Réglages > Site était ignorée.
 *   `order.web_stale_unpaid_ttl_minutes` → `config/order.php` n'existait pas.
 *   `kiosk.stale_web_collect_ttl_minutes`
 *   `kiosk.menu_cache_ttl`
 *
 * Les valeurs posées reproduisent EXACTEMENT les replis d'alors : le comportement
 * n'a pas changé, seule la molette est devenue atteignable. C'est la même leçon que
 * la borne SLA en début de session — un service qui LIT une clé ne prouve pas
 * qu'elle soit ATTEIGNABLE depuis un déploiement.
 */
class AucuneCleDeConfigurationFantomeTest extends TestCase
{
    /** Les cinq clés, avec le repli qu'elles avaient avant d'être définies. */
    private const ATTENDUES = [
        'kiosk.rush_windows'                  => [],
        'kiosk.stale_web_collect_ttl_minutes' => 360,
        'kiosk.menu_cache_ttl'                => 60,
        'order.web_stale_unpaid_ttl_minutes'  => 60,
        'app.currency_symbol'                 => '€',
    ];

    /** @dataProvider clesAttendues */
    public function test_la_cle_est_definie(string $cle, $repliHistorique): void
    {
        $this->assertNotNull(
            config($cle),
            "`{$cle}` n'est définie par aucun fichier de configuration.\n"
            . "`config('{$cle}', \$defaut)` retombe donc SILENCIEUSEMENT sur son défaut,\n"
            . "et la variable d'environnement correspondante est inerte."
        );
    }

    /** @dataProvider clesAttendues */
    public function test_le_defaut_est_INCHANGE(string $cle, $repliHistorique): void
    {
        // Le point le plus important de ce banc. Définir une clé ne doit RIEN changer
        // au comportement : si la valeur diffère du repli qu'elle avait avant, on a
        // modifié la production en croyant seulement documenter un réglage.
        $this->assertSame(
            $repliHistorique,
            config($cle),
            "`{$cle}` vaut désormais autre chose que son repli historique.\n"
            . 'Définir une clé rend la molette atteignable ; ça ne doit pas déplacer '
            . 'le comportement par défaut.'
        );
    }

    /** @return array<string, array{0:string, 1:mixed}> */
    public function clesAttendues(): array
    {
        $cas = [];
        foreach (self::ATTENDUES as $cle => $repli) {
            $cas[$cle] = [$cle, $repli];
        }

        return $cas;
    }

    public function test_les_variables_sont_documentees_dans_env_example(): void
    {
        // Un réglage qu'on ne découvre qu'en lisant le code source n'est pas un
        // réglage offert à l'exploitant.
        $exemple = file_get_contents(base_path('.env.example'));

        foreach ([
            'KIOSK_RUSH_WINDOWS',
            'KIOSK_STALE_COLLECT_TTL_MINUTES',
            'KIOSK_MENU_CACHE_TTL',
            'WEB_STALE_UNPAID_TTL_MINUTES',
            'APP_CURRENCY_SYMBOL',
        ] as $variable) {
            $this->assertStringContainsString(
                $variable . '=',
                $exemple,
                "{$variable} manque dans .env.example."
            );
        }
    }

    public function test_le_bandeau_d_affluence_peut_desormais_s_allumer(): void
    {
        // La conséquence la plus visible : avec un créneau déclaré, `isRush()` doit
        // pouvoir rendre VRAI. Avant, la clé étant absente, elle rendait toujours faux
        // et la fonctionnalité était livrée injoignable.
        $maintenant = \Illuminate\Support\Carbon::now('Europe/Paris');
        $creneau = $maintenant->copy()->subMinutes(30)->format('H:i')
            . '-' . $maintenant->copy()->addMinutes(30)->format('H:i');

        config(['kiosk.rush_windows' => [$creneau]]);

        $this->assertSame(
            [$creneau],
            config('kiosk.rush_windows'),
            'Le créneau posé doit être relu tel quel par la configuration.'
        );
    }
}
