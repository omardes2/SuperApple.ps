<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Livewire\Admin\SettingsPage;
use App\Services\InvoicePdfService;
use App\Services\Settings;
use App\Support\CompanyProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The company logo is uploaded from Settings (raster only, real image
 * validation, ≤2 MB, manage-gated), stored on the local disk, and embedded on
 * the printed invoice as a base64 data URI — so it renders in the browser print
 * view and the offline PDF alike, and a missing file falls back to the default
 * mark without breaking the page.
 */
class InvoiceLogoTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    // 1×1 transparent PNG.
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
    }

    /** Full set of valid non-logo fields the settings form requires. */
    private function baseForm(Testable $c): Testable
    {
        return $c->set('companyName', 'Super Apple')
            ->set('workStart', '09:00')->set('workEnd', '17:00')->set('graceMinutes', 15)
            ->set('workingDays', ['sat', 'sun', 'mon', 'tue', 'wed', 'thu']);
    }

    private function seedLogoFile(string $path = 'company/logo.png'): string
    {
        Storage::fake('local');
        Storage::disk('local')->put($path, base64_decode(self::PNG));
        app(Settings::class)->set('company', 'logo_path', $path);

        return $path;
    }

    public function test_upload_stores_logo_and_sets_path(): void
    {
        Storage::fake('local');

        $this->baseForm(Livewire::test(SettingsPage::class))
            ->set('logo', UploadedFile::fake()->image('logo.png', 600, 132))
            ->call('save')
            ->assertHasNoErrors();

        $path = (string) app(Settings::class)->get('company', 'logo_path');
        $this->assertNotSame('', $path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_replacing_logo_deletes_the_old_file(): void
    {
        Storage::fake('local');

        $this->baseForm(Livewire::test(SettingsPage::class))
            ->set('logo', UploadedFile::fake()->image('one.png', 600, 132))
            ->call('save')->assertHasNoErrors();
        $first = (string) app(Settings::class)->get('company', 'logo_path');
        Storage::disk('local')->assertExists($first);

        $this->baseForm(Livewire::test(SettingsPage::class))
            ->set('logo', UploadedFile::fake()->image('two.png', 600, 132))
            ->call('save')->assertHasNoErrors();
        $second = (string) app(Settings::class)->get('company', 'logo_path');

        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);   // old file cleaned up
        Storage::disk('local')->assertExists($second);
    }

    public function test_remove_logo_clears_setting_and_file_and_falls_back(): void
    {
        $path = $this->seedLogoFile();

        Livewire::test(SettingsPage::class)->call('removeLogo');

        $this->assertSame('', (string) app(Settings::class)->get('company', 'logo_path'));
        Storage::disk('local')->assertMissing($path);
        // Fallback: with no logo the company profile exposes a null data URI.
        $this->assertNull(CompanyProfile::fromSettings(app(Settings::class))['logo_data_uri']);
    }

    public function test_invalid_file_type_is_rejected(): void
    {
        Storage::fake('local');

        $this->baseForm(Livewire::test(SettingsPage::class))
            ->set('logo', UploadedFile::fake()->create('malware.pdf', 20, 'application/pdf'))
            ->call('save')
            ->assertHasErrors('logo');

        $this->assertSame('', (string) app(Settings::class)->get('company', 'logo_path'));
    }

    public function test_oversized_file_is_rejected(): void
    {
        Storage::fake('local');

        $this->baseForm(Livewire::test(SettingsPage::class))
            ->set('logo', UploadedFile::fake()->image('big.png', 100, 100)->size(3000)) // 3 MB
            ->call('save')
            ->assertHasErrors('logo');
    }

    public function test_svg_is_rejected(): void
    {
        Storage::fake('local');

        $this->baseForm(Livewire::test(SettingsPage::class))
            ->set('logo', UploadedFile::fake()->create('logo.svg', 5, 'image/svg+xml'))
            ->call('save')
            ->assertHasErrors('logo');
    }

    public function test_user_without_manage_cannot_upload_or_remove(): void
    {
        $viewer = $this->makeUser(RoleName::Employee);
        $viewer->givePermissionTo('settings.view'); // can open the page, cannot manage
        $this->actingAs($viewer);

        $this->baseForm(Livewire::test(SettingsPage::class))
            ->set('logo', UploadedFile::fake()->image('logo.png', 600, 132))
            ->call('save')
            ->assertForbidden();

        Livewire::test(SettingsPage::class)->call('removeLogo')->assertForbidden();
    }

    public function test_company_profile_exposes_logo_data_uri(): void
    {
        $this->seedLogoFile();

        $company = CompanyProfile::fromSettings(app(Settings::class));

        $this->assertStringStartsWith('data:image/png;base64,', (string) $company['logo_data_uri']);
    }

    public function test_missing_logo_yields_null_data_uri(): void
    {
        Storage::fake('local');
        // A path is set but the file does not exist → null, never an exception.
        app(Settings::class)->set('company', 'logo_path', 'company/gone.png');

        $this->assertNull(CompanyProfile::fromSettings(app(Settings::class))['logo_data_uri']);
    }

    public function test_printed_invoice_embeds_the_logo(): void
    {
        $this->seedLogoFile();
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '100.00', '3.20');

        $this->get(route('admin.invoices.print', $invoice))
            ->assertOk()
            ->assertSee('data:image/png;base64', false);
    }

    public function test_pdf_renders_with_logo(): void
    {
        $this->seedLogoFile();
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '100.00', '3.20');

        $bytes = app(InvoicePdfService::class)->bytes($invoice->fresh());

        $this->assertStringStartsWith('%PDF', $bytes);
    }
}
