<?php
declare(strict_types=1);

final class K2AonGlobalLockBusyException extends RuntimeException
{
}

final class K2AonGlobalImportLock
{
    public const LOCK_NAME = 'k2_automation_aon_import_global';

    private bool $released = false;

    private function __construct(
        private readonly PDO $pdo,
        private readonly bool $acquired
    ) {
    }

    public static function acquire(PDO $pdo, int $timeoutSeconds): self
    {
        if ($timeoutSeconds < 0) {
            throw new InvalidArgumentException('AON global lock timeout must not be negative.');
        }

        $stmt = $pdo->prepare('SELECT GET_LOCK(?, ?) AS lock_result');
        $stmt->execute([self::LOCK_NAME, $timeoutSeconds]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $acquired = isset($result['lock_result']) && (int) $result['lock_result'] === 1;

        return new self($pdo, $acquired);
    }

    public function acquired(): bool
    {
        return $this->acquired && !$this->released;
    }

    public function requireAcquired(): void
    {
        if (!$this->acquired()) {
            throw new K2AonGlobalLockBusyException('Another AON import is already running.');
        }
    }

    public function release(): bool
    {
        if (!$this->acquired()) {
            return true;
        }

        try {
            $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(?) AS release_result');
            $stmt->execute([self::LOCK_NAME]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $released = isset($result['release_result']) && (int) $result['release_result'] === 1;
            $this->released = true;

            return $released;
        } catch (Throwable) {
            error_log('K2 automation AON global lock release failed.');
            return false;
        }
    }
}
