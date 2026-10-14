<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChatImagePersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_uploaded_image_is_stored_permanently_and_returned_in_response(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('diagram.png', 100, 100);

        // Mock HermesClient agar tidak memanggil AI sungguhan
        $this->mock(\App\Services\HermesClient::class, function ($mock) {
            $mock->shouldReceive('sendMessage')->andReturn('Baik, saya lihat gambarnya.');
        });

        $response = $this->actingAs($user)->postJson('/chat/messages', [
            'message' => 'Tolong jelaskan gambar ini',
            'image' => $file,
        ]);

        $response->assertOk();
        $response->assertJsonPath('user_message.image_url', fn ($url) => str_contains((string) $url, 'chat-images/'));

        // File tersimpan di storage
        $message = ChatMessage::firstWhere('role', 'user');
        $this->assertNotNull($message->image_path);
        Storage::disk('public')->assertExists($message->image_path);

        // URL bisa diakses
        $this->assertStringContainsString('chat-images/', $message->imageUrl());
    }

    public function test_message_without_image_has_null_image_url(): void
    {
        $user = User::factory()->create();

        $this->mock(\App\Services\HermesClient::class, function ($mock) {
            $mock->shouldReceive('sendMessage')->andReturn('Halo!');
        });

        $response = $this->actingAs($user)->postJson('/chat/messages', [
            'message' => 'Halo',
        ]);

        $response->assertOk();
        $this->assertNull($response->json('user_message.image_url'));

        $message = ChatMessage::firstWhere('role', 'user');
        $this->assertNull($message->image_path);
        $this->assertNull($message->imageUrl());
    }

    public function test_invalid_image_is_rejected(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('dokumen.pdf', 100, 'application/pdf');

        $response = $this->actingAs($user)->postJson('/chat/messages', [
            'message' => 'Ini pdf',
            'image' => $file,
        ]);

        $response->assertStatus(422);
    }
}
