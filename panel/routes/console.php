<?php

use Illuminate\Support\Facades\Schedule;

// Draait in de scheduler-container (php artisan schedule:work). Zet alleen opdrachten klaar;
// de signer voert ze uit.
Schedule::command('ocm:queue-checks')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('ocm:queue-checks --inventory')->dailyAt('03:15')->withoutOverlapping();
Schedule::command('ocm:cleanup')->hourly();
// PageSpeed draait in de scheduler zelf (alleen de publieke URL gaat naar Google; geen signer nodig).
Schedule::command('ocm:pagespeed')->everyFiveMinutes()->withoutOverlapping(30);
Schedule::command('ocm:monitor')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('ocm:visual')->everyMinute()->withoutOverlapping(15);
Schedule::command('ocm:queue-checks --users')->dailyAt('03:45')->withoutOverlapping();
