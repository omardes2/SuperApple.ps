<?php

namespace Tests\Feature\Production;

use App\Contracts\WhatsAppProvider;
use App\Enums\RoleName;
use App\Livewire\Admin\WhatsAppDashboard;
use App\Services\Settings;
use App\Services\WhatsApp\MetaCloudWhatsAppProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The Meta WhatsApp Cloud API driver: builds the correct Graph API request,
 * parses the returned message id, surfaces API errors as a failed result
 * (never throws secrets), and is refused cleanly when unconfigured. All network
 * is faked — no real call ever leaves the test.
 */
class WhatsAppMetaCloudTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private function configure(): void
    {
        $s = app(Settings::class);
        $s->set('whatsapp', 'provider', 'meta_cloud', 'string');
        $s->set('whatsapp', 'meta_phone_number_id', '100000000000001', 'string');
        $s->set('whatsapp', 'meta_access_token', 'EAAG-test-token', 'string');
        $s->set('whatsapp', 'meta_api_version', 'v21.0', 'string');
    }

    private function driver(): MetaCloudWhatsAppProvider
    {
        return new MetaCloudWhatsAppProvider(app(Settings::class));
    }

    public function test_key_is_meta_cloud(): void
    {
        $this->assertSame('meta_cloud', $this->driver()->key());
    }

    public function test_send_text_posts_to_graph_api_and_parses_id(): void
    {
        $this->configure();
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ABC123']]], 200),
        ]);

        $result = $this->driver()->sendText('+970599254870', 'مرحباً');

        $this->assertTrue($result->ok);
        $this->assertSame('wamid.ABC123', $result->providerMessageId);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'graph.facebook.com/v21.0/100000000000001/messages')
                && $request->hasHeader('Authorization', 'Bearer EAAG-test-token')
                && $request['to'] === '970599254870'      // digits only, no +
                && $request['type'] === 'text'
                && $request['text']['body'] === 'مرحباً';
        });
    }

    public function test_api_error_returns_failed_result_with_message(): void
    {
        $this->configure();
        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth token']], 401),
        ]);

        $result = $this->driver()->sendText('970599254870', 'x');

        $this->assertFalse($result->ok);
        $this->assertSame('Invalid OAuth token', $result->error);
        $this->assertNull($result->providerMessageId);
    }

    public function test_missing_credentials_fail_without_calling_the_api(): void
    {
        Http::fake(); // any real call would be recorded
        // No settings configured → no phone id / token.

        $result = $this->driver()->sendText('970599254870', 'x');

        $this->assertFalse($result->ok);
        Http::assertNothingSent();
    }

    public function test_send_document_uploads_media_then_sends_message(): void
    {
        $this->configure();
        $path = tempnam(sys_get_temp_dir(), 'inv').'.pdf';
        file_put_contents($path, '%PDF-1.4 test');

        Http::fake([
            'graph.facebook.com/*/media' => Http::response(['id' => 'MEDIA-1'], 200),
            'graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.DOC']]], 200),
        ]);

        $result = $this->driver()->sendDocument('970599254870', 'فاتورتك', $path, 'INV-1.pdf');
        @unlink($path);

        $this->assertTrue($result->ok);
        $this->assertSame('wamid.DOC', $result->providerMessageId);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/media'));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/messages')
            && $r['type'] === 'document' && $r['document']['id'] === 'MEDIA-1');
    }

    public function test_get_message_status_is_unknown(): void
    {
        $this->configure();
        $this->assertNull($this->driver()->getMessageStatus('wamid.ABC'));
    }

    public function test_container_resolves_meta_cloud_driver_from_settings(): void
    {
        $this->configure();
        $this->assertInstanceOf(MetaCloudWhatsAppProvider::class, app(WhatsAppProvider::class));
    }

    // ---------------------------------------------------------- Settings UI

    public function test_settings_save_persists_credentials_and_token_is_write_only(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));

        Livewire::test(WhatsAppDashboard::class)
            ->set('provider', 'meta_cloud')
            ->set('enabled', true)
            ->set('metaPhoneNumberId', '100000000000001')
            ->set('metaAccessToken', 'EAAG-secret')
            ->call('saveSettings')
            ->assertHasNoErrors();

        $s = app(Settings::class);
        $this->assertSame('meta_cloud', $s->get('whatsapp', 'provider'));
        $this->assertSame('100000000000001', $s->get('whatsapp', 'meta_phone_number_id'));
        $this->assertSame('EAAG-secret', $s->get('whatsapp', 'meta_access_token'));

        // Re-open: the token field is blank (write-only) but marked as set, and
        // saving again without retyping keeps the stored token.
        Livewire::test(WhatsAppDashboard::class)
            ->assertSet('metaAccessToken', '')
            ->assertSet('metaTokenSet', true)
            ->set('metaPhoneNumberId', '999')
            ->call('saveSettings')
            ->assertHasNoErrors();

        $this->assertSame('EAAG-secret', app(Settings::class)->get('whatsapp', 'meta_access_token'));
        $this->assertSame('999', app(Settings::class)->get('whatsapp', 'meta_phone_number_id'));
    }

    public function test_enabling_meta_cloud_without_credentials_is_rejected(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));

        Livewire::test(WhatsAppDashboard::class)
            ->set('provider', 'meta_cloud')
            ->set('enabled', true)
            ->set('metaPhoneNumberId', '')
            ->call('saveSettings')
            ->assertHasErrors('metaPhoneNumberId');
    }
}
