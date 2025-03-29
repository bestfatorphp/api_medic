<?php

namespace App\Logging;

use Illuminate\Support\Facades\Log;
use Monolog\Formatter\LineFormatter;

class CustomLog
{
    public function __invoke($logger)
    {
        foreach ($logger->getHandlers() as $handler) {
            $handler->setFormatter(new LineFormatter(
                "[%datetime%] %channel%.%level_name%: %message% %context%" . PHP_EOL,
                'Y-m-d H:i:s.u',
                true,
                true
            ));
        }
    }

    /**
     * @param string $className
     * @param string|null $channel
     * @param \Exception $e
     */
    public static function errorLog(string $className, ?string $channel, \Exception $e)
    {
        Log::channel($channel ?? null)->error(
            $className . " Error: " . $e->getMessage(),
            [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]
        );
    }

    /**
     * @param string $className
     * @param string|null $channel
     * @param string $message
     */
    public static function warningLog(string $className, ?string $channel, string $message)
    {
        Log::channel($channel ?? null)->warning($className . ": " . $message);
    }
}
