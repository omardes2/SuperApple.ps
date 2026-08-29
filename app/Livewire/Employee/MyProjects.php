<?php

namespace App\Livewire\Employee;

use App\Models\Project;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.employee')]
#[Title('مشاريعي')]
class MyProjects extends Component
{
    use WithPagination;

    public function mount(): void
    {
        $this->authorize('projects.view_assigned');
    }

    public function render()
    {
        $projects = Project::query()
            ->visibleTo(Auth::user())
            ->with('customer')
            ->withCount('tasks')
            ->latest()
            ->paginate(12);

        return view('livewire.employee.my-projects', ['projects' => $projects]);
    }
}
