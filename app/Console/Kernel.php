<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // $schedule->command('inspire')->hourly();
        // $schedule->command('tickets:close-inactive')->hourly();
        $schedule->command('subscriptions:sync')->hourly()->withoutOverlapping(90);
            $schedule->command('subscriptions:deliver-extension-emails')->everyMinute()->withoutOverlapping(5);
        $schedule->command('push:subscription-expiry-reminders')->dailyAt('09:00');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
        
    }
    protected $commands = [
        \App\Console\Commands\Serve::class,
    ];
}
