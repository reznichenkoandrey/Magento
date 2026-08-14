<?php
declare(strict_types=1);

namespace Scr1be\FpcInspector\Model;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * The bookkeeping that turns a stream of hook calls into a readable trace.
 *
 * Three jobs, all of them per-request:
 *
 * 1. **Correlation.** Both channels stamp the same short request id, so the vary records and the
 *    no-cache records written by one page load can be grepped out of a busy file together.
 *
 * 2. **De-duplication.** The vary string is rebuilt several times per request by different callers.
 *    Writing a line every time buries the interesting event under identical copies of itself, and
 *    writing only the first line throws away the caller list, which is half the answer. So the
 *    fingerprint is (channel + value + call site): one line per distinct call site, plus a fresh
 *    line the moment a call site produces a value that has not been seen yet. A vary string that
 *    changes mid-request is precisely the bug worth catching, and this policy makes it two adjacent
 *    lines instead of a silent overwrite.
 *
 * 3. **Re-entrancy.** The no-cache hook asks the HTTP context for the current vary string to
 *    describe the response it is looking at. That call goes through the same interceptor the vary
 *    hook is attached to, so without a guard the module would record its own question as if it were
 *    somebody else's answer, with a stack rooted inside this module. Recording is therefore a
 *    critical section: while one record is being assembled, the other channel stands down.
 */
class RequestScope implements ResetAfterRequestInterface
{
    /**
     * Four bytes is plenty. The id only has to be unique among the requests interleaved in one log
     * file during a debugging session, not globally, and a short token keeps the line readable.
     */
    private const ID_BYTES = 4;

    private ?string $requestId = null;

    private int $sequence = 0;

    private bool $recording = false;

    /**
     * @var array<string, true>
     */
    private array $seenFingerprints = [];

    public function getRequestId(): string
    {
        if ($this->requestId === null) {
            $this->requestId = bin2hex(random_bytes(self::ID_BYTES));
        }

        return $this->requestId;
    }

    public function nextSequence(): int
    {
        return ++$this->sequence;
    }

    /**
     * True the first time a given (channel, value, call site) combination is seen in this request.
     */
    public function isFirstSighting(string $fingerprint): bool
    {
        if (isset($this->seenFingerprints[$fingerprint])) {
            return false;
        }

        $this->seenFingerprints[$fingerprint] = true;

        return true;
    }

    public function isRecording(): bool
    {
        return $this->recording;
    }

    public function beginRecording(): void
    {
        $this->recording = true;
    }

    public function endRecording(): void
    {
        $this->recording = false;
    }

    /**
     * Application servers that keep the object graph alive between requests would otherwise hand
     * request two the fingerprints and the id of request one.
     */
    public function _resetState(): void
    {
        $this->requestId = null;
        $this->sequence = 0;
        $this->recording = false;
        $this->seenFingerprints = [];
    }
}
