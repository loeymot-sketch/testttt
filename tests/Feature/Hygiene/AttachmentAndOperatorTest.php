<?php

namespace Tests\Feature\Hygiene;

use App\Models\Branch;
use App\Models\HygieneAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** [CAYENNE HYGIÈNE 2026-10-17] Socle : « qui est-ce ? » et pièces jointes photo ré-encodées. */
class AttachmentAndOperatorTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/admin/hygiene';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Branch::factory()->create(['id' => 1]);
        Branch::factory()->create(['id' => 2]);
        Storage::fake('local');
    }

    private function staff(int $branch = 1, string $role = 'POS Operator', string $name = 'Karim Benali'): User
    {
        $u = User::factory()->create(['branch_id' => $branch, 'name' => $name]);
        $u->givePermissionTo('pos-orders');
        $u->assignRole($role);
        Sanctum::actingAs($u, ['*']);

        return $u;
    }

    private function png(int $w = 40, int $h = 30): UploadedFile
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 30));
        $f = tempnam(sys_get_temp_dir(), 'hy').'.png';
        imagepng($im, $f);

        return new UploadedFile($f, 'photo.png', 'image/png', null, true);
    }

    public function test_operators_lists_only_the_staff_of_this_restaurant(): void
    {
        $me = $this->staff();
        User::factory()->create(['branch_id' => 1, 'name' => 'Sarah Lopez'])->assignRole('Chef');
        User::factory()->create(['branch_id' => 1, 'name' => 'Un Client'])->assignRole('Customer');
        User::factory()->create(['branch_id' => 2, 'name' => 'Autre Site'])->assignRole('Chef');

        $r = $this->getJson(self::BASE.'/operators')->assertOk();
        $names = collect($r->json('data'))->pluck('name')->all();
        $this->assertContains('Karim Benali', $names);
        $this->assertContains('Sarah Lopez', $names);
        $this->assertNotContains('Un Client', $names);
        $this->assertNotContains('Autre Site', $names);
        $this->assertSame($me->id, $r->json('meta.me'));
        $this->assertSame('Sarah L.', collect($r->json('data'))->firstWhere('name', 'Sarah Lopez')['short']);
    }

    public function test_upload_reencodes_to_jpeg_and_serves_it_back(): void
    {
        $this->staff();
        $r = $this->post(self::BASE.'/attachments', ['file' => $this->png(), 'kind' => 'bl'], ['Accept' => 'application/json'])->assertCreated();
        $id = $r->json('data.id');
        $row = HygieneAttachment::query()->findOrFail($id);
        $this->assertSame('image/jpeg', $row->mime);
        $this->assertSame('bl', $row->kind);
        $this->assertTrue(Storage::disk('local')->exists($row->path));
        $this->assertStringStartsWith("\xFF\xD8", Storage::disk('local')->get($row->path), 'JPEG réel, pas le fichier reçu');

        $get = $this->get(self::BASE."/attachments/$id")->assertOk();
        $this->assertSame('image/jpeg', $get->headers->get('Content-Type'));
        $this->assertSame('nosniff', $get->headers->get('X-Content-Type-Options'));
    }

    public function test_large_photo_is_scaled_down(): void
    {
        $this->staff();
        $r = $this->post(self::BASE.'/attachments', ['file' => $this->png(3200, 2400)], ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame(1600, $r->json('data.width'));
        $this->assertSame(1200, $r->json('data.height'));
    }

    public function test_a_non_image_is_refused_even_with_an_image_extension(): void
    {
        $this->staff();
        $f = UploadedFile::fake()->createWithContent('photo.jpg', '<?php echo 1; ?> not an image');
        $this->post(self::BASE.'/attachments', ['file' => $f], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame(0, HygieneAttachment::query()->count());
    }

    public function test_another_restaurant_cannot_read_the_photo(): void
    {
        $this->staff(1);
        $id = $this->post(self::BASE.'/attachments', ['file' => $this->png()], ['Accept' => 'application/json'])->json('data.id');
        $this->staff(2);
        $this->get(self::BASE."/attachments/$id", ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_requires_authentication(): void
    {
        $this->getJson(self::BASE.'/operators')->assertUnauthorized();
    }
}
