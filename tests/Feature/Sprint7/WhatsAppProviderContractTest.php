<?php

namespace Tests\Feature\Sprint7;

use App\Contracts\WhatsAppProvider;
use App\Services\Settings;
use App\Services\WhatsApp\FakeWhatsAppProvider;
use App\Services\WhatsApp\LogWhatsAppProvider;
use App\Services\WhatsApp\NullWhatsAppProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppProviderContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_provider_sends_and_returns_a_result(): void
    {
        /** @var list<WhatsAppProvider> $providers */
        $providers = [new NullWhatsAppProvider, new LogWhatsAppProvider, new FakeWhatsAppProvider];
        foreach ($providers as $provider) {
            $result = $provider->sendText('+970591234567', 'مرحباً');
            $this->assertTrue($result->ok, $provider->key().' should send');
            $this->assertNotNull($result->providerMessageId);
            $this->assertNotSame('', $provider->key());
        }
    }

    public function test_container_binds_provider_from_settings(): void
    {
        app(Settings::class)->set('whatsapp', 'provider', 'fake', 'string');
        $this->assertInstanceOf(FakeWhatsAppProvider::class, app(WhatsAppProvider::class));

        app(Settings::class)->set('whatsapp', 'provider', 'null', 'string');
        $this->assertInstanceOf(NullWhatsAppProvider::class, app(WhatsAppProvider::class));
    }

    public function test_fake_provider_records_and_can_fail(): void
    {
        $fake = new FakeWhatsAppProvider;
        $fake->sendText('+970591234567', 'A');
        $this->assertSame(1, $fake->count());

        $fake->fail('boom');
        $this->expectException(\RuntimeException::class);
        $fake->sendText('+970591234567', 'B');
    }
}
