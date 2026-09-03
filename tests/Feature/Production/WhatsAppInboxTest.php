<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Enums\WhatsAppMessageStatus;
use App\Livewire\Admin\WhatsAppInbox;
use App\Models\Customer;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The central WhatsApp Inbox: lists inbound replies, highlights unread ones,
 * and mark-as-read (single + all) drives the unread count that feeds the red
 * sidebar badge. Outbound messages never appear here.
 */
class WhatsAppInboxTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
    }

    private function inbound(?Customer $customer, string $body, bool $read = false): WhatsAppMessage
    {
        return WhatsAppMessage::create([
            'customer_id' => $customer?->id,
            'phone' => '970599000000',
            'message_body' => $body,
            'provider' => 'meta_cloud',
            'direction' => WhatsAppMessage::DIRECTION_INBOUND,
            'status' => WhatsAppMessageStatus::Delivered,
            'admin_read_at' => $read ? now() : null,
        ]);
    }

    private function outbound(string $body): WhatsAppMessage
    {
        return WhatsAppMessage::create([
            'phone' => '970599000000',
            'message_body' => $body,
            'provider' => 'meta_cloud',
            'direction' => WhatsAppMessage::DIRECTION_OUTBOUND,
            'status' => WhatsAppMessageStatus::Sent,
        ]);
    }

    public function test_unread_inbox_count_ignores_read_and_outbound(): void
    {
        $c = $this->makeCustomer();
        $this->inbound($c, 'وارد غير مقروء');
        $this->inbound($c, 'وارد مقروء', read: true);
        $this->outbound('صادر');

        $this->assertSame(1, WhatsAppMessage::unreadInboxCount());
    }

    public function test_inbox_lists_inbound_only(): void
    {
        $c = $this->makeCustomer(['name' => 'بلوتو براند']);
        $this->inbound($c, 'رد الزبون هنا');
        $this->outbound('رسالة صادرة لا تظهر');

        Livewire::test(WhatsAppInbox::class)
            ->assertOk()
            ->assertSee('رد الزبون هنا')
            ->assertSee('بلوتو براند')
            ->assertDontSee('رسالة صادرة لا تظهر');
    }

    public function test_mark_read_clears_a_single_message(): void
    {
        $c = $this->makeCustomer();
        $m = $this->inbound($c, 'رسالة واحدة');

        Livewire::test(WhatsAppInbox::class)->call('markRead', $m->id);

        $this->assertNotNull($m->fresh()->admin_read_at);
        $this->assertSame(0, WhatsAppMessage::unreadInboxCount());
    }

    public function test_mark_all_read_clears_everything(): void
    {
        $c = $this->makeCustomer();
        $this->inbound($c, 'أ');
        $this->inbound($c, 'ب');
        $this->inbound($c, 'ج');
        $this->assertSame(3, WhatsAppMessage::unreadInboxCount());

        Livewire::test(WhatsAppInbox::class)->call('markAllRead');

        $this->assertSame(0, WhatsAppMessage::unreadInboxCount());
    }

    public function test_unread_filter_hides_read_messages(): void
    {
        $c = $this->makeCustomer();
        $this->inbound($c, 'غير مقروءة تظهر');
        $this->inbound($c, 'مقروءة مخفية', read: true);

        Livewire::test(WhatsAppInbox::class)
            ->assertSet('filter', 'unread')
            ->assertSee('غير مقروءة تظهر')
            ->assertDontSee('مقروءة مخفية')
            ->call('setFilter', 'all')
            ->assertSee('غير مقروءة تظهر')
            ->assertSee('مقروءة مخفية');
    }

    public function test_inbox_requires_permission(): void
    {
        $this->actingAs($this->makeUser(RoleName::Employee));

        Livewire::test(WhatsAppInbox::class)->assertForbidden();
    }
}
