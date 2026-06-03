<?php

namespace Tests\Feature;

use App\Models\Employer;
use App\Models\Freelancer;
use App\Models\Job;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * PII (email, phone, DOB) must be hidden on the public freelancer/employer
 * pages, and visible only to the profile owner, Super-Admins, Admins, or users
 * with the directory permission (employers.read).
 */
class ContactPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private function superAdminToken(): string
    {
        $role = Role::firstOrCreate(['name' => 'Super-Admin']);
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u->createToken('test')->plainTextToken;
    }

    private function permissionedToken(): string
    {
        Permission::firstOrCreate(['name' => 'employers.read']);
        $role = Role::firstOrCreate(['name' => 'Directory']);
        $role->givePermissionTo('employers.read');
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u->createToken('test')->plainTextToken;
    }

    // ─── Freelancers ─────────────────────────────────────────────────

    public function test_public_freelancer_list_hides_email_phone_and_dob(): void
    {
        $f = Freelancer::factory()->create(['email' => 'priv@example.com', 'contact' => '0551234567', 'dob' => '2000-01-01']);

        $row = collect($this->getJson('/api/freelancers')->json('data'))->firstWhere('id', $f->id);

        $this->assertNotNull($row);
        $this->assertArrayNotHasKey('email', $row);
        $this->assertArrayNotHasKey('contact', $row);
        $this->assertArrayNotHasKey('dob', $row);
        $this->assertArrayHasKey('age', $row); // derived, stays public
    }

    public function test_another_authenticated_freelancer_cannot_see_others_pii(): void
    {
        $owner = Freelancer::factory()->create(['email' => 'owner@example.com']);
        $other = Freelancer::factory()->create();
        Sanctum::actingAs($other);

        $data = $this->getJson("/api/freelancers/{$owner->id}")->json('data');
        $this->assertArrayNotHasKey('email', $data);
        $this->assertArrayNotHasKey('contact', $data);
    }

    public function test_freelancer_owner_sees_own_pii(): void
    {
        $f = Freelancer::factory()->create(['email' => 'me@example.com', 'dob' => '1995-05-05']);
        Sanctum::actingAs($f);

        $data = $this->getJson("/api/freelancers/{$f->id}")->json('data');
        $this->assertSame('me@example.com', $data['email']);
        $this->assertArrayHasKey('contact', $data);
        $this->assertArrayHasKey('dob', $data);
    }

    public function test_super_admin_sees_freelancer_pii(): void
    {
        $token = $this->superAdminToken();
        $f = Freelancer::factory()->create(['email' => 'priv@example.com']);

        $row = collect(
            $this->withHeader('Authorization', "Bearer $token")->getJson('/api/freelancers')->json('data')
        )->firstWhere('id', $f->id);

        $this->assertSame('priv@example.com', $row['email']);
        $this->assertArrayHasKey('contact', $row);
        $this->assertArrayHasKey('dob', $row);
    }

    // ─── Employers ───────────────────────────────────────────────────

    public function test_public_employer_page_hides_email_and_phone(): void
    {
        $e = Employer::factory()->create(['email' => 'biz@example.com', 'contact' => '0207654321']);

        $data = $this->getJson("/api/employers/{$e->id}")->json('data');
        $this->assertArrayNotHasKey('email', $data);
        $this->assertArrayNotHasKey('contact', $data);
    }

    public function test_user_with_directory_permission_sees_employer_pii(): void
    {
        $token = $this->permissionedToken();
        $e = Employer::factory()->create(['email' => 'biz@example.com']);

        $data = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/employers/{$e->id}")->json('data');

        $this->assertSame('biz@example.com', $data['email']);
        $this->assertArrayHasKey('contact', $data);
    }

    // ─── Job relations (employer + assigned freelancer) ──────────────

    public function test_public_job_hides_employer_and_freelancer_contacts(): void
    {
        $employer = Employer::factory()->create(['email' => 'biz@example.com', 'contact' => '0201112222']);
        $freelancer = Freelancer::factory()->create(['email' => 'free@example.com', 'contact' => '0553334444', 'dob' => '1990-02-02']);
        $job = Job::factory()->create(['employer_id' => $employer->id, 'assigned_freelancer_id' => $freelancer->id]);

        $data = $this->getJson("/api/jobs/{$job->id}")->json('data');
        $emp = $data['employer'];
        $fr = $data['assigned_freelancer'] ?? $data['assignedFreelancer'] ?? [];

        $this->assertArrayNotHasKey('email', $emp);
        $this->assertArrayNotHasKey('contact', $emp);
        $this->assertArrayNotHasKey('email', $fr);
        $this->assertArrayNotHasKey('contact', $fr);
        $this->assertArrayNotHasKey('dob', $fr);
    }

    public function test_super_admin_sees_job_relation_contacts(): void
    {
        $token = $this->superAdminToken();
        $employer = Employer::factory()->create(['email' => 'biz@example.com']);
        $freelancer = Freelancer::factory()->create(['email' => 'free@example.com']);
        $job = Job::factory()->create(['employer_id' => $employer->id, 'assigned_freelancer_id' => $freelancer->id]);

        $data = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/jobs/{$job->id}")->json('data');
        $fr = $data['assigned_freelancer'] ?? $data['assignedFreelancer'] ?? [];

        $this->assertSame('biz@example.com', $data['employer']['email']);
        $this->assertSame('free@example.com', $fr['email']);
    }
}
