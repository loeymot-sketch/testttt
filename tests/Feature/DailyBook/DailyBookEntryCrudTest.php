<?php

namespace Tests\Feature\DailyBook;

use App\Models\DailyBookEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * [GOAL RUPTURE-CARNET 2026-07-15 / W4] CRUD Carnet : création dépense/acompte/
 * note, validations métier, photo facture, liste par jour, suppression.
 */
class DailyBookEntryCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        config(['daily_book.pin' => '2468']);
        $this->postJson('/carnet/api/pin', ['pin' => '2468'])->assertOk();
    }

    public function test_create_expense_with_photo(): void
    {
        Storage::fake('public');

        $this->post('/carnet/api/entries', [
            'type' => 'expense',
            'label' => 'Facture légumes Metro',
            'amount' => '86.40',
            'entry_date' => '2026-07-15',
            'photo' => UploadedFile::fake()->image('facture.jpg', 1200, 1600),
        ])->assertStatus(201)
            ->assertJsonPath('data.type', 'expense')
            ->assertJsonPath('data.amount', 86.4);

        $entry = DailyBookEntry::first();
        $this->assertNotNull($entry->getFirstMedia('invoice-photo'));
    }

    public function test_create_advance_requires_worker_name(): void
    {
        $this->postJson('/carnet/api/entries', [
            'type' => 'advance',
            'label' => 'Acompte',
            'amount' => '50',
            'entry_date' => '2026-07-15',
        ])->assertStatus(422)->assertJsonValidationErrors(['worker_name']);

        $this->postJson('/carnet/api/entries', [
            'type' => 'advance',
            'label' => 'Acompte',
            'worker_name' => 'Karim',
            'amount' => '50',
            'entry_date' => '2026-07-15',
        ])->assertStatus(201)->assertJsonPath('data.worker_name', 'Karim');
    }

    public function test_note_needs_no_amount_but_expense_does(): void
    {
        $this->postJson('/carnet/api/entries', [
            'type' => 'note',
            'label' => 'Penser à commander des serviettes',
            'entry_date' => '2026-07-15',
        ])->assertStatus(201)->assertJsonPath('data.amount', null);

        $this->postJson('/carnet/api/entries', [
            'type' => 'expense',
            'label' => 'Sans montant',
            'entry_date' => '2026-07-15',
        ])->assertStatus(422)->assertJsonValidationErrors(['amount']);
    }

    public function test_list_filters_by_date_and_delete_soft_deletes(): void
    {
        DailyBookEntry::create(['type' => 'expense', 'label' => 'A', 'amount' => 10, 'entry_date' => '2026-07-14', 'branch_id' => 1]);
        $b = DailyBookEntry::create(['type' => 'expense', 'label' => 'B', 'amount' => 20, 'entry_date' => '2026-07-15', 'branch_id' => 1]);

        $this->getJson('/carnet/api/entries?date=2026-07-15')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.label', 'B');

        $this->deleteJson('/carnet/api/entries/'.$b->id)->assertOk();
        $this->assertSoftDeleted('daily_book_entries', ['id' => $b->id]);
        $this->getJson('/carnet/api/entries?date=2026-07-15')->assertJsonCount(0, 'data');
    }
    public function test_note_with_phantom_amount_is_rejected(): void
    {
        // [BRAIN-SUPERVISOR] prohibited_if : montant fantôme sur une note faussait
        // le total du jour (front) vs le mois (back).
        $this->postJson('/carnet/api/entries', [
            'type' => 'note',
            'label' => 'Note avec montant',
            'amount' => '12.00',
            'entry_date' => '2026-07-15',
        ])->assertStatus(422)->assertJsonValidationErrors(['amount']);
    }

    public function test_entry_date_is_bounded(): void
    {
        foreach (['2019-01-01', now()->addYears(2)->format('Y-m-d')] as $bad) {
            $this->postJson('/carnet/api/entries', [
                'type' => 'expense',
                'label' => 'Date hors bornes',
                'amount' => '5',
                'entry_date' => $bad,
            ])->assertStatus(422)->assertJsonValidationErrors(['entry_date']);
        }
    }

    public function test_invoice_photo_is_served_behind_pin_only(): void
    {
        Storage::fake('local');

        $this->post('/carnet/api/entries', [
            'type' => 'expense',
            'label' => 'Facture photo gated',
            'amount' => '10',
            'entry_date' => '2026-07-15',
            'photo' => UploadedFile::fake()->image('facture.jpg', 600, 800),
        ])->assertStatus(201);

        $entry = DailyBookEntry::first();
        $res = $this->getJson('/carnet/api/entries?date=2026-07-15')->assertOk();
        $url = $res->json('data.0.photo_url');
        $this->assertStringContainsString('/carnet/api/entries/'.$entry->id.'/photo', $url);

        // Session verrouillée → la photo est refusée (401), pas d'URL /storage publique.
        $this->postJson('/carnet/api/lock')->assertOk();
        $this->get('/carnet/api/entries/'.$entry->id.'/photo')->assertStatus(401);
    }
}
