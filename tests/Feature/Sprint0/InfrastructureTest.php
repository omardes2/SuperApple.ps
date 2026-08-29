<?php

namespace Tests\Feature\Sprint0;

use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\Concerns\Auditable;
use App\Services\AuditLogger;
use App\Services\DocumentNumberService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class InfrastructureTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();

        Schema::create('audit_fixtures', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('password')->nullable();
            $table->timestamps();
        });
    }

    public function test_document_numbers_increment_sequentially(): void
    {
        $service = app(DocumentNumberService::class);

        $year = date('Y');
        $this->assertSame("INV-{$year}-0001", $service->next('invoice'));
        $this->assertSame("INV-{$year}-0002", $service->next('invoice'));
        $this->assertSame('CUS-00001', $service->next('customer'));
        $this->assertSame('CUS-00002', $service->next('customer'));
        $this->assertSame('TSK-000001', $service->next('task'));
    }

    public function test_audit_logger_records_actions_with_context(): void
    {
        $user = $this->makeUser(RoleName::Accountant);
        $this->actingAs($user);

        app(AuditLogger::class)->log('test_action', $user, 'Testing', description: 'حدث تجريبي');

        $log = AuditLog::where('action', 'test_action')->first();
        $this->assertNotNull($log);
        $this->assertSame('Testing', $log->module);
        $this->assertSame($user->id, $log->user_id);
    }

    public function test_auditable_trait_records_model_lifecycle(): void
    {
        $user = $this->makeUser(RoleName::SuperAdmin);
        $this->actingAs($user);

        $model = AuditableFixture::create(['name' => 'first']);
        $model->update(['name' => 'second']);
        $model->delete();

        $this->assertDatabaseHas('audit_logs', ['action' => 'created', 'auditable_id' => $model->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'updated', 'auditable_id' => $model->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'deleted', 'auditable_id' => $model->id]);

        $updated = AuditLog::where('action', 'updated')
            ->where('auditable_id', $model->id)
            ->first();
        $this->assertSame(['name' => 'first'], $updated->old_values);
        $this->assertSame(['name' => 'second'], $updated->new_values);
    }

    public function test_audit_log_does_not_leak_password(): void
    {
        $user = $this->makeUser(RoleName::SuperAdmin);
        $this->actingAs($user);

        $created = AuditableFixture::create(['name' => 'x', 'password' => 'topsecret']);

        $log = AuditLog::where('action', 'created')
            ->where('auditable_id', $created->id)
            ->first();

        $this->assertArrayNotHasKey('password', $log->new_values ?? []);
    }
}

/**
 * Lightweight model used only to exercise the Auditable trait against a
 * throwaway table created in setUp(). `password` is hidden so we can assert
 * the audit trail never records secrets.
 */
class AuditableFixture extends Model
{
    use Auditable;

    protected $table = 'audit_fixtures';

    protected $guarded = [];

    protected $hidden = ['password'];
}
