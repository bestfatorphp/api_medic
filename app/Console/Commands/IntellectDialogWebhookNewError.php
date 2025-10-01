<?php

namespace App\Console\Commands;

use App\Facades\IntellectDialog;
use Illuminate\Console\Command;

class IntellectDialogWebhookNewError extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'intellect-dialog:webhook-new-error';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Устанавливаем webhook - событие new_error';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $webhookUrl = config('app.url') . '/api/webhook/id-errors';

        try {
            $this->info("Устанавливаю webhook: {$webhookUrl}");

            $result = IntellectDialog::setWebhook($webhookUrl, ['new_error']);

            $this->line('Текущие настройки:');
            $this->line('URL: ' . $result['url']);
            $this->line('Webhook успешно установлен! События: ' . implode(', ', $result['events']));
        } catch (\Exception $e) {
            $this->error('Ошибка при установке webhook: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
