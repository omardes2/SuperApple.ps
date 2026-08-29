<?php

namespace App\Livewire\Auth;

use App\Services\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
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
        $this->validate();
        $this->ensureNotRateLimited();

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password, 'is_active' => true], $this->remember)) {
            RateLimiter::hit($this->throttleKey(), 60);
            throw ValidationException::withMessages([
                'email' => 'بيانات الدخول غير صحيحة أو الحساب غير مفعّل.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        session()->regenerate();

        // Record the successful login timestamp (Users management shows it).
        Auth::user()->forceFill(['last_login_at' => now()])->saveQuietly();

        $audit->log('login', Auth::user(), 'Auth', description: 'تسجيل دخول');

        return redirect()->intended(
            Auth::user()->usesAdminExperience()
                ? route('admin.dashboard')
                : route('employee.dashboard')
        );
    }

    /** Block brute-force: max 5 failed attempts per email+IP per minute. */
    private function ensureNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey());
        throw ValidationException::withMessages([
            'email' => "محاولات كثيرة. حاول مرة أخرى بعد {$seconds} ثانية.",
        ]);
    }

    private function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
