<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL DASHBOARD-CONTRÔLE 2026-09-02 · Sub 3.1 · Codex P1-E/P1-G]
 *
 * Quatre points d'entrée datés — `order-statistics`, `order-summary`, `sales-summary`,
 * `customer-states` — décidaient chacun de leur fenêtre de dates, avec trois contrats
 * différents :
 *
 *  - `orderStatistics` passait par `resolveDayBoundaryParis()`, SANS aucun garde : une
 *    période inversée ou de dix ans y était acceptée, alors que les trois autres la
 *    refusaient en 422. Le même écran renvoyait donc deux réponses contradictoires pour
 *    les mêmes paramètres.
 *  - Les quatre repliaient EN SILENCE sur la période par défaut quand une seule des deux
 *    bornes était fournie : l'opérateur croyait lire mars, l'écran affichait le mois
 *    courant, et rien ne le disait.
 *  - `Carbon::parse('2026-02-31')` ne lève pas : il roule au 3 mars. Une date impossible
 *    donnait donc un résultat, silencieusement décalé.
 *
 * Ce banc fixe UN seul contrat pour les quatre : borne isolée, date impossible, période
 * inversée et période > 366 jours → 422 avec un message français ; nominal → 200.
 */
class DashboardDateContractMatrixTest extends TestCase
{
    use RefreshDatabase;

    /** Les quatre points d'entrée qui acceptent `first_date` / `last_date`. */
    private const POINTS = [
        'order-statistics',
        'order-summary',
        'sales-summary',
        'customer-states',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    private function admin(): User
    {
        $u = User::factory()->create(['branch_id' => 0]);
        $u->assignRole('Admin');

        return $u;
    }

    private function lire(string $point, array $params = [])
    {
        return $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/dashboard/'.$point.($params ? '?'.http_build_query($params) : ''));
    }

    public function test_periode_nominale_repond_200_sur_les_quatre_points(): void
    {
        foreach (self::POINTS as $point) {
            $this->lire($point, ['first_date' => '2026-03-01', 'last_date' => '2026-03-31'])
                ->assertOk();
        }
    }

    public function test_periode_inversee_est_refusee_sur_les_quatre_points(): void
    {
        foreach (self::POINTS as $point) {
            $r = $this->lire($point, ['first_date' => '2026-03-31', 'last_date' => '2026-03-01']);
            $r->assertStatus(422);
            $this->assertStringContainsString(
                'date de fin',
                json_encode($r->json(), JSON_UNESCAPED_UNICODE),
                "{$point} : le refus doit être explicite et en français"
            );
        }
    }

    public function test_periode_de_plus_de_366_jours_est_refusee_sur_les_quatre_points(): void
    {
        foreach (self::POINTS as $point) {
            $this->lire($point, ['first_date' => '2020-01-01', 'last_date' => '2026-01-01'])
                ->assertStatus(422);
        }
    }

    /**
     * Le repli silencieux : une seule borne fournie renvoyait la période PAR DÉFAUT sans
     * le dire. L'opérateur lisait « mars » sur son écran et le mois courant dans les
     * chiffres. Une borne isolée est une demande incomplète, pas une demande par défaut.
     */
    public function test_une_borne_isolee_est_refusee_et_non_repliee_en_silence(): void
    {
        foreach (self::POINTS as $point) {
            $this->lire($point, ['first_date' => '2026-03-01'])
                ->assertStatus(422);
            $this->lire($point, ['last_date' => '2026-03-31'])
                ->assertStatus(422);
        }
    }

    /**
     * `Carbon::parse('2026-02-31')` rend le 3 mars sans lever. Une date qui n'existe pas
     * doit être refusée, pas silencieusement déplacée de trois jours.
     */
    public function test_une_date_impossible_est_refusee_sur_les_quatre_points(): void
    {
        foreach (self::POINTS as $point) {
            $this->lire($point, ['first_date' => '2026-02-31', 'last_date' => '2026-03-05'])
                ->assertStatus(422);
            $this->lire($point, ['first_date' => '2026-03-01', 'last_date' => 'hier'])
                ->assertStatus(422);
        }
    }

    /** Aucune date : chaque point garde sa période par défaut et répond 200. */
    public function test_sans_aucune_date_les_quatre_points_repondent_200(): void
    {
        foreach (self::POINTS as $point) {
            $this->lire($point)->assertOk();
        }
    }

    /*
    |---------------------------------------------------------------------------
    | [G5 · T5.3 2026-09-03] Les bords du contrat, NOMMÉS.
    |---------------------------------------------------------------------------
    |
    | Le test « plus de 366 jours » ci-dessus passe une période de SIX ANS. Il prouve
    | qu'un abus grossier est refusé ; il ne dit rien de l'endroit exact où le refus
    | commence. Un contrat dont on ne connaît pas le bord n'est pas un contrat : c'est
    | une intention.
    |
    | LA MÉTRIQUE EST LE SUJET. Le garde de `DashboardService::assertSalesDateWindow()`
    | compare `$first->diffInDays($last)`, c'est-à-dire un NOMBRE D'INTERVALLES, à 366 ;
    | le message qu'il rend parle de « 366 jours ». Or une période de dates s'exprime en
    | JOURS INCLUSIFS — du 1er au 2 janvier, il y a 2 jours, pas 1. Les deux comptages
    | diffèrent toujours d'exactement un.
    |
    | Ces bancs nomment donc la métrique à chaque cas, et pinnent le comportement RÉEL.
    | AUCUNE CONSÉQUENCE PRODUIT N'EST DÉMONTRÉE : les quatre écrans qui appellent ces
    | points n'offrent aucun préréglage dépassant l'année, et une période d'un jour de
    | trop rend un résultat juste. C'est de la précision de contrat, pas un défaut
    | utilisateur — le décalage est donc CONSIGNÉ, pas corrigé. Le jour où quelqu'un
    | voudra aligner le code sur son message, ce banc dira exactement quoi bouger.
    */

    /** 366 jours inclusifs — la limite telle que le message l’ANNONCE. Acceptée. */
    public function test_bord_366_jours_inclusifs_est_accepte(): void
    {
        foreach (self::POINTS as $point) {
            $this->lire($point, ['first_date' => '2026-01-01', 'last_date' => '2027-01-01'])
                ->assertOk("{$point} : 366 jours inclusifs (diffInDays = 365) doit passer");
        }
    }

    /**
     * 367 jours inclusifs — UN JOUR AU-DELÀ de ce que le message annonce, et pourtant
     * accepté, parce que `diffInDays` y vaut 366 et que le garde teste `> 366`.
     *
     * Ce banc ne réclame pas la correction : il empêche que l'écart devienne invisible,
     * et il empêche surtout qu'on le « corrige » par accident en croyant ne rien changer.
     */
    public function test_bord_367_jours_inclusifs_passe_encore_alors_que_le_message_annonce_366(): void
    {
        foreach (self::POINTS as $point) {
            $this->lire($point, ['first_date' => '2026-01-01', 'last_date' => '2027-01-02'])
                ->assertOk(
                    "{$point} : 367 jours inclusifs (diffInDays = 366) passe — le garde compte "
                    .'des intervalles là où son message parle de jours'
                );
        }
    }

    /** 368 jours inclusifs — la PREMIÈRE période réellement refusée. */
    public function test_bord_368_jours_inclusifs_est_refuse(): void
    {
        foreach (self::POINTS as $point) {
            $r = $this->lire($point, ['first_date' => '2026-01-01', 'last_date' => '2027-01-03']);
            $r->assertStatus(422);
            $this->assertStringContainsString(
                '366 jours',
                json_encode($r->json(), JSON_UNESCAPED_UNICODE),
                "{$point} : le refus doit nommer la limite, en français"
            );
        }
    }

    /**
     * `first_date=0&last_date=0` — « 0 » n'est pas une date, et pourtant les quatre points
     * répondent 200 avec leur période PAR DÉFAUT.
     *
     * La cause est `empty()` : `empty('0')` vaut `true` en PHP. Les deux bornes sont donc
     * jugées ABSENTES avant même d'atteindre `jourCivilParisStrict()`, qui les aurait
     * refusées. Le contrôle de date le plus strict de ce service est court-circuité par la
     * seule chaîne que PHP considère vide sans l'être.
     *
     * Là encore : aucune conséquence produit démontrée. Le sélecteur de dates de l'écran
     * n'émet jamais « 0 » (cf. `tests/js/dashboardDateEnvoyeeEnJourCivil.spec.js`), et le
     * repli sur la période par défaut rend un résultat juste, simplement pas celui qu'un
     * appelant direct de l'API croirait demander. On NOMME, on ne corrige pas : durcir
     * `empty()` en `=== null || === ''` changerait le contrat des quatre points d'un coup,
     * pour un appelant qui n'existe pas.
     */
    public function test_bord_zero_est_traite_comme_une_absence_de_date_et_non_comme_une_date_invalide(): void
    {
        foreach (self::POINTS as $point) {
            $this->lire($point, ['first_date' => '0', 'last_date' => '0'])
                ->assertOk("{$point} : '0' passe par empty() et retombe sur la période par défaut");
        }
    }

    /**
     * Le corollaire, qui lui est cohérent : « 0 » sur UNE seule borne redevient une borne
     * isolée — puisque `empty('0')` la fait disparaître — et le refus 422 s'applique.
     * Le contrat n'est donc pas incohérent, il est décalé d'une conversion.
     */
    public function test_bord_zero_sur_une_seule_borne_est_refuse_comme_une_borne_isolee(): void
    {
        foreach (self::POINTS as $point) {
            $this->lire($point, ['first_date' => '0', 'last_date' => '2026-03-31'])
                ->assertStatus(422);
        }
    }

    /**
     * Le bord d'en bas : une période d'UN SEUL jour inclusif (mêmes bornes) est valide.
     * `first > last` est refusé, `first == last` ne doit pas l'être — c'est la lecture
     * « aujourd'hui », le préréglage le plus utilisé de l'écran.
     */
    public function test_bord_bas_un_seul_jour_inclusif_est_accepte(): void
    {
        foreach (self::POINTS as $point) {
            $this->lire($point, ['first_date' => '2026-03-15', 'last_date' => '2026-03-15'])
                ->assertOk("{$point} : 1 jour inclusif (diffInDays = 0) doit passer");
        }
    }
}
