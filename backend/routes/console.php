<?php
use Illuminate\Support\Facades\Schedule;
Schedule::command('kridaya:expire-deals')->everyFiveMinutes();
Schedule::command('kridaya:remind-deadlines')->dailyAt('08:00');
