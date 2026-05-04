<?php

namespace Tests\Feature\Items;

use App\Http\Controllers\Admin\ItemPhotoController;
use App\Http\Requests\ItemPhotoUploadRequest;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class ItemPhotoUploadAtomicityTest extends TestCase
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

    public function test_atomic_upload_when_storage_throws_keeps_original_image(): void
    {
        $item = Item::factory()->create();

        $this->post($this->endpoint($item), [
            'photo' => UploadedFile::fake()->image('first.jpg', 800, 600),
        ])->assertOk();

        $initialMedia = $item->fresh()->getMedia('item');
        $this->assertCount(1, $initialMedia);
        $this->assertSame('first.jpg', $initialMedia->first()->file_name);

        $request = ItemPhotoUploadRequest::create($this->endpoint($item), 'POST', [], [], [
            'photo' => UploadedFile::fake()->image('second.jpg', 800, 600),
        ]);
        $request->setContainer($this->app);
        $this->app->instance('request', $request);

        $controller = new class extends ItemPhotoController {
            protected function addPhotoMedia(Item $item): Media
            {
                parent::addPhotoMedia($item);

                throw new RuntimeException('Simulated storage failure after media write');
            }
        };

        $response = $controller->store($request, $item);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame([
            'message'    => 'Photo upload failed',
            'error_code' => 'UPLOAD_FAILED',
        ], $response->getData(true));

        $media = $item->fresh()->getMedia('item');
        $this->assertCount(1, $media);
        $this->assertSame('first.jpg', $media->first()->file_name);
    }

    public function test_successful_upload_replaces_old_image_cleanly(): void
    {
        $item = Item::factory()->create();

        $this->post($this->endpoint($item), [
            'photo' => UploadedFile::fake()->image('first.jpg', 800, 600),
        ])->assertOk();

        $response = $this->post($this->endpoint($item), [
            'photo' => UploadedFile::fake()->image('second.jpg', 800, 600),
        ]);

        $response->assertOk()
            ->assertJsonStructure(['id', 'thumb_url', 'cover_url', 'preview_url'])
            ->assertJsonPath('id', $item->id);

        $media = $item->fresh()->getMedia('item');
        $this->assertCount(1, $media);
        $this->assertSame('second.jpg', $media->first()->file_name);
        $this->assertStringContainsString('second', $response->json('thumb_url'));
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
