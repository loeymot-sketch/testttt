<?php

namespace Tests\Feature\Labels;

use App\Models\Branch;
use App\Models\DlcLabelProduct;
use App\Models\LabelPrintLog;
use App\Models\User;
use App\Services\Labels\DlcVoiceInterpreter;
use App\Services\Labels\DlcVoiceLocalMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [ETIQUETTES 2026-10-19] La dictée vocale : la phrase devient une sélection de produits. Ce qu'on prouve :
 * le modèle PROPOSE, le serveur VÉRIFIE (identifiant inventé, produit d'un autre restaurant, produit désactivé,
 * nombre absurde), une panne ne fabrique jamais de produits, et la route n'imprime ni n'écrit rien.
 */
class LabelVoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Branch::factory()->create(['id' => 1]);
        Branch::factory()->create(['id' => 2]);
        config(['services.openai.key' => '', 'label_voice.enabled' => true, 'services.openai.base_url' => 'https://api.test/v1']);
    }

    private function caisse(array $perms = ['pos-orders'], int $branch = 1): User
    {
        $user = User::factory()->create(['branch_id' => $branch]);
        $user->givePermissionTo($perms);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function product(string $name, array $attrs = []): DlcLabelProduct
    {
        $p = new DlcLabelProduct($attrs + ['name' => $name, 'shelf_life_value' => 2, 'shelf_life_unit' => 'days', 'storage' => '+4 °C']);
        $p->branch_id = $attrs['branch_id'] ?? 1;
        $p->save();

        return $p;
    }

    /** Réponse de chat OpenAI-compatible portant le JSON du modèle. */
    private function modelSays(array $payload): array
    {
        return ['choices' => [['message' => ['content' => json_encode($payload)]]]];
    }

    private function wav(): UploadedFile
    {
        // Un vrai contenu (un fichier « fake » est vide) : de quoi dépasser le seuil « rien à écouter ».
        return UploadedFile::fake()->createWithContent('voix.wav', "RIFF\0\0\0\0WAVEfmt ".str_repeat("\x01\x02", 3000));
    }

    // ───────────── moteur local (sans réseau) ─────────────

    public function test_local_matcher_splits_one_sentence_into_several_products_without_separators(): void
    {
        $this->caisse();
        $this->product('Salade iceberg coupée');
        $this->product('Tomates tranchées');
        $this->product('Oignons ciselés');
        $this->product('Poivrons grillés');
        $this->product('Pommes de terre');

        $r = DlcVoiceLocalMatcher::match('une salade iceberg coupée tomates tranchées oignons ciselés poivrons grillés', DlcLabelProduct::query()->get());

        $this->assertEqualsCanonicalizing(
            ['Salade iceberg coupée', 'Tomates tranchées', 'Oignons ciselés', 'Poivrons grillés'],
            array_column($r['items'], 'name')
        );
        $this->assertSame([], $r['unmatched']);
    }

    public function test_local_matcher_never_guesses_between_two_equally_good_products(): void
    {
        $this->caisse();
        $this->product('Tomates tranchées');
        $this->product('Tomates cerises coupées');

        $r = DlcVoiceLocalMatcher::match('tomates', DlcLabelProduct::query()->get());

        $this->assertSame([], $r['items'], '« tomates » désigne deux fiches : le moteur local ne tranche pas au hasard');
        $this->assertSame(['tomat'], $r['unmatched']);
    }

    public function test_local_matcher_takes_a_word_that_names_one_product_and_reports_the_unknown_one(): void
    {
        $this->caisse();
        $m = $this->product('Mozzarella (ouverte)');

        $r = DlcVoiceLocalMatcher::match('imprime aussi mozzarella râpée et licornes', DlcLabelProduct::query()->get());

        $this->assertSame([$m->id], array_column($r['items'], 'product_id'));
        $this->assertContains('licorn', $r['unmatched'], 'un mot inconnu est rendu, pas avalé');
        $this->assertNotContains('imprim', $r['unmatched'], 'un mot de commande n’est pas « pas compris »');
    }

    public function test_stem_is_the_same_for_gender_and_plural(): void
    {
        foreach (['coupé', 'coupée', 'coupés', 'coupées'] as $w) {
            $this->assertSame('coup', DlcVoiceLocalMatcher::stem($w));
        }
        $this->assertSame(DlcVoiceLocalMatcher::stem('tomate'), DlcVoiceLocalMatcher::stem('Tomates'));
    }

    // ───────────── la route, sans clé ─────────────

    public function test_text_without_key_uses_the_local_engine(): void
    {
        $this->caisse();
        $o = $this->product('Oignons ciselés');
        Http::fake();

        $res = $this->postJson('/api/admin/labels/dlc/voice', ['transcript' => 'oignons ciselés']);

        $res->assertOk()->assertJsonPath('data.driver', 'local')->assertJsonPath('data.items.0.product_id', $o->id);
        Http::assertNothingSent();
    }

    public function test_audio_without_key_is_refused_never_invented(): void
    {
        $this->caisse();
        $this->product('Oignons ciselés');
        Http::fake();

        $this->postJson('/api/admin/labels/dlc/voice', ['audio' => $this->wav()])
            ->assertStatus(503)->assertJsonPath('status', 'voice_unavailable');
        Http::assertNothingSent();
    }

    // ───────────── la route, avec un modèle ─────────────

    public function test_model_answer_is_verified_against_the_active_catalogue_of_this_restaurant(): void
    {
        $this->caisse();
        config(['services.openai.key' => 'k', 'services.openai.model' => 'google/gemini-2.5-flash']);
        $tomates = $this->product('Tomates tranchées');
        $oignons = $this->product('Oignons ciselés');
        $off = $this->product('Poivrons coupés', ['is_active' => false]);
        $ailleurs = $this->product('Champignons frais émincés', ['branch_id' => 2]);
        Http::fake(['*' => Http::response($this->modelSays([
            'transcript' => 'tomates oignons poivrons champignons licornes',
            'items' => [
                ['product_id' => $tomates->id, 'copies' => 500, 'heard' => 'tomates'],
                ['product_id' => $oignons->id, 'copies' => 0, 'heard' => 'oignons'],
                ['product_id' => $off->id, 'copies' => 1, 'heard' => 'poivrons'],
                ['product_id' => $ailleurs->id, 'copies' => 1, 'heard' => 'champignons'],
                ['product_id' => 987654, 'copies' => 1, 'heard' => 'licornes'],
                ['product_id' => $tomates->id, 'copies' => 2, 'heard' => 'tomates encore'],
            ],
            'unmatched' => ['licornes'],
        ]))]);

        $res = $this->postJson('/api/admin/labels/dlc/voice', ['transcript' => 'tomates oignons poivrons champignons licornes']);

        $res->assertOk()->assertJsonPath('data.driver', 'openai');
        $items = collect($res->json('data.items'))->keyBy('product_id');
        $this->assertSame([$tomates->id, $oignons->id], $items->keys()->all(), 'ni inventé, ni désactivé, ni d’une autre branche');
        $this->assertSame(20, $items[$tomates->id]['copies'], '500 étiquettes → plafonné à 20, doublon fusionné sur le plus grand');
        $this->assertSame(1, $items[$oignons->id]['copies'], '0 étiquette → 1');
        $this->assertSame(['licornes'], $res->json('data.unmatched'));
        $this->assertSame('tomates oignons poivrons champignons licornes', $res->json('data.transcript'));
    }

    public function test_audio_is_sent_as_input_audio_with_only_this_restaurants_active_catalogue(): void
    {
        $this->caisse();
        config(['services.openai.key' => 'secret-k', 'services.openai.model' => 'google/gemini-2.5-flash']);
        $this->product('Tomates tranchées');
        $this->product('Produit éteint', ['is_active' => false]);
        $this->product('Produit voisin', ['branch_id' => 2]);
        Http::fake(['*' => Http::response($this->modelSays(['transcript' => '', 'items' => [], 'unmatched' => []]))]);

        $res = $this->postJson('/api/admin/labels/dlc/voice', ['audio' => $this->wav()]);

        $res->assertOk()->assertJsonPath('data.transcript', '');
        $this->assertNotNull($res->json('message'), 'rien entendu : la tablette le dit');
        Http::assertSent(function ($req) {
            $parts = $req['messages'][0]['content'];
            $audio = collect($parts)->firstWhere('type', 'input_audio');
            $text = collect($parts)->firstWhere('type', 'text')['text'];

            return str_ends_with($req->url(), '/chat/completions')
                && $req->hasHeader('Authorization', 'Bearer secret-k')
                && $req['model'] === 'google/gemini-2.5-flash'
                && $req['temperature'] === 0
                && $audio !== null && $audio['input_audio']['format'] === 'wav' && $audio['input_audio']['data'] !== ''
                && str_contains($text, 'Tomates tranchées')
                && ! str_contains($text, 'Produit éteint')
                && ! str_contains($text, 'Produit voisin');
        });
    }

    public function test_empty_recording_is_not_sent_to_the_model(): void
    {
        $this->caisse();
        config(['services.openai.key' => 'k']);
        $this->product('Tomates tranchées');
        Http::fake();

        $res = $this->postJson('/api/admin/labels/dlc/voice', ['audio' => UploadedFile::fake()->createWithContent('voix.wav', 'RIFF')]);

        $res->assertOk()->assertJsonPath('data.items', [])->assertJsonPath('data.driver', 'none');
        $this->assertNotNull($res->json('message'));
        Http::assertNothingSent();
    }

    public function test_provider_failure_on_audio_is_an_error_not_an_invention(): void
    {
        $this->caisse();
        config(['services.openai.key' => 'k']);
        $this->product('Tomates tranchées');
        Http::fake(['*' => Http::response('boom', 500)]);

        $this->postJson('/api/admin/labels/dlc/voice', ['audio' => $this->wav()])
            ->assertStatus(502)->assertJsonPath('status', 'voice_failed');
    }

    public function test_unreadable_model_answer_on_audio_is_an_error(): void
    {
        $this->caisse();
        config(['services.openai.key' => 'k']);
        $this->product('Tomates tranchées');
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'pas du json']]]])]);

        $this->postJson('/api/admin/labels/dlc/voice', ['audio' => $this->wav()])->assertStatus(502);
    }

    public function test_provider_failure_on_text_falls_back_to_the_local_engine(): void
    {
        $this->caisse();
        config(['services.openai.key' => 'k']);
        $o = $this->product('Oignons ciselés');
        Http::fake(['*' => Http::response('boom', 500)]);

        $this->postJson('/api/admin/labels/dlc/voice', ['transcript' => 'oignons ciselés'])
            ->assertOk()->assertJsonPath('data.driver', 'local')->assertJsonPath('data.items.0.product_id', $o->id);
    }

    public function test_kill_switch_turns_the_model_off(): void
    {
        $this->caisse();
        config(['services.openai.key' => 'k', 'label_voice.enabled' => false]);
        $this->product('Oignons ciselés');
        Http::fake();

        $this->postJson('/api/admin/labels/dlc/voice', ['audio' => $this->wav()])->assertStatus(503);
        Http::assertNothingSent();
    }

    // ───────────── garde-fous ─────────────

    public function test_voice_never_prints_nor_writes_the_journal(): void
    {
        $this->caisse();
        config(['services.openai.key' => 'k']);
        $p = $this->product('Oignons ciselés');
        Http::fake(['*' => Http::response($this->modelSays(['transcript' => 'oignons', 'items' => [['product_id' => $p->id, 'copies' => 3, 'heard' => 'oignons']], 'unmatched' => []]))]);

        $this->postJson('/api/admin/labels/dlc/voice', ['transcript' => 'oignons'])->assertOk();

        $this->assertSame(0, LabelPrintLog::query()->withoutGlobalScopes()->count());
    }

    public function test_requires_the_labels_permission(): void
    {
        $this->caisse([]);
        $this->product('Oignons ciselés');

        $this->postJson('/api/admin/labels/dlc/voice', ['transcript' => 'oignons'])->assertForbidden();
    }

    public function test_requires_audio_or_text_and_a_catalogue(): void
    {
        $this->caisse();
        $this->postJson('/api/admin/labels/dlc/voice', [])->assertStatus(422);
        $this->postJson('/api/admin/labels/dlc/voice', ['transcript' => 'oignons'])
            ->assertStatus(422)->assertJsonPath('status', 'no_catalogue');
    }

    public function test_oversized_or_wrong_type_audio_is_rejected(): void
    {
        $this->caisse();
        config(['services.openai.key' => 'k', 'label_voice.max_kb' => 100]);
        $this->product('Oignons ciselés');
        Http::fake();

        $this->postJson('/api/admin/labels/dlc/voice', ['audio' => UploadedFile::fake()->create('v.wav', 500, 'audio/wav')])->assertStatus(422);
        $this->postJson('/api/admin/labels/dlc/voice', ['audio' => UploadedFile::fake()->create('v.exe', 10, 'application/octet-stream')])->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_normalize_ignores_garbage_shapes(): void
    {
        $p = $this->product('Oignons ciselés');
        $r = DlcVoiceInterpreter::normalize(
            ['items' => ['x', ['product_id' => 'abc'], ['product_id' => (string) $p->id, 'copies' => 'beaucoup'], null], 'unmatched' => [['a'], str_repeat('é', 200), 'ok', 'ok']],
            DlcLabelProduct::query()->get()
        );

        $this->assertSame([$p->id], array_column($r['items'], 'product_id'));
        $this->assertSame(1, $r['items'][0]['copies']);
        $this->assertCount(2, $r['unmatched']);
        $this->assertSame(60, mb_strlen($r['unmatched'][0]));
    }
}
