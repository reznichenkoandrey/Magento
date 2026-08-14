<?php
declare(strict_types=1);

namespace Scr1be\AdminGridToolkit\Plugin\Grid;

use Magento\Backend\Block\Widget\Grid\Column\Renderer\AbstractRenderer;
use Scr1be\AdminGridToolkit\Model\Config;
use Scr1be\AdminGridToolkit\Model\Export\ValueDecoder;

/**
 * Un-renders a legacy grid cell on its way into a CSV or an Excel XML file.
 *
 * The hook is renderExport() rather than render(), and that is the whole safety argument for this
 * plugin. render() feeds an HTML page, where the escaping is not a defect but the point;
 * renderExport() has exactly one caller, Grid\Column::getRowFieldExport(), and it is only reached
 * from the export actions. Nothing this plugin returns can end up in an admin response body, so
 * decoding entities here cannot become a stored-XSS vector in a grid.
 *
 * `after` because the value core produced is the input: the plugin needs the rendered string and
 * has no reason to influence how it was rendered. A `before` plugin could not see it, and an
 * `around` would take responsibility for calling a renderer whose subclasses override the method.
 *
 * Declared on the abstract renderer so it binds to every subclass, including the handful that
 * override renderExport() themselves — Store, for one, which builds a store-view tree with its own
 * indentation and line breaks. Plugin configuration is inherited by descendants, so one declaration
 * covers every renderer in core and every renderer a third-party grid ships.
 */
class DecodeExportedValues
{
    public function __construct(
        private readonly Config $config,
        private readonly ValueDecoder $decoder
    ) {
    }

    /**
     * Strings in, strings out. A renderer that answers with a Phrase — Store's "All Store Views",
     * for instance — is returning a translated literal rather than row data: there is nothing in it
     * to decode, and converting it here would change the type core's own callers were given.
     *
     * @param mixed $result
     * @return mixed
     */
    public function afterRenderExport(AbstractRenderer $subject, $result)
    {
        if (!is_string($result) || $result === '') {
            return $result;
        }

        if (!$this->config->isExportDecodingEnabled()) {
            return $result;
        }

        return $this->decoder->decode($result);
    }
}
