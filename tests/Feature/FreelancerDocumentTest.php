<?php

namespace Tests\Feature;

use App\Models\Freelancer;
use App\Models\FreelancerDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FreelancerDocumentTest extends TestCase
{
    use RefreshDatabase;

    private Freelancer $freelancer;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->freelancer = Freelancer::factory()->verified()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);
        $this->token = $this->freelancer->createToken('test')->plainTextToken;
    }

    // ─── Upload: profile image + documents named by slug ─────────────

    public function test_profile_image_is_stored_with_name_slug(): void
    {
        $file = UploadedFile::fake()->image('anything.png');

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->post("/api/freelancers/{$this->freelancer->id}", [
                '_method' => 'PUT',
                'profile_image' => $file,
            ]);

        Storage::disk('public')->assertExists('profile_images/john-doe.png');

        $this->assertSame('profile_images/john-doe.png', $this->freelancer->fresh()->profile_image);
    }

    public function test_documents_are_stored_with_name_slug_prefix(): void
    {
        $file = UploadedFile::fake()->create('resume.pdf', 10, 'application/pdf');

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->post("/api/freelancers/{$this->freelancer->id}", [
                '_method' => 'PUT',
                'documents' => [$file],
            ]);

        $doc = $this->freelancer->documents()->first();
        $this->assertNotNull($doc);
        $this->assertStringStartsWith('documents/john-doe-', $doc->file_path);
        $this->assertStringEndsWith('.pdf', $doc->file_path);
        Storage::disk('public')->assertExists($doc->file_path);

        $this->assertSame('resume.pdf', $doc->original_name);
    }

    // ─── Delete document ─────────────────────────────────────────────

    public function test_freelancer_can_delete_own_document(): void
    {
        Storage::disk('public')->put('documents/john-doe-x.pdf', 'content');
        $doc = FreelancerDocument::create([
            'freelancer_id' => $this->freelancer->id,
            'file_path' => 'documents/john-doe-x.pdf',
            'file_type' => 'pdf',
            'original_name' => 'resume.pdf',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->deleteJson("/api/freelancers/{$this->freelancer->id}/documents/{$doc->id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'Document deleted successfully.']);

        $this->assertDatabaseMissing('freelancer_documents', ['id' => $doc->id]);
        Storage::disk('public')->assertMissing('documents/john-doe-x.pdf');
    }

    public function test_freelancer_cannot_delete_another_freelancers_document(): void
    {
        $other = Freelancer::factory()->verified()->create();
        Storage::disk('public')->put('documents/other.pdf', 'x');
        $doc = FreelancerDocument::create([
            'freelancer_id' => $other->id,
            'file_path' => 'documents/other.pdf',
            'file_type' => 'pdf',
            'original_name' => 'other.pdf',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->deleteJson("/api/freelancers/{$this->freelancer->id}/documents/{$doc->id}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('freelancer_documents', ['id' => $doc->id]);
    }

    public function test_unauthenticated_cannot_delete_document(): void
    {
        $doc = FreelancerDocument::create([
            'freelancer_id' => $this->freelancer->id,
            'file_path' => 'documents/x.pdf',
            'file_type' => 'pdf',
            'original_name' => 'x.pdf',
        ]);

        $this->deleteJson("/api/freelancers/{$this->freelancer->id}/documents/{$doc->id}")
            ->assertStatus(401);
    }

    // ─── Destroy freelancer cleans up files ──────────────────────────

    public function test_destroying_freelancer_cleans_up_files(): void
    {
        Storage::disk('public')->put('profile_images/john-doe.png', 'img');
        Storage::disk('public')->put('documents/john-doe-abc.pdf', 'doc');

        $this->freelancer->update(['profile_image' => 'profile_images/john-doe.png']);
        FreelancerDocument::create([
            'freelancer_id' => $this->freelancer->id,
            'file_path' => 'documents/john-doe-abc.pdf',
            'file_type' => 'pdf',
            'original_name' => 'resume.pdf',
        ]);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->deleteJson("/api/freelancers/{$this->freelancer->id}")
            ->assertStatus(200);

        Storage::disk('public')->assertMissing('profile_images/john-doe.png');
        Storage::disk('public')->assertMissing('documents/john-doe-abc.pdf');
    }

    // ─── Resource URL format (single /storage/ prefix) ───────────────

    public function test_resource_returns_single_storage_prefix_url(): void
    {
        $this->freelancer->update(['profile_image' => 'profile_images/john-doe.png']);

        $response = $this->getJson("/api/freelancers/{$this->freelancer->id}");

        $response->assertStatus(200);
        $url = $response->json('data.profile_image_url');
        $this->assertStringContainsString('/storage/profile_images/john-doe.png', $url);
        $this->assertStringNotContainsString('/storage//storage/', $url);
    }

    public function test_resource_normalizes_legacy_storage_prefixed_paths(): void
    {
        $this->freelancer->update(['profile_image' => '/storage/profile_images/john-doe.png']);

        $response = $this->getJson("/api/freelancers/{$this->freelancer->id}");

        $response->assertStatus(200);
        $url = $response->json('data.profile_image_url');
        $this->assertStringNotContainsString('/storage//storage/', $url);
        $this->assertStringContainsString('/storage/profile_images/john-doe.png', $url);
    }
}
