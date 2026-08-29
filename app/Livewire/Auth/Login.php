<?php

namespace App\Livewire\Auth;

use App\Services\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.guest')]
class Login extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    public function login(AuditLogger $audit)
    {
        $credentials = $this->validate();

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password, 'is_active' => true], $this->remember)) {
            throw ValidationException::withMessages([
                'email' => 'بيانات الدخول غير صحيحة أو الحساب غير مفعّل.',
            ]);
        }

        session()->regenerate();

        $audit->log('login', Auth::user(), 'Auth', description: 'تسجيل دخول');

        return redirect()->intended(
            Auth::user()->usesAdminExperience()
                ? route('admin.dashboard')
                : route('employee.dashboard')
        );
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
