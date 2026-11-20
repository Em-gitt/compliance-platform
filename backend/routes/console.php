<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('compliance:check-expirations')
    ->dailyAt('00:05')
    ->timezone('America/Mexico_City');

Schedule::command('alerts:generate')
    ->dailyAt('07:00')
    ->timezone('America/Mexico_City');
