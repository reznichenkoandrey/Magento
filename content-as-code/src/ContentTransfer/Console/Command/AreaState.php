<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;

/**
 * Puts the process in the adminhtml area, once, without caring whether something already did.
 *
 * `Magento\Framework\App\State::setAreaCode()` throws `LocalizedException('Area code is already
 * set')` on the second call, and `getAreaCode()` throws when it has never been called — so the
 * check and the set are the same operation and both commands need it.
 *
 * Adminhtml rather than global because that is the area these same saves run in when an
 * administrator does them by hand, so config read under a scope resolves the way the admin UI would
 * resolve it. It does **not** load `adminhtml/di.xml`: that is a separate step (`ConfigLoader`) that
 * `Magento\Framework\Console\Cli` does not perform, which is why `ImportEngine` invalidates caches
 * itself instead of relying on the area-only wiring core puts on the widget instance model.
 */
class AreaState
{
    public function __construct(
        private readonly State $appState
    ) {
    }

    public function ensureAdminArea(): void
    {
        try {
            $this->appState->getAreaCode();
        } catch (LocalizedException) {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        }
    }
}
