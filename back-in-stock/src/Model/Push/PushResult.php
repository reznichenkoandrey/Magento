<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Model\Push;

/**
 * What came back from a send.
 *
 * `invalidTokens` is the interesting half and the reason this is a result object rather than a bool.
 * A push registry that only ever grows is a registry that, a year in, spends most of its send budget
 * on browsers that were uninstalled — and the only party that knows a token is dead is the push
 * service, at the moment it refuses it. Handing those tokens back is what lets the caller clean up
 * without a second API and without guessing.
 */
final class PushResult
{
    /**
     * @param string[] $invalidTokens Tokens the service refused permanently. Not the ones that
     *        failed transiently: a timeout is not a dead device.
     * @param string[] $errors One line per failure, for the log.
     */
    public function __construct(
        public readonly int $delivered,
        public readonly array $invalidTokens = [],
        public readonly array $errors = []
    ) {
    }

    public static function nothingSent(): self
    {
        return new self(0);
    }
}
