<?php

namespace Tests\Feature;

use App\Models\Employer;
use App\Models\Freelancer;
use App\Notifications\AccountReactivated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * In-app notifications are private to the account they belong to. Every
 * NotificationController action is scoped to the authenticated notifiable, so
 * one account can never list, read or delete another account's notifications —
 * even across the freelancer/employer tables that share an id space.
 */
class NotificationOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifications_require_authentication(): void
    {
        $this->getJson('/api/notifications')->assertStatus(401);
    }

    public function test_user_only_sees_their_own_notifications(): void
    {
        $me = Freelancer::factory()->create();
        $other = Freelancer::factory()->create();
        $me->notify(new AccountReactivated());
        $other->notify(new AccountReactivated());

        Sanctum::actingAs($me);

        $res = $this->getJson('/api/notifications')->assertStatus(200);
        $this->assertSame(1, $res->json('data.total'));
        $this->assertSame(1, $res->json('unread_count'));
    }

    public function test_user_cannot_mark_read_another_accounts_notification(): void
    {
        $attacker = Freelancer::factory()->create();
        $victim = Employer::factory()->create();
        $victim->notify(new AccountReactivated());
        $victimNotificationId = $victim->notifications()->first()->id;

        Sanctum::actingAs($attacker);

        $this->postJson("/api/notifications/{$victimNotificationId}/read")->assertStatus(404);
        $this->assertSame(1, $victim->unreadNotifications()->count()); // still unread
    }

    public function test_user_cannot_delete_another_accounts_notification(): void
    {
        $attacker = Freelancer::factory()->create();
        $victim = Employer::factory()->create();
        $victim->notify(new AccountReactivated());
        $victimNotificationId = $victim->notifications()->first()->id;

        Sanctum::actingAs($attacker);

        $this->deleteJson("/api/notifications/{$victimNotificationId}")->assertStatus(404);
        $this->assertDatabaseHas('notifications', ['id' => $victimNotificationId]);
    }
}
