<?php

use Illuminate\Support\Facades\Schedule;

// Draait in de scheduler-container (php artisan schedule:work). Zet alleen opdrachten klaar;
// de signer voert ze uit.
Schedule::command('ocm:queue-checks')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('ocm:queue-checks --inventory')->dailyAt('03:15')->withoutOverlapping();
Schedule::command('ocm:cleanup')->hourly();
