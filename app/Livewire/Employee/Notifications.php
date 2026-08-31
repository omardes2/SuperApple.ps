<?php

namespace App\Livewire\Employee;

use App\Livewire\Admin\NotificationCenter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

/**
 * The employee notification centre. Reuses the shared, permission-aware
 * NotificationCenter logic (it only ever shows the current user's own
 * notifications and hides categories they cannot see) but renders inside the
 * unified employee shell.
 */
#[Layout('layouts.employee')]
#[Title('الإشعارات')]
class Notifications extends NotificationCenter {}
