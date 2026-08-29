<?php

namespace App\Console\Commands;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the first (or an additional) Super Admin account for production —
 * safely and interactively. The password is read with a hidden prompt and is
 * never echoed, passed as an argument, or written to logs.
 *
 *   php artisan app:create-admin
 *   php artisan app:create-admin --name="..." --email="..."   (password still prompted)
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'app:create-admin
        {--name= : Full name (prompted if omitted)}
        {--email= : Login email (prompted if omitted)}
        {--phone= : Optional phone}
        {--role= : Role to assign (defaults to Super Admin)}';

    protected $description = 'Create a Super Admin (or other role) user securely for production.';

    public function handle(): int
    {
        // The role must exist — run the RolePermissionSeeder first in production.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $roleName = $this->option('role') ?: RoleName::SuperAdmin->value;
        if (! Role::where('name', $roleName)->exists()) {
            $this->error("الدور [{$roleName}] غير موجود. شغّل: php artisan db:seed --class=RolePermissionSeeder أولاً.");

            return self::FAILURE;
        }

        $name = $this->option('name') ?: $this->ask('الاسم الكامل');
        $email = $this->option('email') ?: $this->ask('البريد الإلكتروني (اسم الدخول)');
        $phone = $this->option('phone');

        // Hidden prompts — the password never appears on screen or in history.
        $password = $this->secret('كلمة المرور');
        $confirm = $this->secret('تأكيد كلمة المرور');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirm],
            [
                'name' => 'required|string|max:150',
                'email' => 'required|email|unique:users,email',
                'password' => 'required|string|min:10|confirmed',
            ],
            ['password.confirmed' => 'كلمتا المرور غير متطابقتين.', 'password.min' => 'كلمة المرور يجب ألا تقل عن 10 أحرف.'],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'phone' => $phone ?: null,
            'password' => Hash::make($password),
            'is_active' => true,
            'locale' => 'ar',
            'email_verified_at' => now(),
        ]);
        $user->syncRoles([$roleName]);

        // Never log or echo the password — only confirm the account.
        $this->info("تم إنشاء المستخدم [{$user->email}] بدور [{$roleName}].");

        return self::SUCCESS;
    }
}
