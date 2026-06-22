<?php

namespace Tests\Feature\Items;

use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ItemPhotoUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        Sanctum::actingAs($this->adminUser(), ['*']);
    }

    public function test_uploads_valid_jpg_and_attaches_to_media_collection_item(): void
    {
        $item = Item::factory()->create();

        $response = $this->post($this->endpoint($item), [
            'photo' => UploadedFile::fake()->image('photo.jpg', 800, 600),
        ]);

        $response->assertOk()
            ->assertJsonStructure(['id', 'thumb_url', 'cover_url', 'preview_url'])
            ->assertJsonPath('id', $item->id);

        $media = $item->fresh()->getFirstMedia('item');
        $this->assertNotNull($media);
        $this->assertSame('item', $media->collection_name);
    }

    public function test_replaces_old_photo_when_new_uploaded(): void
    {
        $item = Item::factory()->create();

        $this->post($this->endpoint($item), [
            'photo' => UploadedFile::fake()->image('first.jpg', 800, 600),
        ])->assertOk();

        $this->post($this->endpoint($item), [
            'photo' => UploadedFile::fake()->image('second.jpg', 800, 600),
        ])->assertOk();

        $media = $item->fresh()->getMedia('item');
        $this->assertCount(1, $media);
        $this->assertSame('second.jpg', $media->first()->file_name);
    }

    public function test_rejects_oversize_file(): void
    {
        $item = Item::factory()->create();

        $this->post($this->endpoint($item), [
            'photo' => UploadedFile::fake()->image('oversize.jpg', 800, 600)->size(4097),
        ])->assertStatus(422);
    }

    public function test_rejects_invalid_mime(): void
    {
        $item = Item::factory()->create();

        $this->post($this->endpoint($item), [
            'photo' => UploadedFile::fake()->create('photo.txt', 100, 'text/plain'),
        ])->assertStatus(422);
    }

    public function test_rejects_missing_photo(): void
    {
        $item = Item::factory()->create();

        $this->post($this->endpoint($item))->assertStatus(422);
    }

    private function adminUser(): User
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');

        return $admin;
    }

    private function endpoint(Item $item): string
    {
        return "/api/admin/items/{$item->id}/photo";
    }
}
