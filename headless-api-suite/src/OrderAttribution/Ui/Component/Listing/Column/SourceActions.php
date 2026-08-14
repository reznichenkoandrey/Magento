<?php
declare(strict_types=1);

namespace Scr1be\OrderAttribution\Ui\Component\Listing\Column;

use Magento\Framework\Escaper;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Edit and delete links for one registry row.
 *
 * `'post' => true` on the delete link is what makes the delete controller's HttpPostActionInterface
 * reachable: the grid renders a form-submitting link rather than an anchor. Core does the same in
 * `Magento\Cms\Ui\Component\Listing\Column\BlockActions`.
 */
class SourceActions extends Column
{
    private const URL_PATH_EDIT = 'scr1be_orderattribution/source/edit';
    private const URL_PATH_DELETE = 'scr1be_orderattribution/source/delete';

    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param UrlInterface $urlBuilder
     * @param Escaper $escaper
     * @param array $components
     * @param array $data
     */
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
     * @inheritDoc
     */
    public function prepareDataSource(array $dataSource): array
    {
        foreach ($dataSource['data']['items'] ?? [] as &$item) {
            if (!isset($item['source_id'])) {
                continue;
            }

            $code = $this->escaper->escapeHtmlAttr((string)($item['code'] ?? ''));
            $item[$this->getData('name')] = [
                'edit' => [
                    'href' => $this->urlBuilder->getUrl(self::URL_PATH_EDIT, ['source_id' => $item['source_id']]),
                    'label' => __('Edit'),
                ],
                'delete' => [
                    'href' => $this->urlBuilder->getUrl(self::URL_PATH_DELETE, ['source_id' => $item['source_id']]),
                    'label' => __('Delete'),
                    'confirm' => [
                        'title' => __('Delete %1', $code),
                        'message' => __(
                            'Orders already attributed to "%1" keep the code but lose its label. '
                            . 'Set the source to "No" under Accepting New Orders instead if you only '
                            . 'want to stop new traffic.',
                            $code
                        ),
                    ],
                    'post' => true,
                ],
            ];
        }

        return $dataSource;
    }
}
