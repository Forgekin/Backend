<?php

namespace Tests\Feature;

use App\Models\Employer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmployerProfileFieldsTest extends TestCase
{
    use RefreshDatabase;

    private Employer $employer;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->employer = Employer::factory()->active()->create([
            'company_name' => 'TechVision Solutions',
            'first_name' => 'Leslie',
            'last_name' => 'Brown',
        ]);
        $this->token = $this->employer->createToken('test')->plainTextToken;
    }

    public function test_employer_can_update_extended_profile_fields(): void
    {
        $payload = [
            'industry' => 'Technology & Software',
            'company_size' => '50-200 employees',
            'location' => 'Accra, Ghana',
            'website' => 'www.techvision.com',
            'founded' => '2018',
            'about' => 'TechVision Solutions is a leading technology company.',
            'specialties' => ['Web Development', 'Mobile Apps', 'UI/UX Design'],
        ];

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/employers/{$this->employer->id}", $payload);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $fresh = $this->employer->fresh();
        $this->assertSame('Technology & Software', $fresh->industry);
        $this->assertSame('50-200 employees', $fresh->company_size);
        $this->assertSame('Accra, Ghana', $fresh->location);
        $this->assertSame('2018', $fresh->founded);
        $this->assertSame(
            ['Web Development', 'Mobile Apps', 'UI/UX Design'],
            $fresh->specialties
        );
    }

    public function test_specialties_round_trip_through_json_column(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/employers/{$this->employer->id}", [
                'specialties' => ['Design', 'Marketing'],
            ])->assertStatus(200);

        $response = $this->getJson("/api/employers/{$this->employer->id}");
        $response->assertStatus(200);
        $this->assertSame(['Design', 'Marketing'], $response->json('data.specialties'));
    }

    public function test_founded_must_be_four_digit_year(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/employers/{$this->employer->id}", [
                'founded' => '20',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('founded');
    }

    public function test_specialty_entries_must_be_strings_under_100_chars(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/employers/{$this->employer->id}", [
                'specialties' => [str_repeat('x', 200)],
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('specialties.0');
    }

    // ─── Logo: multipart ─────────────────────────────────────────────

    public function test_company_logo_multipart_upload_uses_company_slug(): void
    {
        $file = UploadedFile::fake()->image('random.png');

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->post("/api/employers/{$this->employer->id}", [
                '_method' => 'PUT',
                'company_logo' => $file,
            ]);

        $response->assertStatus(200);

        Storage::disk('public')->assertExists('company_logos/techvision-solutions.png');
        $this->assertSame(
            'company_logos/techvision-solutions.png',
            $this->employer->fresh()->company_logo
        );
    }

    public function test_logo_multipart_rejects_disallowed_extension(): void
    {
        $file = UploadedFile::fake()->create('virus.exe', 100);

        $response = $this->withHeaders([
                'Authorization' => "Bearer {$this->token}",
                'Accept' => 'application/json',
            ])
            ->post("/api/employers/{$this->employer->id}", [
                '_method' => 'PUT',
                'company_logo' => $file,
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('company_logo');
    }

    // ─── Logo: base64 ────────────────────────────────────────────────

    public function test_company_logo_base64_upload_decodes_and_saves(): void
    {
        $pngBinary = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
        );
        $base64 = 'data:image/png;base64,' . base64_encode($pngBinary);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/employers/{$this->employer->id}", [
                'company_logo' => $base64,
            ]);

        $response->assertStatus(200);

        Storage::disk('public')->assertExists('company_logos/techvision-solutions.png');
        $this->assertSame(
            $pngBinary,
            Storage::disk('public')->get('company_logos/techvision-solutions.png')
        );
    }

    public function test_invalid_base64_data_uri_is_rejected(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/employers/{$this->employer->id}", [
                'company_logo' => 'not-a-data-uri',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('company_logo');
    }

    public function test_sending_null_logo_clears_existing_logo(): void
    {
        Storage::disk('public')->put('company_logos/techvision-solutions.png', 'img');
        $this->employer->update(['company_logo' => 'company_logos/techvision-solutions.png']);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/employers/{$this->employer->id}", [
                'company_logo' => null,
            ]);

        $response->assertStatus(200);
        $this->assertNull($this->employer->fresh()->company_logo);
        Storage::disk('public')->assertMissing('company_logos/techvision-solutions.png');
    }

    public function test_response_includes_company_logo_url(): void
    {
        $this->employer->update(['company_logo' => 'company_logos/techvision-solutions.png']);

        $response = $this->getJson("/api/employers/{$this->employer->id}");

        $response->assertStatus(200);
        $url = $response->json('data.company_logo_url');
        $this->assertStringContainsString('/storage/company_logos/techvision-solutions.png', $url);
        $this->assertStringNotContainsString('/storage//storage/', $url);
    }
}
