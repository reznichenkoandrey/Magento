<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Model;

use Magento\Framework\Exception\ConfigurationMismatchException;
use Magento\Framework\Phrase;
use Scr1be\ContentTransfer\Api\PorterInterface;

/**
 * The registry, and the only place that knows what order content has to be written in.
 *
 * Ordering is derived from `PorterInterface::getDependencies()` rather than from a sort_order
 * integer, because the thing being expressed is a real constraint — a page that embeds a block by
 * identifier needs that block to exist — and integers let two modules agree on 10 and disagree on
 * reality. A cycle is a configuration bug and throws on first use; there is no ordering that
 * satisfies it and picking one silently would produce an import that works on the developer's
 * machine and not on the next one.
 */
class PorterPool
{
    /**
     * @var array<string, PorterInterface>
     */
    private array $porters = [];

    /**
     * @var PorterInterface[]|null Memoised topological order; the graph cannot change at runtime.
     */
    private ?array $sorted = null;

    /**
     * @param PorterInterface[] $porters
     * @throws ConfigurationMismatchException
     */
    public function __construct(array $porters = [])
    {
        foreach ($porters as $key => $porter) {
            if (!$porter instanceof PorterInterface) {
                throw new ConfigurationMismatchException(
                    new Phrase(
                        'Porter "%1" must implement %2, %3 given.',
                        [$key, PorterInterface::class, get_debug_type($porter)]
                    )
                );
            }

            // Keyed by the porter's own code, not by the di.xml array key: the code is what appears
            // in bundles and on the command line, and a mismatch between the two is a trap.
            $this->porters[$porter->getCode()] = $porter;
        }
    }

    public function has(string $code): bool
    {
        return isset($this->porters[$code]);
    }

    /**
     * @throws ConfigurationMismatchException when nothing in the pool answers to that code.
     */
    public function get(string $code): PorterInterface
    {
        if (!isset($this->porters[$code])) {
            throw new ConfigurationMismatchException(
                new Phrase('No content porter is registered for "%1".', [$code])
            );
        }

        return $this->porters[$code];
    }

    /**
     * @return array<string, PorterInterface> Registration order, for listings that only need names.
     */
    public function getAll(): array
    {
        return $this->porters;
    }

    /**
     * Dependencies first, then alphabetically by code so that two installs with the same modules
     * produce the same bundle regardless of the order `di.xml` files happened to merge in.
     *
     * @return PorterInterface[]
     * @throws ConfigurationMismatchException on a dependency cycle or an unsatisfiable dependency.
     */
    public function getSorted(): array
    {
        if ($this->sorted !== null) {
            return $this->sorted;
        }

        $codes = array_keys($this->porters);
        sort($codes);

        $sorted = [];
        $state = [];

        foreach ($codes as $code) {
            $this->visit($code, $state, $sorted);
        }

        return $this->sorted = $sorted;
    }

    /**
     * Depth-first visit with a three-colour marker: absent = unvisited, false = on the current
     * stack, true = finished. The false state is what turns a cycle into an exception instead of
     * infinite recursion.
     *
     * @param array<string, bool> $state
     * @param PorterInterface[] $sorted
     * @throws ConfigurationMismatchException
     */
    private function visit(string $code, array &$state, array &$sorted): void
    {
        if (isset($state[$code])) {
            if ($state[$code] === false) {
                throw new ConfigurationMismatchException(
                    new Phrase('Content porter dependencies form a cycle involving "%1".', [$code])
                );
            }

            return;
        }

        $state[$code] = false;

        $dependencies = $this->get($code)->getDependencies();
        sort($dependencies);

        foreach ($dependencies as $dependency) {
            if (!$this->has($dependency)) {
                throw new ConfigurationMismatchException(
                    new Phrase(
                        'Content porter "%1" depends on "%2", which is not registered.',
                        [$code, $dependency]
                    )
                );
            }

            $this->visit($dependency, $state, $sorted);
        }

        $state[$code] = true;
        $sorted[] = $this->porters[$code];
    }
}
