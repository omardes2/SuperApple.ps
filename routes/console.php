<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Recurring invoices: bill every due subscription once a day. Idempotent —
// a period is never billed twice thanks to the subscription_billings unique key.
Schedule::command('subscriptions:bill')->dailyAt('02:00')->withoutOverlapping();

// Payment reminders: evaluate the active reminder rules once a day.
Schedule::command('payments:send-reminders')->dailyAt('09:00')->withoutOverlapping();
