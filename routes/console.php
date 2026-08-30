<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Subscriptions were retired — no automatic recurring invoicing. (The
// subscriptions:bill command and its tables remain as legacy but are no longer
// scheduled, so no invoice is ever created automatically.)

// Payment reminders: evaluate the active reminder rules once a day.
Schedule::command('payments:send-reminders')->dailyAt('09:00')->withoutOverlapping();
