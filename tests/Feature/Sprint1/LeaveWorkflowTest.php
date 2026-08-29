<?php

namespace Tests\Feature\Sprint1;

use App\Enums\LeaveStatus;
use App\Enums\RoleName;
use App\Livewire\Admin\LeavesIndex;
use App\Livewire\Employee\MyLeaves;
use App\Models\LeaveType;
use App\Notifications\LeaveRequestSubmitted;
use App\Notifications\LeaveStatusChanged;
use App\Services\LeaveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class LeaveWorkflowTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function leaveType(): LeaveType
    {
        return LeaveType::create([
            'name' => 'سنوية', 'code' => 'ANN', 'is_paid' => true,
            'requires_attachment' => false, 'is_active' => true,
        ]);
    }

    public function test_employee_cannot_open_the_leaves_management_screen(): void
    {
        [$user] = $this->makeStaff();

        Livewire::actingAs($user)->test(LeavesIndex::class)->assertForbidden();
        $this->assertFalse($user->can('leaves.approve'));
    }

    public function test_hr_receives_notification_when_leave_submitted(): void
    {
        Notification::fake();

        [$user, $employee] = $this->makeStaff();
        $hr = $this->makeUser(RoleName::HrManager);
        $this->actingAs($user);

        $sunday = Carbon::now()->next(Carbon::SUNDAY);
        app(LeaveService::class)->submit($employee, $this->leaveType(), $sunday, $sunday->copy()->addDay());

        Notification::assertSentTo($hr, LeaveRequestSubmitted::class);
    }

    public function test_hr_can_approve_via_component_and_employee_is_notified(): void
    {
        Notification::fake();

        [$user, $employee] = $this->makeStaff();
        $hr = $this->makeUser(RoleName::HrManager);

        $this->actingAs($user);
        $sunday = Carbon::now()->next(Carbon::SUNDAY);
        $request = app(LeaveService::class)->submit($employee, $this->leaveType(), $sunday, $sunday->copy()->addDay());

        Livewire::actingAs($hr)->test(LeavesIndex::class)
            ->call('openReview', $request->id, 'approve')
            ->set('reviewNotes', 'موافق')
            ->call('confirmReview')
            ->assertHasNoErrors();

        $this->assertSame(LeaveStatus::Approved, $request->fresh()->status);
        Notification::assertSentTo($user, LeaveStatusChanged::class);
    }

    public function test_employee_can_submit_leave_through_their_own_component(): void
    {
        [$user, $employee] = $this->makeStaff();
        $type = $this->leaveType();
        $sunday = Carbon::now()->next(Carbon::SUNDAY);

        Livewire::actingAs($user)->test(MyLeaves::class)
            ->call('create')
            ->set('leave_type_id', $type->id)
            ->set('start_date', $sunday->toDateString())
            ->set('end_date', $sunday->copy()->addDay()->toDateString())
            ->set('reason', 'سبب')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('leave_requests', [
            'employee_id' => $employee->id,
            'status' => LeaveStatus::Pending->value,
        ]);
    }
}
