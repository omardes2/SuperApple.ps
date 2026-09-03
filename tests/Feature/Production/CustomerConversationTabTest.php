<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Enums\WhatsAppMessageStatus;
use App\Livewire\Admin\CustomerProfile;
use App\Models\Customer;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The customer profile "المراسلات" tab renders the WhatsApp thread as a chat:
 * inbound (customer) and outbound (us) messages are visually distinguished so a
 * reply can be read in context.
 */
class CustomerConversationTabTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
    }

    private function profile(Customer $customer)
    {
        return Livewire::actingAs($this->makeUser(RoleName::GeneralManager))
            ->test(CustomerProfile::class, ['customer' => $customer]);
    }

    private function message(Customer $customer, string $direction, string $body): WhatsAppMessage
    {
        return WhatsAppMessage::create([
            'customer_id' => $customer->id,
            'phone' => '970599000000',
            'message_body' => $body,
            'provider' => 'meta_cloud',
            'direction' => $direction,
            'status' => WhatsAppMessageStatus::Delivered,
        ]);
    }

    public function test_conversation_tab_shows_inbound_and_outbound_messages(): void
    {
        $customer = $this->makeCustomer(['whatsapp_number' => '0599000000']);
        $this->message($customer, WhatsAppMessage::DIRECTION_OUTBOUND, 'تذكير بالدفع من الشركة');
        $this->message($customer, WhatsAppMessage::DIRECTION_INBOUND, 'شكراً سيتم الدفع غداً');

        $this->profile($customer)
            ->call('setTab', 'communications')
            ->assertOk()
            ->assertSee('صادر')
            ->assertSee('وارد')
            ->assertSee('تذكير بالدفع من الشركة')
            ->assertSee('شكراً سيتم الدفع غداً');
    }

    public function test_conversation_tab_is_chronological_oldest_first(): void
    {
        $customer = $this->makeCustomer(['whatsapp_number' => '0599000000']);
        $older = $this->message($customer, WhatsAppMessage::DIRECTION_OUTBOUND, 'الرسالة الأقدم');
        $newer = $this->message($customer, WhatsAppMessage::DIRECTION_INBOUND, 'الرسالة الأحدث');

        $this->profile($customer)
            ->call('setTab', 'communications')
            ->assertViewHas('messages', function ($messages) use ($older, $newer) {
                return $messages->first()->id === $older->id
                    && $messages->last()->id === $newer->id;
            });
    }

    public function test_conversation_tab_empty_renders(): void
    {
        $this->profile($this->makeCustomer())
            ->call('setTab', 'communications')
            ->assertOk()
            ->assertSee('لا مراسلات بعد.');
    }
}
