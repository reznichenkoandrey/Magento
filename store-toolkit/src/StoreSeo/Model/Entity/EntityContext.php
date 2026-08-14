<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Model\Entity;

/**
 * The thing the current request is about, in the two fields hreflang needs to know about it.
 */
class EntityContext
{
    /**
     * The store home page. Not an entity in url_rewrite terms — a store's home is its base URL,
     * whatever CMS page happens to be configured behind it — so it gets its own type rather than
     * being squeezed into the cms-page one.
     */
    public const TYPE_HOME = 'home';

    private string $type;

    private int $id;

    public function __construct(string $type, int $id = 0)
    {
        $this->type = $type;
        $this->id = $id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getId(): int
    {
        return $this->id;
    }
}
