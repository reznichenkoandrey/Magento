<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Controller\Adminhtml;

use Magento\Backend\App\Action;

/**
 * Shared ACL declaration for the slider back office.
 *
 * `ADMIN_RESOURCE` is what `Magento\Backend\App\AbstractAction::_isAllowed()` checks, so declaring it
 * once here is what stops a new controller from shipping wide open by inheriting the framework's
 * permissive default. The two write-side controllers narrow it further.
 */
abstract class AbstractSlider extends Action
{
    public const ADMIN_RESOURCE = 'Scr1be_HyvaProductSlider::sliders';
}
