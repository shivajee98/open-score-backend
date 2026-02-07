<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\TicketMessage;
use App\Models\SupportTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StorageMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_onboarding_stores_profile_image_locally()
    {
        Storage::fake('public');

        $user = User::factory()->create(['is_onboarded' => false]);
        $this->actingAs($user, 'api');

        $file = UploadedFile::fake()->image('profile.jpg');

        $response = $this->postJson('/api/complete-onboarding', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'business_name' => 'Johns Business',
            'profile_image' => $file,
        ]);

        $response->assertStatus(200);

        $user->refresh();
        $this->assertNotNull($user->profile_image);
        $this->assertStringNotContainsString('http', $user->getRawOriginal('profile_image'));
        $this->assertStringContainsString('profiles/', $user->getRawOriginal('profile_image'));
        
        // Check accessor
        $this->assertStringContainsString('http://localhost/storage/profiles/', $user->profile_image);

        Storage::disk('public')->assertExists($user->getRawOriginal('profile_image'));
    }

    public function test_support_ticket_stores_attachment_locally()
    {
        Storage::fake('public');

        $user = User::factory()->create(['role' => 'CUSTOMER']);
        $this->actingAs($user, 'api');

        $ticket = SupportTicket::create([
            'user_id' => $user->id,
            'subject' => 'Help',
            'status' => 'open',
            'priority' => 'medium',
        ]);

        $file = UploadedFile::fake()->create('doc.pdf', 100);

        $response = $this->postJson("/api/support/tickets/{$ticket->id}/messages", [
            'message' => 'Here is my doc',
            'attachment' => $file,
        ]);

        $response->assertStatus(201);

        $message = TicketMessage::latest()->first();
        $this->assertNotNull($message->attachment_url);
        $this->assertStringNotContainsString('http', $message->getRawOriginal('attachment_url'));
        $this->assertStringContainsString('attachments/', $message->getRawOriginal('attachment_url'));

        // Check accessor
        $this->assertStringContainsString('http://localhost/storage/attachments/', $message->attachment_url);

        Storage::disk('public')->assertExists($message->getRawOriginal('attachment_url'));
    }

    public function test_user_update_profile_stores_multiple_shop_images()
    {
        Storage::fake('public');

        $user = User::factory()->create(['role' => 'MERCHANT']);
        $this->actingAs($user, 'api');

        $files = [
            UploadedFile::fake()->image('shop1.jpg'),
            UploadedFile::fake()->image('shop2.jpg'),
        ];

        $response = $this->postJson('/api/update-profile', [
            'shop_images' => $files,
        ]);

        $response->assertStatus(200);

        $user->refresh();
        $this->assertCount(2, $user->shop_images);
        
        $rawImages = $user->getRawOriginal('shop_images');
        $this->assertIsArray($user->shop_images);
        
        foreach ($user->shop_images as $url) {
            $this->assertStringContainsString('http://localhost/storage/merchants/', $url);
        }

        foreach (json_decode($rawImages, true) as $path) {
            Storage::disk('public')->assertExists($path);
        }
    }
}
