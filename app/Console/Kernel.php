<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('sync:saasu-items')->dailyAt('20:00');
        $schedule->command('sync:saasu-contacts')->dailyAt('20:00');
        $schedule->command('sync:saasu-companies')->dailyAt('20:00');
        $schedule->command('sync:saasu-invoices')->dailyAt('20:00');
        $schedule->command(\Spatie\Health\Commands\RunHealthChecksCommand::class)->everyMinute();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }

    protected function scheduleTimezone()
    {
        return 'Australia/Sydney';
    }
}
