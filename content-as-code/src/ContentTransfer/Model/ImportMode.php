<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Model;

/**
 * What `apply` does when the bundle names something this install already has.
 *
 * SKIP is the default because the common case is a deploy that runs the same bundle on every
 * environment forever: the first run seeds, the rest are no-ops, and nobody's hand edit gets
 * silently reverted at 3am. REPLACE has to be typed out, every time.
 */
enum ImportMode: string
{
    case Skip = 'skip';
    case Replace = 'replace';

    public function replacesExisting(): bool
    {
        return $this === self::Replace;
    }
}
