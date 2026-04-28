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
        // // Large dumps rebuilt nightly — withoutOverlapping prevents stacking if a run overruns
        // foreach (['systems', 'populated-systems', 'bodies', 'stations', 'carriers'] as $type) {
        //     $schedule->command("dumps:build --type={$type}")
        //         ->daily()
        //         ->withoutOverlapping()
        //         ->onOneServer()
        //         ->runInBackground();
        // }

        // // Recent (7-day window) dumps refreshed every six hours
        // foreach (['systems-recent', 'bodies-recent', 'stations-recent', 'carriers-recent'] as $type) {
        //     $schedule->command("dumps:build --type={$type}")
        //         ->everySixHours()
        //         ->withoutOverlapping()
        //         ->onOneServer()
        //         ->runInBackground();
        // }
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
