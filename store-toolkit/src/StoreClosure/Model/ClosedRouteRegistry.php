<?php
declare(strict_types=1);

namespace Scr1be\StoreClosure\Model;

/**
 * Which storefront routes stop working while a store is closed.
 *
 * Two lists rather than one, because the granularity genuinely differs: the whole `checkout` route
 * goes away, while `customer` only loses the pages that create or start a session — a customer who
 * is already signed in still has an order history worth reading, and locking them out of it during
 * a closure would generate support tickets rather than prevent them.
 *
 * Both lists are di.xml arguments so a project can add its own (a quote-request module, a
 * subscription portal) without touching this module.
 */
class ClosedRouteRegistry
{
    /**
     * @var string[]
     */
    private array $routes;

    /**
     * @var string[]
     */
    private array $fullActionNames;

    /**
     * @param string[] $routes Whole route names, e.g. `checkout`.
     * @param string[] $fullActionNames Individual actions, e.g. `customer_account_login`.
     */
    public function __construct(array $routes = [], array $fullActionNames = [])
    {
        // Normalised on the way in, because Magento\Framework\App\Request\Http::getFullActionName()
        // concatenates the router's own strings without touching their case — `customer_account_loginPost`
        // is what actually arrives — while everyone writing a di.xml list types lower case.
        $this->routes = array_map('strtolower', array_values($routes));
        $this->fullActionNames = array_map('strtolower', array_values($fullActionNames));
    }

    public function isClosedRoute(string $routeName, string $fullActionName): bool
    {
        return in_array(strtolower($routeName), $this->routes, true)
            || in_array(strtolower($fullActionName), $this->fullActionNames, true);
    }
}
