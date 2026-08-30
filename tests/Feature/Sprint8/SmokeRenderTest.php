<?php

namespace Tests\Feature\Sprint8;

use App\Enums\RoleName;
use Database\Seeders\WhatsAppSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class SmokeRenderTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->seed(WhatsAppSeeder::class);
    }

    public function test_sprint8_pages_render_for_super_admin(): void
    {
        $this->actingAs($this->makeUser(RoleName::SuperAdmin));

        foreach ([
            '/admin', '/admin/reports', '/admin/reports/ar-aging', '/admin/reports/customers',
            '/admin/reports/whatsapp',
            '/admin/notifications', '/admin/activity', '/admin/users', '/admin/roles',
            '/admin/production-readiness', '/admin/audit-log',
        ] as $url) {
            $this->get($url)->assertOk();
        }

        // Subscriptions module was retired — its report route no longer exists.
        $this->get('/admin/reports/subscriptions')->assertNotFound();
    }

    public function test_error_pages_exist(): void
    {
        // A missing admin route returns the styled 404.
        $this->actingAs($this->makeUser(RoleName::SuperAdmin))
            ->get('/admin/definitely-not-a-page')->assertNotFound();
    }

    public function test_production_readiness_is_super_admin_only(): void
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager))
            ->get('/admin/production-readiness')->assertForbidden();
    }

    public function test_roles_page_is_super_admin_only(): void
    {
        // GM lacks roles.manage → route middleware forbids.
        $this->actingAs($this->makeUser(RoleName::GeneralManager))
            ->get('/admin/roles')->assertForbidden();
    }
}
