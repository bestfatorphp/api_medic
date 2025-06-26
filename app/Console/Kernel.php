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
        $commonPath = 'logs/';
        $schedule->command('import:medtouch-helios --chunk=5 --timeout=120 --need-file=true')
            ->dailyAt('13:30')
            ->sendOutputTo(storage_path("{$commonPath}import-medtouch.log"));

        $schedule->command('import:telegram-users')
            ->dailyAt('13:31')
            ->sendOutputTo(storage_path("{$commonPath}import-telegram-users.log"));

        $schedule->command('import:sendsay-stats --from=01.01.2025')
            ->dailyAt('13:32')
            ->sendOutputTo(storage_path("{$commonPath}import-stats-sendsay.log"));

        $schedule->command('import:id-campaigns')
            ->dailyAt('13:33')
            ->sendOutputTo(storage_path("{$commonPath}import-id-campaigns.log"));

        $schedule->command('import:old-mt-users')
            ->dailyAt('13:34')
            ->sendOutputTo(storage_path("{$commonPath}import-old-mt-users.log"));

        $schedule->command('import:new-mt-users --updated_after=27.09.2024')
            ->dailyAt('13:35')
            ->sendOutputTo(storage_path("{$commonPath}import-new-mt-users.log"));

        $schedule->command('import:us-campaigns --from=15.05.2025 --to=01.06.2025')
            ->dailyAt('13:36')
            ->sendOutputTo(storage_path("{$commonPath}import-us-campaigns.log"));
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
}
