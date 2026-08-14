<?php
declare(strict_types=1);

namespace Scr1be\OrderAttribution\Model;

use Magento\Framework\Exception\NoSuchEntityException;
use Scr1be\OrderAttribution\Api\SourceRepositoryInterface;

/**
 * Turns raw mutation input into an Attribution, or explains why it cannot.
 *
 * Validation is against the registry rather than a free-text column, because attribution that is not
 * a closed set is not attribution — it is a folder of typos. Two clients writing `ios` and `iOS`
 * produce two rows in every report that groups by source, and nobody notices until the quarter ends.
 */
class SourceValidator
{
    /**
     * @param SourceRepositoryInterface $sourceRepository
     */
    public function __construct(private readonly SourceRepositoryInterface $sourceRepository)
    {
    }

    /**
     * Validate one `order_source` input object.
     *
     * Returns null when the client sent nothing — an absent attribution is not an error, because the
     * field is optional and the storefront never sends it.
     *
     * @param array<string, mixed>|null $input
     * @return Attribution|null
     * @throws UnknownSourceException
     */
    public function validate(?array $input): ?Attribution
    {
        if ($input === null) {
            return null;
        }

        $code = trim((string)($input['source_code'] ?? ''));
        if ($code === '') {
            return null;
        }

        try {
            $source = $this->sourceRepository->getByCode($code);
        } catch (NoSuchEntityException) {
            throw new UnknownSourceException($code);
        }

        if (!$source->isActive()) {
            // Deactivated rather than deleted is how a merchant retires a channel without rewriting
            // the orders that came through it. Refusing new traffic is the whole point of the flag.
            throw new UnknownSourceException($code);
        }

        $detail = $input['source_detail'] ?? null;

        return Attribution::of($source->getCode(), $detail === null ? null : (string)$detail);
    }
}
