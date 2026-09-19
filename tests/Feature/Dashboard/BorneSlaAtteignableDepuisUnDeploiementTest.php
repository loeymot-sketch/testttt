<?php

namespace Tests\Feature\Dashboard;

use Tests\TestCase;

/**
 * [ONB-10 2026-08-28 · MON PROPRE ANGLE MORT] La borne SLA n'est réglable
 * que si le fichier de configuration existe dans le dépôt.
 *
 * `AlertesSlaFenetreBorneeTest` prouve que `DashboardService` lit bien
 * `dashboard.sla_alerts_window_hours` — en posant la valeur EN MÉMOIRE avec
 * `config([...])`. C'est un périmètre trop étroit : sur un dépôt fraîchement
 * cloné, `config/dashboard.php` n'était pas suivi par git. Les deux appels
 * `config('dashboard.sla_*', <défaut>)` retombaient donc silencieusement sur
 * leur valeur de repli, et les variables d'environnement
 * `DASHBOARD_SLA_WINDOW_HOURS` / `DASHBOARD_SLA_THRESHOLD_MINUTES` n'étaient
 * lues par personne. Mon commit annonçait « la borne se disait réglable et ne
 * l'était pas » ; après correctif, elle ne l'était toujours pas.
 *
 * Ce banc-ci contrôle l'autre bout de la chaîne : le fichier est présent, il
 * expose les deux clés, et chacune est branchée sur sa variable
 * d'environnement. C'est ce qui rend le réglage réel pour un exploitant, et
 * c'est la seule partie qu'un test en mémoire ne peut pas voir.
 *
 * Trouvé par un agent adverse lancé sur mon propre travail — huitième fois dans
 * cette session qu'une sentinelle verte gardait le mauvais périmètre.
 */
class BorneSlaAtteignableDepuisUnDeploiementTest extends TestCase
{
    private const CLES = [
        'sla_alerts_window_hours'      => 'DASHBOARD_SLA_WINDOW_HOURS',
        'sla_alerts_threshold_minutes' => 'DASHBOARD_SLA_THRESHOLD_MINUTES',
    ];

    private function chemin(): string
    {
        return config_path('dashboard.php');
    }

    public function test_le_fichier_de_configuration_existe_dans_le_depot(): void
    {
        $this->assertFileExists(
            $this->chemin(),
            "config/dashboard.php est absent.\n"
            . "Sans lui, `config('dashboard.sla_alerts_window_hours', 24)` retombe sur 24 h\n"
            . "quoi que l'exploitant écrive dans son .env : le réglage n'existe pas."
        );
    }

    public function test_les_deux_cles_sont_exposees(): void
    {
        foreach (array_keys(self::CLES) as $cle) {
            $this->assertNotNull(
                config('dashboard.' . $cle),
                "La clé `dashboard.{$cle}` n'est pas définie par la configuration.\n"
                . "Le service la lit avec une valeur de repli : il ne planterait pas,\n"
                . "il ignorerait simplement le réglage — c'est le défaut d'origine."
            );
        }
    }

    public function test_chaque_cle_est_branchee_sur_sa_variable_d_environnement(): void
    {
        $source = file_get_contents($this->chemin());

        foreach (self::CLES as $cle => $variable) {
            $this->assertMatchesRegularExpression(
                "/'" . preg_quote($cle, '/') . "'\s*=>.*env\(\s*'" . preg_quote($variable, '/') . "'/",
                $source,
                "`dashboard.{$cle}` doit être alimentée par env('{$variable}').\n"
                . "Une constante écrite en dur dans le fichier de configuration est aussi\n"
                . "fixe qu'une constante écrite dans le service : l'exploitant ne peut pas\n"
                . "la changer sans modifier le code."
            );
        }
    }

    public function test_les_variables_sont_documentees_dans_env_example(): void
    {
        $exemple = file_get_contents(base_path('.env.example'));

        foreach (self::CLES as $cle => $variable) {
            $this->assertStringContainsString(
                $variable . '=',
                $exemple,
                "{$variable} manque dans .env.example.\n"
                . "Un réglage qu'on ne découvre qu'en lisant le code source n'est pas\n"
                . "un réglage offert au commerçant : c'est un réglage caché."
            );
        }
    }

    public function test_les_valeurs_par_defaut_du_fichier_valent_celles_du_service(): void
    {
        // Les replis inscrits dans DashboardService (24 h / 15 min) ne doivent pas
        // diverger de ceux du fichier : deux défauts différents pour une même borne
        // font que le comportement change selon que le fichier est présent ou non.
        $service = file_get_contents(app_path('Services/DashboardService.php'));

        $this->assertStringContainsString(
            "config('dashboard.sla_alerts_window_hours', 24)",
            $service,
            'Le repli du service et celui du fichier doivent rester la même valeur.'
        );
        $this->assertSame(24, (int) config('dashboard.sla_alerts_window_hours'));

        $this->assertStringContainsString(
            "config('dashboard.sla_alerts_threshold_minutes', 15)",
            $service,
            'Le repli du service et celui du fichier doivent rester la même valeur.'
        );
        $this->assertSame(15, (int) config('dashboard.sla_alerts_threshold_minutes'));
    }
}