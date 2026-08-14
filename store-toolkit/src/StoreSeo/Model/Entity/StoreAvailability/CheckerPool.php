<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Model\Entity\StoreAvailability;

use Magento\Framework\Exception\ConfigurationMismatchException;

/**
 * Entity type to checker, wired in di.xml.
 *
 * A pool rather than a chain of `instanceof`: adding a fourth entity type (a blog post, a landing
 * page from a page-builder module) is then an `<item>` in someone else's di.xml and no edit here.
 */
class CheckerPool
{
    /**
     * @var array<string, AvailabilityCheckerInterface>
     */
    private array $checkers;

    /**
     * @param array<string, AvailabilityCheckerInterface> $checkers
     * @throws ConfigurationMismatchException
     */
    public function __construct(array $checkers = [])
    {
        foreach ($checkers as $type => $checker) {
            if (!$checker instanceof AvailabilityCheckerInterface) {
                throw new ConfigurationMismatchException(
                    __('Availability checker for "%1" must implement %2.', $type, AvailabilityCheckerInterface::class)
                );
            }
        }

        $this->checkers = $checkers;
    }

    /**
     * Null for a type nobody registered — the caller then advertises nothing rather than guessing.
     */
    public function get(string $entityType): ?AvailabilityCheckerInterface
    {
        return $this->checkers[$entityType] ?? null;
    }
}
