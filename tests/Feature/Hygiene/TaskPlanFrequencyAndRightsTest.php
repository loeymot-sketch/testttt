<?php

namespace Tests\Feature\Hygiene;

use App\Models\Branch;
use App\Models\HygieneTaskCompletion;
use App\Models\HygieneTaskTemplate;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [AUDIT E2E 2026-11-18] Plan de nettoyage :
 *  - P0 : changer la fréquence d'une fiche qui a un passé ne réécrit JAMAIS le dossier (la fiche est clôturée, une nouvelle prend le relais) ;
 *  - P0 droits : la structure du plan est réservée au responsable (`settings`, comme Équipe et Alertes) ; valider et annuler restent ouverts.
 */
class TaskPlanFrequencyAndRightsTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/admin/hygiene/tasks';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Branch::factory()->create(['id' => 1]);
        Branch::factory()->create(['id' => 2]);
        Carbon::setTestNow(Carbon::parse('2026-11-19 10:00:00', config('app.timezone'))); // un JEUDI
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cashier(): User
    {
        $u = User::factory()->create(['branch_id' => 1, 'name' => 'Caissier']);
        $u->givePermissionTo('pos-orders');
        $u->assignRole('POS Operator');
        Sanctum::actingAs($u, ['*']);

        return $u;
    }

    private function manager(): User
    {
        $u = User::factory()->create(['branch_id' => 1, 'name' => 'Responsable']);
        $u->givePermissionTo(['pos-orders', 'settings']);
        $u->assignRole('Branch Manager');
        Sanctum::actingAs($u, ['*']);

        return $u;
    }

    private function hood(string $created = '2026-11-01 08:00:00'): HygieneTaskTemplate
    {
        $t = new HygieneTaskTemplate([
            'catalog_key' => 'hotte', 'name' => 'Hotte et filtres', 'zone' => 'Cuisine', 'how_to' => 'Dégraissant, rinçage',
            'checklist' => [['key' => 'k1', 'label' => 'Filtres'], ['key' => 'k2', 'label' => 'Hotte']],
            'frequency' => 'daily', 'due_time' => '23:00', 'sort_order' => 70, 'is_active' => true,
        ]);
        $t->branch_id = 1;
        $t->created_at = Carbon::parse($created, config('app.timezone'));
        $t->save();

        return $t;
    }

    private function dossier(string $from = '2026-11-01', string $to = '2026-11-18'): array
    {
        return $this->getJson("/api/admin/hygiene/report/summary?from=$from&to=$to")->assertOk()->json('data.tasks');
    }

    // ───────── P0 : la fréquence ne réécrit pas le passé ─────────

    public function test_passer_une_fiche_quotidienne_en_mensuelle_garde_les_17_jours_non_faits_du_dossier(): void
    {
        $this->manager();
        $t = $this->hood(); // créée le 01/11 : du 02 au 18/11 = 17 jours attendus, aucun fait (le 01 = jour de création, jamais « manqué »)
        $missingBefore = $this->getJson(self::BASE.'/templates')->json('meta.recommended.missing');
        $before = $this->dossier();
        $this->assertSame(17, $before['expected']);
        $this->assertSame(17, $before['missed']);

        $r = $this->putJson(self::BASE."/templates/{$t->id}", ['frequency' => 'monthly', 'month_day' => 1])->assertOk();

        // le dossier n'a pas bougé : 0 sur 17 · 17 non faites (sans correctif : « 0 sur 0 »)
        $after = $this->dossier();
        $this->assertSame(17, $after['expected'], 'changer la fréquence ne réécrit pas les jours passés');
        $this->assertSame(17, $after['missed']);
        $this->assertSame(0, $after['done']);

        // l'ancienne fiche est clôturée (suppression douce), une nouvelle porte la nouvelle fréquence et tout le reste
        $newId = $r->json('data.id');
        $this->assertNotSame($t->id, $newId);
        $r->assertJsonPath('meta.replaced', true)->assertJsonPath('meta.previous_id', $t->id)
            ->assertJsonPath('data.frequency', 'monthly')->assertJsonPath('data.month_day', 1)->assertJsonPath('data.weekday', null)
            ->assertJsonPath('data.name', 'Hotte et filtres')->assertJsonPath('data.catalog_key', 'hotte')->assertJsonPath('data.sort_order', 70)
            ->assertJsonPath('data.zone', 'Cuisine')->assertJsonPath('data.how_to', 'Dégraissant, rinçage')->assertJsonPath('data.due_time', '23:00')
            ->assertJsonPath('data.checklist.1.label', 'Hotte');
        $this->assertNotNull(HygieneTaskTemplate::withTrashed()->find($t->id)->deleted_at);
        $this->assertSame([$newId], HygieneTaskTemplate::query()->pluck('id')->all(), 'une seule fiche vivante');
        $this->assertSame(['daily'], HygieneTaskTemplate::onlyTrashed()->pluck('frequency')->all(), 'l’ancienne fiche garde sa fréquence : c’est elle qui juge le passé');

        // le lendemain, le jour du changement (fin de l'ancienne, création de la nouvelle) n'invente aucun « non fait »
        Carbon::setTestNow(Carbon::parse('2026-11-20 09:00:00', config('app.timezone')));
        $next = $this->dossier('2026-11-01', '2026-11-19');
        $this->assertSame(17, $next['missed']);
        $this->assertSame(17, $next['expected']);
        // et la liste du plan ne montre que la nouvelle fiche
        $this->assertSame(['Hotte et filtres'], collect($this->getJson(self::BASE.'/templates')->json('data'))->pluck('name')->all());
        // le plan de départ ne la croit pas manquante (catalog_key et nom repris)
        $this->assertSame($missingBefore, $this->getJson(self::BASE.'/templates')->json('meta.recommended.missing'));
    }

    public function test_changer_le_jour_d_une_fiche_hebdomadaire_est_aussi_un_changement_de_frequence(): void
    {
        $this->manager();
        $t = $this->hood();
        $this->putJson(self::BASE."/templates/{$t->id}", ['frequency' => 'weekly', 'weekday' => 1])->assertOk(); // quotidienne → lundi : nouvelle fiche
        $weekly = HygieneTaskTemplate::query()->firstOrFail();
        $this->assertNotSame($t->id, $weekly->id);
        // fiche créée AUJOURD'HUI : pas de passé, le jour se change en place (aucune fiche fantôme)
        $this->putJson(self::BASE."/templates/{$weekly->id}", ['weekday' => 3])->assertOk()->assertJsonPath('data.id', $weekly->id)->assertJsonPath('data.weekday', 3);
        $this->assertSame(2, HygieneTaskTemplate::withTrashed()->count());
    }

    public function test_une_modification_sans_changement_de_frequence_reste_une_simple_mise_a_jour(): void
    {
        $this->manager();
        $t = $this->hood();
        $this->putJson(self::BASE."/templates/{$t->id}", ['name' => 'Hotte, filtres et gaines', 'frequency' => 'daily', 'weekday' => null, 'month_day' => null, 'due_time' => '22:00'])
            ->assertOk()->assertJsonPath('data.id', $t->id)->assertJsonPath('data.name', 'Hotte, filtres et gaines');
        $this->assertSame(1, HygieneTaskTemplate::withTrashed()->count());
        $this->assertNull($t->fresh()->deleted_at);
    }

    public function test_le_nom_de_la_fiche_remplacee_ne_bloque_pas_et_reste_unique_ensuite(): void
    {
        $this->manager();
        $t = $this->hood();
        // même nom envoyé avec la nouvelle fréquence : jamais « Cette fiche existe déjà »
        $this->putJson(self::BASE."/templates/{$t->id}", ['name' => 'Hotte et filtres', 'frequency' => 'monthly', 'month_day' => 15])->assertOk();
        // la règle « nom en double refusé » vaut toujours entre fiches VIVANTES
        $this->postJson(self::BASE.'/templates', ['name' => 'hotte et filtres', 'checklist' => ['a'], 'frequency' => 'daily'])->assertStatus(422);
    }

    /** [tour 3 · P1] Une fiche déjà traitée aujourd'hui ne change pas de fréquence le jour même (validation invisible, double compte). */
    public function test_une_fiche_deja_traitee_aujourd_hui_ne_change_pas_de_frequence_le_jour_meme(): void
    {
        $this->manager();
        $t = $this->hood();
        $id = $this->postJson(self::BASE.'/completions', ['template_id' => $t->id, 'all' => true])->assertCreated()->json('data.id');

        foreach ([['frequency' => 'weekly', 'weekday' => 4], ['frequency' => 'monthly', 'month_day' => 28]] as $change) {
            $this->putJson(self::BASE."/templates/{$t->id}", $change)->assertStatus(422)
                ->assertJsonPath('errors.frequency.0', 'Cette fiche a déjà été traitée aujourd’hui : changez sa fréquence demain, ou annulez d’abord la validation du jour.');
        }
        $this->assertSame('daily', $t->fresh()->frequency, 'rien n\'a bougé');
        $this->assertSame(1, HygieneTaskTemplate::query()->withTrashed()->count());
        // un autre réglage reste possible le jour même
        $this->putJson(self::BASE."/templates/{$t->id}", ['zone' => 'Plonge'])->assertOk();

        // validation annulée : le changement redevient possible
        $this->postJson(self::BASE."/completions/{$id}/void", ['reason' => 'erreur de fiche'])->assertOk();
        $this->putJson(self::BASE."/templates/{$t->id}", ['frequency' => 'weekly', 'weekday' => 4])->assertOk()->assertJsonPath('meta.replaced', true);
    }

    /** [tour 3 · P1] Réactiver une fiche masquée depuis un jour passé n'invente pas de « non faites » pour ses jours masqués. */
    public function test_reactiver_une_fiche_masquee_ne_transforme_pas_ses_jours_masques_en_non_faites(): void
    {
        $this->manager();
        $t = $this->hood(); // quotidienne depuis le 01/11
        Carbon::setTestNow(Carbon::parse('2026-11-05 12:00:00', config('app.timezone')));
        $this->putJson(self::BASE."/templates/{$t->id}", ['is_active' => false])->assertOk(); // masquée le 05/11
        Carbon::setTestNow(Carbon::parse('2026-11-19 10:00:00', config('app.timezone')));
        $avant = $this->dossier('2026-11-01', '2026-11-18');

        $r = $this->putJson(self::BASE."/templates/{$t->id}", ['is_active' => true])->assertOk();
        $this->assertTrue((bool) $r->json('meta.replaced'), 'la fiche masquée reste close à sa date de masquage, une nouvelle commence');
        $this->assertNotSame($t->id, $r->json('data.id'));
        $this->assertTrue((bool) $r->json('data.is_active'));

        $apres = $this->dossier('2026-11-01', '2026-11-18');
        $this->assertSame($avant['missed'], $apres['missed'], 'les jours masqués (06 → 18/11) ne deviennent pas « non faits »');
        $this->assertSame($avant['expected'], $apres['expected']);
        // le lendemain, la nouvelle fiche est attendue normalement
        Carbon::setTestNow(Carbon::parse('2026-11-21 09:00:00', config('app.timezone')));
        $this->assertSame($avant['missed'] + 1, $this->dossier('2026-11-01', '2026-11-20')['missed'], 'le 20/11 non fait compte ; le 19 (jour de reprise) non');
    }

    /** [tour 2 · P1] La relève garde le RETARD du jour : elle naît au début de la journée, son heure limite s'applique. */
    public function test_la_fiche_de_remplacement_reste_en_retard_et_sa_validation_est_dite_apres_l_heure(): void
    {
        $this->manager();
        $t = $this->hood();
        $t->forceFill(['due_time' => '09:00'])->save(); // heure limite passée (il est 10:00)
        $newId = $this->putJson(self::BASE."/templates/{$t->id}", ['frequency' => 'weekly', 'weekday' => 4])->assertOk()->json('data.id');

        $today = collect($this->getJson(self::BASE.'/today')->json('data'))->firstWhere('id', $newId);
        $this->assertTrue((bool) ($today['overdue'] ?? false), 'la relève est « en retard », pas « à faire »');
        $row = $this->postJson(self::BASE.'/completions', ['template_id' => $newId, 'all' => true])->assertCreated()->json('data');
        $this->assertTrue((bool) $row['late'], 'validée après l\'heure : le registre le dit');
    }

    // ───────── P0 droits : structure réservée au responsable ─────────

    public function test_un_caissier_ne_peut_ni_creer_ni_modifier_ni_supprimer_une_fiche_ni_installer_le_plan(): void
    {
        $this->cashier();
        $t = $this->hood();

        $this->postJson(self::BASE.'/templates', ['name' => 'Piratée', 'checklist' => ['a'], 'frequency' => 'daily'])->assertForbidden();
        $this->putJson(self::BASE."/templates/{$t->id}", ['name' => 'Piratée'])->assertForbidden();
        $this->patchJson(self::BASE."/templates/{$t->id}", ['frequency' => 'monthly'])->assertForbidden();
        $this->deleteJson(self::BASE."/templates/{$t->id}")->assertForbidden();
        $this->postJson(self::BASE.'/templates/install-recommended')->assertForbidden();
        $this->postJson(self::BASE.'/templates/install-recommended', ['only_if_empty' => true])->assertForbidden();

        $this->assertSame(['Hotte et filtres'], HygieneTaskTemplate::withTrashed()->pluck('name')->all());
        $this->assertSame('daily', $t->fresh()->frequency);
        $this->assertNull($t->fresh()->deleted_at);

        // le travail du jour reste ouvert à tout le personnel : lire, valider, annuler avec motif, journal
        $this->getJson(self::BASE.'/templates')->assertOk();
        $this->getJson(self::BASE.'/today')->assertOk();
        $id = $this->postJson(self::BASE.'/completions', ['template_id' => $t->id, 'all' => true])->assertCreated()->json('data.id');
        $this->postJson(self::BASE."/completions/$id/void", ['reason' => 'Erreur de fiche'])->assertOk()->assertJsonPath('data.voided', true);
        $this->getJson(self::BASE.'/history')->assertOk();
        $this->assertSame(1, HygieneTaskCompletion::query()->count());
    }

    public function test_le_responsable_gere_le_plan(): void
    {
        $this->manager();
        $id = $this->postJson(self::BASE.'/templates', ['name' => 'Machine à glaçons', 'checklist' => ['Bac vidé'], 'frequency' => 'daily'])->assertCreated()->json('data.id');
        $this->putJson(self::BASE."/templates/$id", ['name' => 'Machine à glaçons (bar)'])->assertOk();
        $this->postJson(self::BASE.'/templates/install-recommended')->assertCreated();
        $this->deleteJson(self::BASE."/templates/$id")->assertOk();
        $this->assertNotNull(HygieneTaskTemplate::withTrashed()->find($id)->deleted_at);
    }

    // ───────── P2 : messages en français ─────────

    public function test_les_erreurs_de_validation_nomment_les_champs_en_francais(): void
    {
        $this->manager();
        $r = $this->postJson(self::BASE.'/templates', ['name' => 'Dégraissage', 'checklist' => ['a'], 'frequency' => 'monthly', 'month_day' => 31, 'weekday' => 9])->assertStatus(422);
        $all = implode(' ', array_merge(...array_values($r->json('errors')))); // les MESSAGES (les clés restent les noms techniques)
        $this->assertStringContainsString('jour du mois', $all);
        $this->assertStringContainsString('jour de la semaine', $all);
        $this->assertStringNotContainsString('month day', $all);
        $this->assertStringNotContainsString('weekday', $all);
    }
}
