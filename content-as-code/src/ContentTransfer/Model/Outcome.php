<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Model;

/**
 * What happened to one entry during `apply`.
 *
 * A porter returns Created / Replaced / Skipped. Failed is produced by the engine alone, from the
 * exception a porter let escape — a porter that catches its own errors and reports them as
 * successes is how an import comes back green with nothing imported.
 */
class Outcome
{
    public const STATUS_CREATED = 'created';
    public const STATUS_REPLACED = 'replaced';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    private function __construct(
        private readonly string $status,
        private readonly string $message
    ) {
    }

    public static function created(string $message = ''): self
    {
        return new self(self::STATUS_CREATED, $message);
    }

    public static function replaced(string $message = ''): self
    {
        return new self(self::STATUS_REPLACED, $message);
    }

    public static function skipped(string $message = ''): self
    {
        return new self(self::STATUS_SKIPPED, $message);
    }

    public static function failed(string $message): self
    {
        return new self(self::STATUS_FAILED, $message);
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function isFailure(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * True when this entry changed the database — the signal the engine uses to decide whether a
     * cache invalidation is warranted at the end of the run.
     */
    public function isWrite(): bool
    {
        return $this->status === self::STATUS_CREATED || $this->status === self::STATUS_REPLACED;
    }
}
