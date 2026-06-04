<?php

namespace Tests\Feature\PlatformModule;

use App\DataObjects\RequestObjects\PlatformSupportTicketCreate;
use App\DataObjects\RequestObjects\PlatformSupportTicketReply;
use App\Events\PlatformSupportTicketCreated;
use App\Events\PlatformSupportTicketMessageCreated;
use App\Events\PlatformSupportTicketStatusChanged;
use App\Models\PlatformModule\PlatformAdmin;
use App\Models\PlatformModule\PlatformSupportTicket;
use App\Models\PlatformModule\PlatformUser;
use App\Services\PlatformModule\PlatformSupportTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlatformSupportTicketTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_user_can_create_pending_ticket_with_attachments(): void
    {
        Storage::fake('public');
        $user = $this->platformUser('owner@example.com');

        $response = $this->actingAs($user, 'platformuser')->post(route('platform.customer-service.store'), [
            'subject' => 'Cannot open dashboard',
            'type' => 'bugs',
            'message' => 'The dashboard shows an error.',
            'attachments' => [
                UploadedFile::fake()->image('error.png'),
                UploadedFile::fake()->create('notes.txt', 4, 'text/plain'),
            ],
        ]);

        $ticket = PlatformSupportTicket::query()->first();

        $response->assertRedirect(route('platform.customer-service.show', $ticket->id));
        $this->assertDatabaseHas('platform_support_tickets', [
            'platform_user_id' => $user->id,
            'subject' => 'Cannot open dashboard',
            'type' => 'bugs',
            'status' => 'pending',
        ]);
        $this->assertDatabaseCount('platform_support_ticket_messages', 1);
        $this->assertDatabaseCount('platform_support_ticket_attachments', 2);
    }

    public function test_platform_user_cannot_view_another_users_ticket(): void
    {
        $owner = $this->platformUser('owner@example.com');
        $other = $this->platformUser('other@example.com', 'PU0000002');
        $ticket = $this->ticketFor($owner);

        $response = $this->actingAs($other, 'platformuser')->get(route('platform.customer-service.show', $ticket->id));

        $response->assertStatus(403);
    }

    public function test_admin_reply_opens_pending_ticket(): void
    {
        $user = $this->platformUser('owner@example.com');
        $admin = $this->platformAdmin();
        $ticket = $this->ticketFor($user);

        $response = $this->actingAs($admin, 'platformadmin')->post(route('admin.issued-tickets.messages.store', $ticket->id), [
            'message' => 'We are checking this now.',
        ]);

        $response->assertRedirect(route('admin.issued-tickets.show', $ticket->id));
        $this->assertDatabaseHas('platform_support_tickets', [
            'id' => $ticket->id,
            'status' => 'open',
            'user_unread_replies_count' => 1,
        ]);
        $this->assertDatabaseHas('platform_support_ticket_messages', [
            'platform_support_ticket_id' => $ticket->id,
            'sender_type' => 'platform_admin',
            'platform_admin_id' => $admin->id,
            'message' => 'We are checking this now.',
        ]);
    }

    public function test_admin_can_resolve_pending_ticket_and_user_cannot_reply_after_resolution(): void
    {
        $user = $this->platformUser('owner@example.com');
        $admin = $this->platformAdmin();
        $ticket = $this->ticketFor($user);

        $this->actingAs($admin, 'platformadmin')
            ->post(route('admin.issued-tickets.resolve', $ticket->id))
            ->assertRedirect(route('admin.issued-tickets.show', $ticket->id));

        $this->assertDatabaseHas('platform_support_tickets', [
            'id' => $ticket->id,
            'status' => 'resolved',
            'resolved_by' => $admin->id,
        ]);

        $this->actingAs($user, 'platformuser')
            ->post(route('platform.customer-service.messages.store', $ticket->id), [
                'message' => 'I still need help.',
            ])
            ->assertStatus(422);
    }

    public function test_ticket_owner_viewing_detail_resets_unread_reply_count(): void
    {
        $user = $this->platformUser('owner@example.com');
        $admin = $this->platformAdmin();
        $ticket = $this->ticketFor($user);

        $this->actingAs($admin, 'platformadmin')->post(route('admin.issued-tickets.messages.store', $ticket->id), [
            'message' => 'Unread admin reply.',
        ]);

        $this->assertDatabaseHas('platform_support_tickets', [
            'id' => $ticket->id,
            'user_unread_replies_count' => 1,
        ]);

        $this->actingAs($user, 'platformuser')
            ->get(route('platform.customer-service.show', $ticket->id))
            ->assertOk();

        $this->assertDatabaseHas('platform_support_tickets', [
            'id' => $ticket->id,
            'user_unread_replies_count' => 0,
        ]);
    }

    public function test_support_ticket_events_are_dispatched_for_create_reply_and_status_changes(): void
    {
        Event::fake([
            PlatformSupportTicketCreated::class,
            PlatformSupportTicketMessageCreated::class,
            PlatformSupportTicketStatusChanged::class,
        ]);

        $user = $this->platformUser('owner@example.com');
        $admin = $this->platformAdmin();

        $this->actingAs($user, 'platformuser');
        $ticket = app(PlatformSupportTicketService::class)->createForCurrentPlatformUser(new PlatformSupportTicketCreate(
            subject: 'Broadcast test',
            type: 'support',
            message: 'Initial message.',
        ));

        Event::assertDispatched(PlatformSupportTicketCreated::class);

        app(PlatformSupportTicketService::class)->replyAsPlatformUser(new PlatformSupportTicketReply(
            ticketId: $ticket->id,
            message: 'User reply.',
        ));

        Event::assertDispatched(PlatformSupportTicketMessageCreated::class, function (PlatformSupportTicketMessageCreated $event): bool {
            return $event->recipientType === 'admin'
                && $event->message->sender_type === 'platform_user';
        });

        $this->actingAs($admin, 'platformadmin');
        app(PlatformSupportTicketService::class)->replyAsAdmin(new PlatformSupportTicketReply(
            ticketId: $ticket->id,
            message: 'Admin reply.',
        ));

        Event::assertDispatched(PlatformSupportTicketStatusChanged::class);
        Event::assertDispatched(PlatformSupportTicketMessageCreated::class, function (PlatformSupportTicketMessageCreated $event): bool {
            return $event->recipientType === 'platform_user'
                && $event->message->sender_type === 'platform_admin';
        });
    }

    public function test_private_broadcast_channels_are_guard_and_owner_scoped(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        app()->forgetInstance('broadcast.manager');
        require base_path('routes/channels.php');

        $owner = $this->platformUser('owner@example.com');
        $other = $this->platformUser('other@example.com', 'PU0000002');
        $admin = $this->platformAdmin();
        $ticket = $this->ticketFor($owner);

        Auth::guard('platformuser')->logout();
        $this->actingAs($admin, 'platformadmin')
            ->post('/broadcasting/auth', [
                'socket_id' => '123.456',
                'channel_name' => 'private-platform.admin.issued-tickets',
            ])
            ->assertOk();

        Auth::guard('platformadmin')->logout();
        $this->actingAs($owner, 'platformuser')
            ->post('/broadcasting/auth', [
                'socket_id' => '123.456',
                'channel_name' => 'private-platform.admin.issued-tickets',
            ])
            ->assertForbidden();

        Auth::guard('platformadmin')->logout();
        $this->actingAs($owner, 'platformuser')
            ->post('/broadcasting/auth', [
                'socket_id' => '123.456',
                'channel_name' => 'private-platform.user.'.$owner->id.'.customer-service',
            ])
            ->assertOk();

        Auth::guard('platformadmin')->logout();
        $this->actingAs($other, 'platformuser')
            ->post('/broadcasting/auth', [
                'socket_id' => '123.456',
                'channel_name' => 'private-platform.user.'.$owner->id.'.customer-service',
            ])
            ->assertForbidden();

        Auth::guard('platformadmin')->logout();
        $this->actingAs($owner, 'platformuser')
            ->post('/broadcasting/auth', [
                'socket_id' => '123.456',
                'channel_name' => 'private-platform.support-ticket.'.$ticket->id,
            ])
            ->assertOk();

        Auth::guard('platformuser')->logout();
        $this->actingAs($admin, 'platformadmin')
            ->post('/broadcasting/auth', [
                'socket_id' => '123.456',
                'channel_name' => 'private-platform.support-ticket.'.$ticket->id,
            ])
            ->assertOk();

        Auth::guard('platformadmin')->logout();
        $this->actingAs($other, 'platformuser')
            ->post('/broadcasting/auth', [
                'socket_id' => '123.456',
                'channel_name' => 'private-platform.support-ticket.'.$ticket->id,
            ])
            ->assertForbidden();
    }

    public function test_custom_500_page_contains_prefilled_customer_service_report_link(): void
    {
        $response = $this->get('/missing-page-for-error-view-context');
        $response->assertNotFound();

        $html = view('errors.500')->render();

        $this->assertStringContainsString(route('platform.customer-service.create'), $html);
        $this->assertStringContainsString('type=bugs', $html);
        $this->assertStringContainsString('Server%20error%20report', $html);
    }

    private function platformUser(string $email, string $code = 'PU0000001'): PlatformUser
    {
        return PlatformUser::query()->create([
            'code' => $code,
            'name' => 'Platform User',
            'email' => $email,
            'password' => Hash::make('Password@123'),
            'status' => 'active',
        ]);
    }

    private function platformAdmin(): PlatformAdmin
    {
        return PlatformAdmin::query()->create([
            'code' => 'PA0000001',
            'name' => 'Platform Admin',
            'username' => 'admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('Password@123'),
            'status' => 'active',
        ]);
    }

    private function ticketFor(PlatformUser $user): PlatformSupportTicket
    {
        $ticket = PlatformSupportTicket::query()->create([
            'code' => 'ST0000001',
            'platform_user_id' => $user->id,
            'subject' => 'Need help',
            'type' => 'support',
            'status' => 'pending',
        ]);

        $ticket->messages()->create([
            'sender_type' => 'platform_user',
            'platform_user_id' => $user->id,
            'message' => 'Please help.',
        ]);

        return $ticket;
    }
}
