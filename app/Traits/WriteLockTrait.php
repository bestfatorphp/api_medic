<?php

namespace App\Traits;

use App\Models\WriteLock;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

trait WriteLockTrait
{
    /**
     * Получаем эксклюзивный доступ для записи в таблицу
     * Будет пытаться бесконечно, пока не получит блокировку
     */
    protected function lockTableForWrite(string $tableName): void
    {
        $attempt = 0;
        $lastErrorTime = null;

        while (true) {
            try {
                $locked = DB::transaction(function () use ($tableName) {
                    // Очищаем старые блокировки (> 5 минут)
                    WriteLock::where('table_name', $tableName)
                        ->where('locked_at', '<', Carbon::now()->subMinutes(5))
                        ->delete();

                    // Пытаемся получить или создать блокировку
                    $lock = WriteLock::firstOrNew(['table_name' => $tableName]);

                    if ($lock->exists && $lock->is_writing) {
                        return false;
                    }

                    $lock->fill([
                        'is_writing' => true,
                        'locked_at' => Carbon::now()
                    ])->save();

                    return true;
                });

                if ($locked) {
                    return;
                }

            } catch (QueryException $e) {
                $this->handleLockError($e, $tableName, $attempt, $lastErrorTime);
            }

            $this->waitBeforeRetry($attempt);
            $attempt++;
        }
    }

    /**
     * Освобождаем блокировку таблицы
     */
    protected function unlockTable(string $tableName): void
    {
        $attempt = 0;

        while ($attempt < 5) {
            try {
                DB::transaction(function () use ($tableName) {
                    WriteLock::where('table_name', $tableName)
                        ->update([
                            'is_writing' => false,
                            'locked_at' => null,
                            'updated_at' => Carbon::now()
                        ]);
                });
                return;
            } catch (\Exception $e) {
                $attempt++;
                Log::warning("Unlock attempt {$attempt} failed for {$tableName}: " . $e->getMessage());
                $this->waitBeforeRetry($attempt);

                if ($attempt >= 5) {
                    $this->forceReleaseLock($tableName);
                    return;
                }
            }
        }
    }

    /**
     * Безопасное выполнение с блокировкой таблицы
     */
    protected function withTableLock(string $tableName, callable $operation): mixed
    {
        $this->lockTableForWrite($tableName);

        try {
            return DB::transaction(function () use ($operation) {
                return $operation();
            });
        } finally {
            $this->unlockTable($tableName);
        }
    }

    private function handleLockError(QueryException $e, string $tableName, int &$attempt, ?Carbon &$lastErrorTime): void
    {
        $currentTime = Carbon::now();
        $isDeadlock = $this->isDeadlockException($e);

        if ($lastErrorTime && $lastErrorTime->diffInMinutes($currentTime) >= 5) {
            Log::error("Persistent lock error for {$tableName} - forcing release");
            $this->forceReleaseLock($tableName);
            $lastErrorTime = null;
            $attempt = 0;
            return;
        }

        if ($isDeadlock) {
            Log::warning("Deadlock detected for {$tableName}, attempt {$attempt}");
        } else {
            Log::error("Lock error for {$tableName}: " . $e->getMessage());
        }

        $lastErrorTime = $currentTime;
    }

    private function forceReleaseLock(string $tableName): void
    {
        try {
            DB::connection()->reconnect();
            WriteLock::withoutGlobalScopes()
                ->where('table_name', $tableName)
                ->delete();
            Log::info("Forcefully released lock for {$tableName}");
        } catch (\Exception $e) {
            Log::error("Force unlock failed for {$tableName}: " . $e->getMessage());
        }
    }

    private function waitBeforeRetry(int $attempt): void
    {
        $delay = min(5000000, 100000 * pow(2, $attempt)); //макс 5 секунд
        usleep($delay);
    }

    private function isDeadlockException(QueryException $e): bool
    {
        $codes = [
            'mysql' => [1213],
            'pgsql' => ['40P01'],
            'sqlsrv' => [1205]
        ];

        $driver = config('database.default');
        return in_array($e->errorInfo[1] ?? null, $codes[$driver] ?? []);
    }

    public static function clearStaleLocks(): void
    {
        try {
            WriteLock::where('is_writing', true)
                ->where('locked_at', '<', Carbon::now()->subMinutes(10))
                ->delete();
        } catch (\Exception $e) {
            Log::error("Clear stale locks failed: " . $e->getMessage());
        }
    }
}
