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
Schedule::command('ocm:backups')->everyMinute()->withoutOverlapping(10);
Schedule::command('ocm:vulnerabilities')->dailyAt('04:30')->withoutOverlapping(60);
Schedule::command('ocm:queue-checks --users')->dailyAt('03:45')->withoutOverlapping();
Schedule::command('ocm:alerts')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('ocm:update-schedules')->everyFiveMinutes()->withoutOverlapping(30);
Schedule::command('ocm:queue-checks --integrity')->dailyAt('04:15')->withoutOverlapping();
Schedule::command('ocm:plugin-directory')->dailyAt('05:10')->withoutOverlapping(60);
