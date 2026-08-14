<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Ui\Component\Listing\Column;

use Magento\Framework\Escaper;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Edit and Delete links per row.
 *
 * The delete confirmation names the slider, escaped for an attribute context, because the message is
 * interpolated into `data-mage-init` markup — a title containing a quote would otherwise end the
 * attribute early, and a title is merchant-supplied text.
 */
class SliderActions extends Column
{
    private const URL_PATH_EDIT = 'scr1be_slider/slider/edit';
    private const URL_PATH_DELETE = 'scr1be_slider/slider/delete';

    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        private readonly Escaper $escaper,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @param array<string, mixed> $dataSource
     * @return array<string, mixed>
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        foreach ($dataSource['data']['items'] as &$item) {
            if (!isset($item['slider_id'])) {
                continue;
            }

            $sliderId = (int) $item['slider_id'];

            $item[$this->getData('name')] = [
                'edit' => [
                    'href' => $this->urlBuilder->getUrl(self::URL_PATH_EDIT, ['slider_id' => $sliderId]),
                    'label' => __('Edit'),
                ],
                'delete' => [
                    'href' => $this->urlBuilder->getUrl(self::URL_PATH_DELETE, ['slider_id' => $sliderId]),
                    'label' => __('Delete'),
                    'post' => true,
                    'confirm' => [
                        'title' => __('Delete "%1"', $this->escaper->escapeHtmlAttr((string) ($item['title'] ?? ''))),
                        'message' => __(
                            'Are you sure you want to delete "%1"? Any page or widget pointing at its '
                            . 'identifier will render nothing.',
                            $this->escaper->escapeHtmlAttr((string) ($item['title'] ?? ''))
                        ),
                    ],
                ],
            ];
        }

        return $dataSource;
    }
}
