<?php
declare(strict_types=1);

namespace Scr1be\FpcInspector\Model;

use Psr\Log\LoggerInterface;

/**
 * Writes a record to the module's own log file.
 *
 * Its own file (`var/log/fpc_inspector.log`, wired in `etc/di.xml`) rather than a line in
 * `system.log`: this log is read by tailing it while reloading one page, and every unrelated notice
 * in between costs the reader a scroll. It also makes cleaning up after a debugging session a
 * single `rm`.
 *
 * Each line carries a one-sentence summary as the message and the full structured record as the
 * Monolog context, so `tail -f` stays readable while `grep … | jq` still has everything.
 */
class Recorder
{
    /**
     * A vary string is a 64-character sha256. Nobody reads it; they compare it. Eight characters is
     * enough to see at a glance that two lines disagree, and the untruncated value stays in the
     * structured context for anyone who wants to match it against a cache key.
     */
    private const SUMMARY_HASH_LENGTH = 8;

    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, mixed> $record
     */
    public function record(array $record): void
    {
        $this->logger->info($this->summarise($record), $record);
    }

    /**
     * A debugging tool that takes the storefront down with it is worse than no debugging tool, so
     * both hooks swallow their own failures and report them here instead. If even this fails —
     * an unwritable log directory being the realistic case — the module gives up quietly rather
     * than letting an exception escape into a page it was only supposed to watch.
     */
    public function failed(\Throwable $error): void
    {
        try {
            $this->logger->error('FPC Inspector could not write a record', ['exception' => (string) $error]);
        } catch (\Throwable) {
            return;
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    private function summarise(array $record): string
    {
        $channel = (string) ($record['channel'] ?? 'unknown');
        $uri = (string) ($record['uri'] ?? '');

        if ($channel === RecordBuilder::CHANNEL_NO_CACHE) {
            $cacheControl = $record['will_cache']['cache_control'] ?? null;

            return sprintf(
                'no-cache stamped on %s, replacing Cache-Control: %s',
                $uri,
                is_string($cacheControl) && $cacheControl !== '' ? $cacheControl : '(none set)'
            );
        }

        $contributors = is_array($record['contributors'] ?? null) ? $record['contributors'] : [];
        $keys = array_map(
            static fn (array $contributor): string => (string) ($contributor['key'] ?? '?'),
            $contributors
        );

        return sprintf(
            'vary %s on %s from %s',
            $this->shorten($record['vary'] ?? null),
            $uri,
            $keys === [] ? 'nothing (context is empty, so the page is cached unvaried)' : implode(', ', $keys)
        );
    }

    private function shorten(mixed $hash): string
    {
        if (!is_string($hash) || $hash === '') {
            return '(none)';
        }

        return substr($hash, 0, self::SUMMARY_HASH_LENGTH);
    }
}
