<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Model\SocialProof;

/**
 * One product's purchase line, already worded.
 *
 * The browser receives `text` and nothing it could use to re-identify a buyer: no order id, no
 * surname, no timestamp. `purchases` travels alongside because a slide may want to render "4 sold
 * this week" instead of the sentence, and `elapsedSeconds` because a long-lived page can grey the
 * line out once it is old — neither is enough to reconstruct who bought what.
 */
class Proof implements \JsonSerializable
{
    public function __construct(
        private readonly int $productId,
        private readonly string $text,
        private readonly int $elapsedSeconds,
        private readonly int $purchases
    ) {
    }

    public function getProductId(): int
    {
        return $this->productId;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getElapsedSeconds(): int
    {
        return $this->elapsedSeconds;
    }

    public function getPurchases(): int
    {
        return $this->purchases;
    }

    /**
     * @return array{text: string, elapsed: int, purchases: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'text' => $this->text,
            'elapsed' => $this->elapsedSeconds,
            'purchases' => $this->purchases,
        ];
    }
}
