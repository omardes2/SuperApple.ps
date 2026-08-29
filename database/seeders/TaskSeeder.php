<?php

namespace Database\Seeders;

use App\Enums\TaskStatus;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use App\Services\TaskWorkflowService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

class TaskSeeder extends Seeder
{
    public function run(): void
    {
        $gm = User::where('email', 'gm@superapple.ps')->first() ?? User::first();
        Auth::login($gm);

        $taskService = app(TaskService::class);
        $workflow = app(TaskWorkflowService::class);

        $projects = Project::with('members')->get();
        $titles = [
            'تصميم الشعار', 'إعداد دليل الهوية', 'تصميم منشورات أسبوعية', 'كتابة المحتوى',
            'إعداد خطة الحملة', 'تصميم صفحة الهبوط', 'برمجة الواجهة', 'تجهيز المتجر',
            'تصوير المنتجات', 'مونتاج الفيديو', 'مراجعة نهائية', 'تسليم الملفات',
        ];

        $counter = 0;

        foreach ($projects as $project) {
            $members = $project->members;
            $howMany = random_int(4, 7);

            for ($i = 0; $i < $howMany; $i++) {
                $counter++;
                $assignee = $members->isNotEmpty() ? $members->random() : Employee::inRandomOrder()->first();
                $number = 'TSK-'.str_pad((string) $counter, 6, '0', STR_PAD_LEFT);
                if (Task::where('task_number', $number)->exists()) {
                    continue;
                }

                $task = $taskService->create([
                    'task_number' => $number,
                    'title' => $titles[array_rand($titles)],
                    'description' => 'تفاصيل المهمة ضمن مشروع '.$project->name.'.',
                    'project_id' => $project->id,
                    'primary_assignee_id' => $assignee?->id,
                    'priority' => ['low', 'normal', 'high', 'urgent'][array_rand([0, 1, 2, 3])],
                    'start_date' => now()->subDays(random_int(1, 10))->toDateString(),
                    'due_date' => now()->addDays(random_int(-3, 15))->toDateString(),
                    'estimated_minutes' => random_int(60, 480),
                ]);

                // Checklist + a comment.
                $taskService->addChecklistItem($task, 'تجهيز المتطلبات');
                $taskService->addChecklistItem($task, 'التنفيذ');
                $taskService->addComment($task, 'تم البدء بالعمل على المهمة.');

                // Drive some tasks through the workflow (GM can do every step).
                $roll = random_int(1, 6);
                if ($roll >= 2) {
                    $workflow->transition($task, TaskStatus::InProgress, $gm);
                }
                if ($roll >= 4) {
                    $workflow->transition($task, TaskStatus::WaitingReview, $gm);
                }
                if ($roll === 5) {
                    $workflow->transition($task, TaskStatus::ChangesRequested, $gm, 'يرجى تعديل الألوان');
                    $workflow->transition($task, TaskStatus::InProgress, $gm);
                }
                if ($roll === 6) {
                    $workflow->transition($task, TaskStatus::Completed, $gm);
                }
            }
        }

        Auth::logout();
    }
}
