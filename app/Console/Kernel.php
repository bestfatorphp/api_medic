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
        //Запускаем команды:

        //php artisan import:sendsay-stats --from=01.05.2025

        //php artisan import:users-chats - разовая команда (отработала)

        //php artisan import:old-mt-users - разовая команда (отработала)

        //php artisan import:new-mt-users --onlyUsers=1 (отработала)
        //php artisan import:new-mt-users --updated_after=01.01.2025 (отработала)


        //php artisan import:new-mt-touches --updated_after=01.05.2025 (отработала)

        //php artisan import:medtouch-helios --chunk=5 --timeout=120 --need-file=true


        $commonPath = 'logs/';

//        $schedule->command('import:sendsay-stats --from=01.05.2025')
//            ->yearlyOn(now()->month, now()->day, '01:00')
//            ->timezone('Europe/Moscow')
//            ->sendOutputTo(storage_path("{$commonPath}import-stats-sendsay.log"));

//        $schedule->command('import:medtouch-helios --chunk=5 --timeout=120 --need-file=true')
//            ->yearlyOn(now()->month, now()->day, '15:35')
//            ->timezone('Europe/Moscow')
//            ->sendOutputTo(storage_path("{$commonPath}import-medtouch-cn.log"));

        //Суточные комманды (сбор статистики и данных за предыдущие сутки)

        $schedule->command('import:new-mt-users')
            ->dailyAt('00:10')
            ->sendOutputTo(storage_path("{$commonPath}import-new-mt-users.log"));

        $schedule->command('import:new-mt-touches')
            ->dailyAt('00:20')
            ->sendOutputTo(storage_path("{$commonPath}import-new-mt-touches.log"));

//        $schedule->command('import:medtouch-helios --chunk=5 --timeout=120 --need-file=true')
//            ->dailyAt('00:30')
//            ->sendOutputTo(storage_path("{$commonPath}import-medtouch.log"));

//        $schedule->command('import:sendsay-stats')
//            ->dailyAt('01:00')
//            ->sendOutputTo(storage_path("{$commonPath}import-stats-sendsay.log"));



        /*
        $schedule->command('import:id-campaigns')
            ->dailyAt('00:40')
            ->sendOutputTo(storage_path("{$commonPath}import-id-campaigns.log"));*/


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
