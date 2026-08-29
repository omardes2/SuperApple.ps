<?php

namespace App\Livewire\Admin;

use App\Models\Employee;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Role;

#[Layout('layouts.app')]
#[Title('المستخدمون')]
class UsersIndex extends Component
{
    use WithPagination;

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public ?int $employee_id = null;

    public string $role = '';

    public bool $is_active = true;

    /** @var list<string> */
    public array $directPermissions = [];

    public bool $showDirect = false;

    public function mount(): void
    {
        $this->authorize('users.view');
    }

    public function openCreate(): void
    {
        $this->authorize('users.manage');
        $this->reset(['editingId', 'name', 'email', 'password', 'employee_id', 'role', 'directPermissions', 'showDirect']);
        $this->is_active = true;
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('users.manage');
        $u = User::findOrFail($id);
        $this->editingId = $u->id;
        $this->name = $u->name;
        $this->email = $u->email;
        $this->password = '';
        $this->employee_id = $u->employee_id;
        $this->role = $u->getRoleNames()->first() ?? '';
        $this->is_active = $u->is_active;
        $this->directPermissions = $u->getDirectPermissions()->pluck('name')->all();
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorize('users.manage');
        $this->validate([
            'name' => 'required|string|max:150',
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($this->editingId)],
            'password' => $this->editingId ? 'nullable|string|min:8' : 'required|string|min:8',
            'role' => 'required|string|exists:roles,name',
            'employee_id' => 'nullable|integer|exists:employees,id',
        ]);

        if ($this->editingId) {
            $u = User::findOrFail($this->editingId);
            $u->update([
                'name' => $this->name,
                'email' => $this->email,
                'employee_id' => $this->employee_id ?: null,
                'is_active' => $this->is_active,
            ]);
            if ($this->password !== '') {
                $u->update(['password' => Hash::make($this->password)]);
            }
        } else {
            $u = User::create([
                'name' => $this->name,
                'email' => $this->email,
                'password' => Hash::make($this->password),
                'employee_id' => $this->employee_id ?: null,
                'is_active' => $this->is_active,
                'locale' => 'ar',
            ]);
        }

        $u->syncRoles([$this->role]);
        // Direct permissions are advanced; only Super Admin may set them.
        if (auth()->user()->can('roles.manage')) {
            $u->syncPermissions($this->directPermissions);
        }

        $this->showForm = false;
        session()->flash('status', 'تم حفظ المستخدم.');
    }

    public function toggleActive(int $id): void
    {
        $this->authorize('users.manage');
        $u = User::findOrFail($id);
        // A deactivated user cannot log in, but their history/created_by stays.
        $u->update(['is_active' => ! $u->is_active]);
        session()->flash('status', $u->is_active ? 'تم تفعيل المستخدم.' : 'تم تعطيل المستخدم.');
    }

    public function render()
    {
        return view('livewire.admin.users-index', [
            'users' => User::with('roles', 'employee')->orderBy('name')->paginate(20),
            'roles' => Role::orderBy('name')->pluck('name'),
            'employees' => Employee::active()->orderBy('full_name')->get(['id', 'full_name']),
            'permissionCatalog' => Permissions::catalog(),
            'canManage' => auth()->user()->can('users.manage'),
            'canDirect' => auth()->user()->can('roles.manage'),
        ]);
    }
}
