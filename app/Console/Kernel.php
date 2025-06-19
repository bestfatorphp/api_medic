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
        $schedule->command('import:medtouch-helios --chunk=5 --timeout=120 --need-file=true')
            ->dailyAt('20:00')
            ->sendOutputTo(storage_path('logs/output/import-medtouch.log'));

        $schedule->command('import:telegram-users')
            ->dailyAt('20:01')
            ->sendOutputTo(storage_path('logs/output/import-telegram-users.log'));

        $schedule->command('import:id-campaigns')
            ->dailyAt('20:02')
            ->sendOutputTo(storage_path('logs/output/import-id-campaigns.log'));

        $schedule->command('import:old-mt-users')
            ->dailyAt('20:03')
            ->sendOutputTo(storage_path('logs/output/import-old-mt-users.log'));

        $schedule->command('import:us-campaigns --from=15.05.2025 --to=01.06.2025')
            ->dailyAt('20:04')
            ->sendOutputTo(storage_path('logs/output/import-us-campaigns.log'));

        $schedule->command('import:new-mt-users --updated_after=27.09.2024')
            ->dailyAt('20:05')
            ->sendOutputTo(storage_path('logs/output/import-new-mt-users.log'));
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
