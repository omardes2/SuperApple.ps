<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Livewire\Employee\MyTasks;
use App\Models\Employee;
use App\Models\Service;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskMemberService;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The employee "my tasks" list must always show the newest-created task first
 * (tasks.created_at DESC, id as tie-breaker), across every filter and for
 * participant tasks too, with no duplicates.
 */
class EmployeeTaskOrderingTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $user;

    private Employee $employee;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        [$this->user, $this->employee] = $this->makeStaff(RoleName::Employee);
        $this->actingAs($this->user);
    }

    private function service(): Service
    {
        $this->seq++;

        return Service::create([
            'service_code' => 'ORD-'.str_pad((string) $this->seq, 4, '0', STR_PAD_LEFT),
            'name' => 'خدمة '.$this->seq, 'category' => 'تصميم',
            'service_type' => 'custom', 'is_active' => true,
        ]);
    }

    /** Create a task assigned to $this->employee, with an explicit created_at. */
    private function task(string $title, string $createdAt, ?Employee $owner = null): Task
    {
        $task = app(TaskService::class)->create([
            'title' => $title,
            'customer_id' => $this->makeCustomer()->id,
            'service_ids' => [$this->service()->id],
            'primary_assignee_id' => ($owner ?? $this->employee)->id,
            'start_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'priority' => 'normal',
        ]);

        DB::table('tasks')->where('id', $task->id)->update(['created_at' => $createdAt]);

        return $task->fresh();
    }

    /** @return list<int> ordered task ids as the component would render them */
    private function renderedIds(string $filter = 'all'): array
    {
        return Livewire::test(MyTasks::class)
            ->set('filter', $filter)
            ->viewData('tasks')
            ->pluck('id')->all();
    }

    public function test_newest_created_task_is_first(): void
    {
        $old = $this->task('أقدم', '2026-08-30 10:00:00');
        $mid = $this->task('متوسطة', '2026-08-31 15:00:00');
        $new = $this->task('أحدث', '2026-08-31 17:00:00');

        $this->assertSame([$new->id, $mid->id, $old->id], $this->renderedIds());
    }

    public function test_older_task_appears_below_newer(): void
    {
        $older = $this->task('قديمة', '2026-08-29 09:00:00');
        $newer = $this->task('جديدة', '2026-08-31 09:00:00');

        $ids = $this->renderedIds();
        $this->assertLessThan(array_search($older->id, $ids, true), array_search($newer->id, $ids, true));
    }

    public function test_completed_filter_keeps_newest_first(): void
    {
        $c1 = $this->task('مكتملة قديمة', '2026-08-28 09:00:00');
        $c2 = $this->task('مكتملة أحدث', '2026-08-31 09:00:00');
        DB::table('tasks')->whereIn('id', [$c1->id, $c2->id])->update(['status' => 'completed', 'completed_at' => now()]);

        $this->assertSame([$c2->id, $c1->id], $this->renderedIds('completed'));
    }

    public function test_in_progress_filter_keeps_newest_first(): void
    {
        $a = $this->task('قيد أقدم', '2026-08-28 09:00:00');
        $b = $this->task('قيد أحدث', '2026-08-31 09:00:00');
        DB::table('tasks')->whereIn('id', [$a->id, $b->id])->update(['status' => 'in_progress']);

        $this->assertSame([$b->id, $a->id], $this->renderedIds('in_progress'));
    }

    public function test_today_filter_keeps_newest_first(): void
    {
        // Both due today, created at different times.
        $early = $this->task('اليوم مبكرة', now()->copy()->setTime(9, 0)->toDateTimeString());
        $late = $this->task('اليوم متأخرة', now()->copy()->setTime(17, 0)->toDateTimeString());
        DB::table('tasks')->whereIn('id', [$early->id, $late->id])->update(['due_date' => now()->toDateString()]);

        $this->assertSame([$late->id, $early->id], $this->renderedIds('today'));
    }

    public function test_participant_tasks_respect_newest_first(): void
    {
        [, $owner] = $this->makeStaff(RoleName::Employee);
        $ownerUser = $owner->user;

        // A task I own (older) and a task I merely participate in (newer).
        $mine = $this->task('مهمتي', '2026-08-30 09:00:00');

        $this->actingAs($ownerUser);
        $theirs = $this->task('مهمة زميل', '2026-08-31 09:00:00', owner: $owner);
        app(TaskMemberService::class)->addParticipant($theirs, $this->employee, $ownerUser);
        DB::table('tasks')->where('id', $theirs->id)->update(['created_at' => '2026-08-31 09:00:00']);

        $this->actingAs($this->user);
        $ids = $this->renderedIds();

        $this->assertContains($mine->id, $ids);
        $this->assertContains($theirs->id, $ids);
        // The participant task (newer) comes before my own (older).
        $this->assertLessThan(array_search($mine->id, $ids, true), array_search($theirs->id, $ids, true));
    }

    public function test_no_duplicate_tasks(): void
    {
        $this->task('أ', '2026-08-31 10:00:00');
        $this->task('ب', '2026-08-31 11:00:00');

        $ids = $this->renderedIds();
        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    public function test_same_created_at_breaks_tie_by_id_desc(): void
    {
        $first = $this->task('نفس الوقت 1', '2026-08-31 12:00:00');
        $second = $this->task('نفس الوقت 2', '2026-08-31 12:00:00');

        // Identical created_at → higher id (later insert) ranks first.
        $this->assertSame([$second->id, $first->id], $this->renderedIds());
    }
}
