<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

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

        //import:users-chats - разовая команда (отработала)

        //import:old-mt-users - разовая команда (отработала)

        //import:new-mt-users --onlyUsers=1 (отработала)
        //import:new-mt-users --updated_after=01.01.2025 (отработала)

        //import:new-mt-touches --updated_after=01.05.2025 (отработала)

        //import:medtouch-helios --chunk=5 --timeout=120 --need-file=true (отработала)

        //import:medtouch-reg-users --chunk=5 --timeout=120 --need-file=true

        //import:id-campaigns (ПРОВЕРИТЬ!!!)

        //import:sendsay-stats --from=01.05.2025 (отработала)


        $commonPath = 'logs/';

//        $schedule->command('import:sendsay-stats --from=01.05.2025')
//            ->yearlyOn(now()->month, now()->day, '00:01')
//            ->timezone('Europe/Moscow')
//            ->sendOutputTo(storage_path("{$commonPath}import-users-chats.log"));

        //Суточные комманды (сбор статистики и данных за предыдущие сутки)

        $schedule->command('import:new-mt-users')
            ->dailyAt('00:10')
            ->sendOutputTo(storage_path("{$commonPath}import-new-mt-users.log"));

        $schedule->command('import:new-mt-touches')
            ->dailyAt('00:20')
            ->sendOutputTo(storage_path("{$commonPath}import-new-mt-touches.log"));

//        $schedule->command('import:id-campaigns')
//            ->dailyAt('00:30')
//            ->sendOutputTo(storage_path("{$commonPath}import-id-campaigns.log"));

        $schedule->command('import:sendsay-stats')
            ->dailyAt('00:40')
            ->sendOutputTo(storage_path("{$commonPath}import-stats-sendsay.log"));

        $schedule->command('import:medtouch-helios --chunk=5 --timeout=120 --need-file=true')
            ->dailyAt('01:30')
            ->sendOutputTo(storage_path("{$commonPath}import-medtouch-helios.log"))
            ->then(function () use ($commonPath) {
                //создаём и запускаем команду вручную с ожиданием, пока не отработает первая,
                //т.к. работают на одном порту, парралельная работа невозможна, нужно чтобы осуществился выход из клиента
                sleep(60);

                //удаляем старый файл, если он существует
                $logFile = storage_path("{$commonPath}import-medtouch-reg-users.log");
                if (file_exists($logFile)) {
                    unlink($logFile);
                }

                $command = new \App\Console\Commands\ImportBitrixMt\ImportMTRegisteredUsersFile();
                $command->setLaravel(app());
                $exitCode = $command->run(
                    new \Symfony\Component\Console\Input\ArrayInput([
                        '--chunk' => 5,
                        '--timeout' => 120,
                        '--need-file' => true,
                    ]),
                    new \Symfony\Component\Console\Output\StreamOutput(
                        fopen($logFile, 'w')
                    )
                );

                if ($exitCode !== 0) {
                    Log::channel('commands')->error("Команда import:medtouch-reg-users завершилась с ошибкой (код: {$exitCode})");
                }
            });


        //разовая команда (после оплаты аккаунта собрать)
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
