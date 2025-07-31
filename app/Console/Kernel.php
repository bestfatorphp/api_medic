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

        $schedule->command('import:sendsay-stats --from=01.05.2025 --to=12.05.2025')
            ->yearlyOn(now()->month, now()->day, '07:41')
            ->timezone('Europe/Moscow')
            ->sendOutputTo(storage_path("{$commonPath}import-stats-sendsay-cn-1.log"));

        //Суточные комманды (сбор статистики и данных за предыдущие сутки)
        /*$schedule->command('import:medtouch-helios --chunk=5 --timeout=120 --need-file=true')
            ->dailyAt('00:10')
            ->sendOutputTo(storage_path("{$commonPath}import-medtouch.log"));

        $schedule->command('import:telegram-users')
            ->dailyAt('00:20')
            ->sendOutputTo(storage_path("{$commonPath}import-telegram-users.log"));

        $schedule->command('import:sendsay-stats')
            ->dailyAt('00:30')
            ->sendOutputTo(storage_path("{$commonPath}import-stats-sendsay.log"));

        $schedule->command('import:id-campaigns')
            ->dailyAt('00:40')
            ->sendOutputTo(storage_path("{$commonPath}import-id-campaigns.log"));*/

        //Перенесли из файла, лучше запускать руками, если файл будет изменён!!!
//        $schedule->command('import:old-mt-users')
//            ->dailyAt('00:50')
//            ->sendOutputTo(storage_path("{$commonPath}import-old-mt-users.log"));

        /*$schedule->command('import:new-mt-users')
            ->dailyAt('01:00')
            ->sendOutputTo(storage_path("{$commonPath}import-new-mt-users.log"));

        $schedule->command('import:new-mt-touches')
            ->dailyAt('01:10')
            ->sendOutputTo(storage_path("{$commonPath}import-new-mt-touches.log"));*/

//        $schedule->command('import:us-campaigns')
//            ->dailyAt('01:20')
//            ->sendOutputTo(storage_path("{$commonPath}import-us-campaigns.log"));
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
