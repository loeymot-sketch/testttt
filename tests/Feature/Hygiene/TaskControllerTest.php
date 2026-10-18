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
 * [CAYENNE HYGIÈNE 2026-10-17] Plan de nettoyage : fiches du jour, validation par une personne identifiée, point non fait motivé,
 * journal append-only avec checklist copiée.
 */
class TaskControllerTest extends TestCase
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
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00:00', config('app.timezone'))); // un MARDI
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function staff(int $branch = 1, array $perms = ['pos-orders'], string $role = 'POS Operator', string $name = 'Karim Benali'): User
    {
        $user = User::factory()->create(['branch_id' => $branch, 'name' => $name]);
        $user->givePermissionTo($perms);
        $user->assignRole($role);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function install(): void
    {
        $this->postJson(self::BASE.'/templates/install-recommended')->assertCreated();
    }

    private function template(string $key): HygieneTaskTemplate
    {
        return HygieneTaskTemplate::query()->where('catalog_key', $key)->firstOrFail();
    }

    private function allKeys(HygieneTaskTemplate $t): array
    {
        return collect($t->checklist)->pluck('key')->all();
    }

    // ───────── catalogue de départ ─────────

    public function test_install_recommended_creates_the_starter_plan_and_is_idempotent(): void
    {
        $this->staff();
        $this->install();
        $this->assertSame(8, HygieneTaskTemplate::query()->count());
        $this->postJson(self::BASE.'/templates/install-recommended')->assertOk()->assertJsonPath('data.added', 0);
        $this->assertSame(8, HygieneTaskTemplate::query()->count());
    }

    public function test_auto_install_only_when_never_configured_and_never_after_a_deliberate_delete(): void
    {
        $this->staff();
        $this->postJson(self::BASE.'/templates/install-recommended', ['only_if_empty' => true])->assertCreated();
        $this->template('salle')->delete();
        $this->postJson(self::BASE.'/templates/install-recommended', ['only_if_empty' => true])->assertOk()->assertJsonPath('data.added', 0);
        $this->assertSame(7, HygieneTaskTemplate::query()->count());
        // la demande explicite, elle, remet la fiche absente
        $this->postJson(self::BASE.'/templates/install-recommended')->assertCreated()->assertJsonPath('data.added', 1);
    }

    // ───────── l'écran du jour ─────────

    public function test_today_lists_only_the_sheets_due_today(): void
    {
        $this->staff();
        $this->install();
        // mardi : les 5 fiches quotidiennes, pas les 3 hebdomadaires du lundi
        $r = $this->getJson(self::BASE.'/today')->assertOk();
        $this->assertSame(5, $r->json('meta.summary.total'));
        $this->assertSame(5, $r->json('meta.summary.todo'));
        $this->assertNotContains('hotte', collect($r->json('data'))->pluck('catalog_key')->all());

        Carbon::setTestNow(Carbon::parse('2026-10-19 10:00:00', config('app.timezone'))); // lundi
        $this->assertSame(8, $this->getJson(self::BASE.'/today')->json('meta.summary.total'));
    }

    public function test_monthly_sheet_falls_on_the_last_day_when_the_month_is_short(): void
    {
        $this->staff();
        $t = new HygieneTaskTemplate(['name' => 'Dégraissage', 'checklist' => [['key' => 'k1', 'label' => 'Tout']], 'frequency' => 'monthly', 'month_day' => 28, 'sort_order' => 10, 'is_active' => true]);
        $t->branch_id = 1;
        $t->save();
        Carbon::setTestNow(Carbon::parse('2026-02-28 09:00:00', config('app.timezone')));
        $this->assertSame(1, $this->getJson(self::BASE.'/today')->json('meta.summary.total'));
        Carbon::setTestNow(Carbon::parse('2026-02-27 09:00:00', config('app.timezone')));
        $this->assertSame(0, $this->getJson(self::BASE.'/today')->json('meta.summary.total'));
    }

    // ───────── validation ─────────

    public function test_complete_all_in_one_gesture_records_the_copied_checklist_and_the_operator(): void
    {
        $user = $this->staff();
        $this->install();
        $t = $this->template('salle');

        $r = $this->postJson(self::BASE.'/completions', ['template_id' => $t->id, 'all' => true])->assertCreated();
        $r->assertJsonPath('data.complete', true)->assertJsonPath('data.items_done', 4)->assertJsonPath('data.operator_name', 'Karim Benali');
        $row = HygieneTaskCompletion::query()->firstOrFail();
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame('Salle', $row->task_name);
        $this->assertSame('2026-10-20', $row->due_date->toDateString());
        $this->assertSame('Sols', collect($row->items)->firstWhere('key', 'k2')['label']);

        $today = $this->getJson(self::BASE.'/today');
        $this->assertSame(1, $today->json('meta.summary.done'));
        $this->assertSame(4, $today->json('meta.summary.todo'));
    }

    public function test_a_point_left_undone_requires_a_reason(): void
    {
        $this->staff();
        $this->install();
        $t = $this->template('salle');
        $keys = $this->allKeys($t);

        $this->postJson(self::BASE.'/completions', ['template_id' => $t->id, 'done' => array_slice($keys, 0, 3)])
            ->assertStatus(422)->assertJsonValidationErrors('note');
        $this->assertSame(0, HygieneTaskCompletion::query()->count());

        $r = $this->postJson(self::BASE.'/completions', ['template_id' => $t->id, 'done' => array_slice($keys, 0, 3), 'note' => 'Vitrine en panne'])->assertCreated();
        $r->assertJsonPath('data.complete', false)->assertJsonPath('data.items_done', 3)->assertJsonPath('data.note', 'Vitrine en panne');
        $this->assertSame(1, $this->getJson(self::BASE.'/today')->json('meta.summary.partial'));
    }

    public function test_complete_note_is_dropped_when_everything_is_done(): void
    {
        $this->staff();
        $this->install();
        $r = $this->postJson(self::BASE.'/completions', ['template_id' => $this->template('salle')->id, 'all' => true, 'note' => 'rien à signaler'])->assertCreated();
        $this->assertNull($r->json('data.note'));
    }

    public function test_labels_come_from_the_sheet_never_from_the_client(): void
    {
        $this->staff();
        $this->install();
        $t = $this->template('salle');
        $this->postJson(self::BASE.'/completions', ['template_id' => $t->id, 'all' => true, 'items' => [['key' => 'k1', 'label' => 'FAUX']]])->assertCreated();
        $this->assertSame('Mobilier et surfaces', HygieneTaskCompletion::query()->firstOrFail()->items[0]['label']);
    }

    public function test_unknown_point_is_refused(): void
    {
        $this->staff();
        $this->install();
        $this->postJson(self::BASE.'/completions', ['template_id' => $this->template('salle')->id, 'done' => ['zz']])->assertStatus(422)->assertJsonValidationErrors('done');
    }

    public function test_a_sheet_not_due_today_is_refused(): void
    {
        $this->staff();
        $this->install();
        $this->postJson(self::BASE.'/completions', ['template_id' => $this->template('hotte')->id, 'all' => true])->assertStatus(422);
    }

    public function test_a_sheet_already_completed_today_is_not_doubled_but_can_be_redone_after_a_void(): void
    {
        $this->staff();
        $this->install();
        $t = $this->template('salle');
        $first = $this->postJson(self::BASE.'/completions', ['template_id' => $t->id, 'all' => true])->assertCreated()->json('data.id');
        $this->postJson(self::BASE.'/completions', ['template_id' => $t->id, 'all' => true])->assertStatus(422);

        $this->postJson(self::BASE."/completions/$first/void", ['reason' => 'ab'])->assertStatus(422);
        $this->postJson(self::BASE."/completions/$first/void", ['reason' => 'Cochée par erreur'])->assertOk()->assertJsonPath('data.voided', true);
        $this->assertSame(1, HygieneTaskCompletion::query()->count(), 'la ligne reste au journal');
        $this->assertSame(0, $this->getJson(self::BASE.'/today')->json('meta.summary.done'));
        $this->postJson(self::BASE.'/completions', ['template_id' => $t->id, 'all' => true])->assertCreated();
    }

    public function test_replayed_request_with_same_client_ref_is_not_doubled(): void
    {
        $this->staff();
        $this->install();
        $body = ['template_id' => $this->template('salle')->id, 'all' => true, 'client_ref' => 'abc-123'];
        $a = $this->postJson(self::BASE.'/completions', $body)->assertCreated()->json('data.id');
        $b = $this->postJson(self::BASE.'/completions', $body)->assertOk()->json('data.id');
        $this->assertSame($a, $b);
        $this->assertSame(1, HygieneTaskCompletion::query()->count());
    }

    public function test_overdue_and_late_follow_the_sheet_time(): void
    {
        $this->staff();
        $this->install();
        $t = $this->template('salle');
        $t->forceFill(['due_time' => '09:00'])->save(); // il est 10:00
        $row = collect($this->getJson(self::BASE.'/today')->json('data'))->firstWhere('catalog_key', 'salle');
        $this->assertTrue($row['overdue']);
        $this->assertSame(1, $this->getJson(self::BASE.'/today')->json('meta.summary.overdue'));

        $r = $this->postJson(self::BASE.'/completions', ['template_id' => $t->id, 'all' => true])->assertCreated();
        $this->assertTrue($r->json('data.late'));
        $this->assertSame(0, $this->getJson(self::BASE.'/today')->json('meta.summary.overdue'));
    }

    public function test_operator_can_be_another_staff_member_but_never_a_stranger(): void
    {
        $this->staff();
        $other = User::factory()->create(['branch_id' => 1, 'name' => 'Sarah Lopez']);
        $other->assignRole('Chef');
        $outsider = User::factory()->create(['branch_id' => 2, 'name' => 'Autre Site']);
        $outsider->assignRole('Chef');
        $customer = User::factory()->create(['branch_id' => 1, 'name' => 'Un Client']);
        $customer->assignRole('Customer');
        $this->install();
        $t = $this->template('salle');

        foreach ([$outsider->id, $customer->id, 999999] as $bad) {
            $this->postJson(self::BASE.'/completions', ['template_id' => $t->id, 'all' => true, 'operator_user_id' => $bad])
                ->assertStatus(422)->assertJsonValidationErrors('operator_user_id');
        }
        $this->postJson(self::BASE.'/completions', ['template_id' => $t->id, 'all' => true, 'operator_user_id' => $other->id])
            ->assertCreated()->assertJsonPath('data.operator_name', 'Sarah Lopez');
        $this->assertSame($other->id, HygieneTaskCompletion::query()->firstOrFail()->user_id);
    }

    // ───────── fiches ─────────

    public function test_editing_a_sheet_never_rewrites_what_was_attested(): void
    {
        $this->staff();
        $this->install();
        $t = $this->template('salle');
        $this->postJson(self::BASE.'/completions', ['template_id' => $t->id, 'all' => true])->assertCreated();

        $this->putJson(self::BASE."/templates/{$t->id}", ['name' => 'Salle & terrasse', 'checklist' => [['key' => 'k1', 'label' => 'Tables']]])->assertOk();
        $row = HygieneTaskCompletion::query()->firstOrFail();
        $this->assertSame('Salle', $row->task_name);
        $this->assertCount(4, $row->items);
        $this->assertSame('Mobilier et surfaces', $row->items[0]['label']);
    }

    public function test_template_validation_and_defaults(): void
    {
        $this->staff();
        $this->postJson(self::BASE.'/templates', ['name' => 'X', 'checklist' => [], 'frequency' => 'daily'])->assertStatus(422);
        $this->postJson(self::BASE.'/templates', ['name' => 'X', 'checklist' => ['a'], 'frequency' => 'yearly'])->assertStatus(422);
        $this->postJson(self::BASE.'/templates', ['name' => 'X', 'checklist' => ['a'], 'frequency' => 'daily', 'due_time' => '25:00'])->assertStatus(422);
        $this->postJson(self::BASE.'/templates', ['name' => 'X', 'checklist' => ['  '], 'frequency' => 'daily'])->assertStatus(422);

        $r = $this->postJson(self::BASE.'/templates', ['name' => 'Machine à glaçons', 'checklist' => ['Bac vidé', 'Joints lavés'], 'frequency' => 'weekly', 'due_time' => '22:30'])->assertCreated();
        $r->assertJsonPath('data.weekday', 1)->assertJsonPath('data.checklist.0.key', 'k1')->assertJsonPath('data.checklist.1.key', 'k2');
        $this->postJson(self::BASE.'/templates', ['name' => 'machine à glaçons', 'checklist' => ['a'], 'frequency' => 'daily'])->assertStatus(422);
    }

    public function test_history_filters_and_keeps_voided_rows(): void
    {
        $this->staff();
        $this->install();
        $a = $this->postJson(self::BASE.'/completions', ['template_id' => $this->template('salle')->id, 'all' => true])->json('data.id');
        $this->postJson(self::BASE.'/completions', ['template_id' => $this->template('dechets')->id, 'done' => [], 'note' => 'Local fermé'])->assertCreated();
        $this->postJson(self::BASE."/completions/$a/void", ['reason' => 'Erreur de fiche']);

        $this->assertCount(2, $this->getJson(self::BASE.'/history')->json('data'));
        $inc = $this->getJson(self::BASE.'/history?incomplete_only=1')->json('data');
        $this->assertCount(1, $inc);
        $this->assertSame('Poubelles et local déchets', $inc[0]['task_name']);
    }

    // ───────── isolation et porte ─────────

    public function test_branch_isolation(): void
    {
        $this->staff(1);
        $this->install();
        $id = $this->template('salle')->id;

        $this->staff(2);
        $this->assertSame(0, $this->getJson(self::BASE.'/today')->json('meta.summary.total'));
        $this->postJson(self::BASE.'/completions', ['template_id' => $id, 'all' => true])->assertStatus(404);
        $this->putJson(self::BASE."/templates/$id", ['name' => 'Piraté'])->assertNotFound();
    }

    public function test_requires_the_staff_permission(): void
    {
        $this->getJson(self::BASE.'/today')->assertUnauthorized();
        $user = User::factory()->create(['branch_id' => 1]);
        Sanctum::actingAs($user, ['*']); // aucun rôle, aucune permission
        $this->getJson(self::BASE.'/today')->assertForbidden();
        $this->postJson(self::BASE.'/completions', [])->assertForbidden();
    }

    // ───────────── engagement (« je confirme l'exactitude des informations… ») ─────────────

    public function test_l_engagement_coche_est_date_et_reste_au_journal_et_au_dossier(): void
    {
        $this->staff();
        $this->install();
        $t = $this->template('salle');
        $r = $this->postJson(self::BASE.'/completions', ['template_id' => $t->id, 'all' => true, 'attested' => true])->assertCreated();

        $r->assertJsonPath('data.attested', true);
        $row = HygieneTaskCompletion::query()->firstOrFail();
        $this->assertNotNull($row->attested_at);
        $this->assertEquals(now()->startOfMinute(), $row->attested_at->startOfMinute());

        $csv = $this->get('/api/admin/hygiene/report/csv?module=tasks&from='.now()->toDateString().'&to='.now()->addDay()->toDateString())->assertOk()->streamedContent();
        $this->assertStringContainsString('Engagement', $csv);
        $this->assertStringContainsString(';oui;', $csv);
    }

    public function test_sans_engagement_la_validation_est_enregistree_mais_dite_sans_engagement(): void
    {
        $this->staff();
        $this->install();
        $t = $this->template('salle');
        $r = $this->postJson(self::BASE.'/completions', ['template_id' => $t->id, 'all' => true])->assertCreated();
        $r->assertJsonPath('data.attested', false);
        $this->assertNull(HygieneTaskCompletion::query()->firstOrFail()->attested_at);
        $csv = $this->get('/api/admin/hygiene/report/csv?module=tasks&from='.now()->toDateString().'&to='.now()->addDay()->toDateString())->assertOk()->streamedContent();
        $this->assertStringContainsString(';non;', $csv);
    }

    public function test_un_engagement_non_booleen_est_refuse(): void
    {
        $this->staff();
        $this->install();
        $t = $this->template('salle');
        $this->postJson(self::BASE.'/completions', ['template_id' => $t->id, 'all' => true, 'attested' => 'peut-être'])->assertStatus(422)->assertJsonValidationErrors('attested');
        $this->assertSame(0, HygieneTaskCompletion::query()->count());
    }
}
