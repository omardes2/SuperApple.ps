<?php

namespace Tests\Feature\Sprint6;

use App\Enums\RoleName;
use App\Exceptions\PostedRecordImmutableException;
use App\Models\PayrollRun;
use App\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class PayrollWorkflowTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
    }

    private function service(): PayrollService
    {
        return app(PayrollService::class);
    }

    private function calculatedRun(): PayrollRun
    {
        $e = $this->makeEmployee();
        $this->makeSalaryProfile($e, '4000');
        $run = $this->makePayrollRun(2026, 8);
        $this->fillFullAttendance($e, $run);

        return $this->service()->calculate($run);
    }

    public function test_draft_can_calculate(): void
    {
        $run = $this->calculatedRun();
        $this->assertSame('calculated', $run->status->value);
        $this->assertSame(1, $run->items()->count());
    }

    public function test_calculated_can_recalculate(): void
    {
        $run = $this->calculatedRun();
        $this->service()->calculate($run->fresh());
        $this->assertSame('calculated', $run->fresh()->status->value);
    }

    public function test_calculated_can_approve(): void
    {
        $run = $this->calculatedRun();
        $this->service()->approve($run->fresh(), $this->makeUser(RoleName::GeneralManager));
        $this->assertSame('approved', $run->fresh()->status->value);
    }

    public function test_approved_cannot_recalculate(): void
    {
        $run = $this->calculatedRun();
        $this->service()->approve($run->fresh(), $this->makeUser(RoleName::GeneralManager));
        $this->expectException(RuntimeException::class);
        $this->service()->calculate($run->fresh());
    }

    public function test_approved_can_post(): void
    {
        $run = $this->calculatedRun();
        $this->service()->approve($run->fresh(), $this->makeUser(RoleName::GeneralManager));
        $this->service()->post($run->fresh());
        $this->assertSame('posted', $run->fresh()->status->value);
    }

    public function test_posted_run_is_immutable(): void
    {
        $run = $this->calculatedRun();
        $this->service()->approve($run->fresh(), $this->makeUser(RoleName::GeneralManager));
        $this->service()->post($run->fresh());

        $this->expectException(PostedRecordImmutableException::class);
        $run->fresh()->update(['period_start' => '2026-01-01']);
    }

    public function test_duplicate_posting_prevented(): void
    {
        $run = $this->calculatedRun();
        $this->service()->approve($run->fresh(), $this->makeUser(RoleName::GeneralManager));
        $this->service()->post($run->fresh());

        // A second post attempt is rejected (status no longer approved).
        $this->expectException(RuntimeException::class);
        $this->service()->post($run->fresh());
    }
}
