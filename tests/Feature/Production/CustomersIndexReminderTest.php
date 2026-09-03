<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Livewire\Admin\CustomersIndex;
use App\Models\WhatsAppMessage;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The customers list can send a balance reminder over WhatsApp straight from
 * the row actions: the modal prefills an editable default body (company name +
 * amount) and sending records a message row linked to the customer, so it also
 * shows in that customer's conversation tab.
 */
class CustomersIndexReminderTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        app(Settings::class)->set('company', 'name', 'سوبر آبل', 'string');
    }

    public function test_open_reminder_prefills_body_and_shows_modal(): void
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        $customer = $this->makeCustomer(['name' => 'بلوتو براند', 'whatsapp_number' => '0599432037']);
        $this->makeIssuedInvoice($customer, '500', '3.20');

        Livewire::test(CustomersIndex::class)
            ->call('openReminder', $customer->id)
            ->assertSet('showReminder', true)
            // The body is bound via wire:model (not server-rendered in the
            // textarea), so assert on the component state directly.
            ->assertSet('reminderBody', fn ($v) => str_contains($v, 'بلوتو براند') && str_contains($v, 'سوبر آبل'));
    }

    public function test_send_reminder_records_a_message_for_the_customer(): void
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        $fake = $this->useFakeWhatsApp();
        $customer = $this->makeCustomer(['name' => 'بلوتو براند', 'whatsapp_number' => '0599432037']);
        $this->makeIssuedInvoice($customer, '500', '3.20');

        Livewire::test(CustomersIndex::class)
            ->call('openReminder', $customer->id)
            ->call('sendReminder')
            ->assertSet('showReminder', false)
            ->assertHasNoErrors();

        $this->assertSame(1, $fake->count());
        $this->assertDatabaseHas('whatsapp_messages', [
            'customer_id' => $customer->id,
            'direction' => WhatsAppMessage::DIRECTION_OUTBOUND,
        ]);
    }

    public function test_reminder_button_hidden_without_permission(): void
    {
        // A user who can view customers but cannot send WhatsApp.
        $role = Role::findOrCreate('عارض عملاء', 'web');
        $role->syncPermissions(['customers.view']);
        $user = $this->makeUser(RoleName::GeneralManager);
        $user->syncRoles(['عارض عملاء']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($user->fresh());

        $this->makeCustomer(['name' => 'عميل', 'whatsapp_number' => '0599432037']);

        // No whatsapp.send permission → the row action is not rendered.
        Livewire::test(CustomersIndex::class)
            ->assertDontSee('openReminder(');
    }
}
