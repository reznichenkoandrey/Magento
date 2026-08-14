<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Model\Pdf\Items;

use Magento\Sales\Model\Order\Pdf\Items\AbstractItems;
use Magento\Sales\Model\RtlTextHandler;

/**
 * Item renderer for the `order` PDF page type this module adds in etc/pdf.xml.
 *
 * Modelled on Magento\Sales\Model\Order\Pdf\Items\Invoice\DefaultInvoice, with the two places core's
 * base class assumes a *child* document corrected. `AbstractItems::getSku()` and
 * `AbstractItems::getItemOptions()` both reach through `$this->getItem()->getOrderItem()`, which is
 * how an invoice item finds the order item behind it. Here the item already *is* the order item, and
 * `Magento\Sales\Model\Order\Item` has no `getOrderItem()` — it would return null through the magic
 * getter and fatal on the next arrow. Both are overridden to read the item directly.
 *
 * The quantity comes from `getQtyOrdered()` rather than `getQty()`: an invoice item's `qty` is what
 * was invoiced, an order item's is not a column at all.
 */
class DefaultOrder extends AbstractItems
{
    /**
     * Column feeds, shared with Scr1be\SignedDocumentDelivery\Model\Pdf\Order::_drawHeader() so the
     * values line up under their headings.
     */
    private const FEED_NAME = 35;
    private const FEED_SKU = 290;
    private const FEED_QTY = 435;
    private const FEED_PRICE = 395;
    private const FEED_SUBTOTAL = 565;
    private const FEED_TAX = 495;
    private const FEED_OPTION_VALUE = 40;

    private const WRAP_NAME = 35;
    private const WRAP_SKU = 17;
    private const WRAP_OPTION_LABEL = 40;
    private const WRAP_OPTION_VALUE = 50;

    private const LINE_HEIGHT = 20;
    private const LINE_SHIFT = 5;

    /**
     * @var \Magento\Framework\Stdlib\StringUtils
     */
    private $string;

    /**
     * @var RtlTextHandler
     */
    private $rtlTextHandler;

    /**
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Tax\Helper\Data $taxData
     * @param \Magento\Framework\Filesystem $filesystem
     * @param \Magento\Framework\Filter\FilterManager $filterManager
     * @param \Magento\Framework\Stdlib\StringUtils $string
     * @param RtlTextHandler $rtlTextHandler
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource|null $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb|null $resourceCollection
     * @param array $data
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Tax\Helper\Data $taxData,
        \Magento\Framework\Filesystem $filesystem,
        \Magento\Framework\Filter\FilterManager $filterManager,
        \Magento\Framework\Stdlib\StringUtils $string,
        RtlTextHandler $rtlTextHandler,
        ?\Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        ?\Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->string = $string;
        $this->rtlTextHandler = $rtlTextHandler;
        parent::__construct(
            $context,
            $registry,
            $taxData,
            $filesystem,
            $filterManager,
            $resource,
            $resourceCollection,
            $data
        );
    }

    /**
     * Draw one order line.
     *
     * @return void
     */
    public function draw()
    {
        $order = $this->getOrder();
        $item = $this->getItem();
        $pdf = $this->getPdf();
        $page = $this->getPage();
        $lines = [];

        $lines[0][] = [
            'text' => $this->string->split($this->prepareText((string) $item->getName()), self::WRAP_NAME, true, true),
            'feed' => self::FEED_NAME,
        ];

        $lines[0][] = [
            'text' => $this->string->split($this->prepareText((string) $this->getSku($item)), self::WRAP_SKU),
            'feed' => self::FEED_SKU,
            'align' => 'right',
        ];

        // `* 1` is core's own idiom for turning "2.0000" into "2" without deciding on a format.
        $lines[0][] = ['text' => $item->getQtyOrdered() * 1, 'feed' => self::FEED_QTY, 'align' => 'right'];

        $index = 0;
        foreach ($this->getItemPricesForDisplay() as $priceData) {
            if (isset($priceData['label'])) {
                $lines[$index][] = ['text' => $priceData['label'], 'feed' => self::FEED_PRICE, 'align' => 'right'];
                $lines[$index][] = ['text' => $priceData['label'], 'feed' => self::FEED_SUBTOTAL, 'align' => 'right'];
                $index++;
            }

            $lines[$index][] = [
                'text' => $priceData['price'],
                'feed' => self::FEED_PRICE,
                'font' => 'bold',
                'align' => 'right',
            ];
            $lines[$index][] = [
                'text' => $priceData['subtotal'],
                'feed' => self::FEED_SUBTOTAL,
                'font' => 'bold',
                'align' => 'right',
            ];
            $index++;
        }

        $lines[0][] = [
            'text' => $order->formatPriceTxt($item->getTaxAmount()),
            'feed' => self::FEED_TAX,
            'font' => 'bold',
            'align' => 'right',
        ];

        foreach ($this->getItemOptions() as $option) {
            $lines[][] = [
                'text' => $this->string->split(
                    $this->filterManager->stripTags($option['label']),
                    self::WRAP_OPTION_LABEL,
                    true,
                    true
                ),
                'font' => 'italic',
                'feed' => self::FEED_NAME,
            ];

            if ($option['value'] === null) {
                continue;
            }

            $printValue = $option['print_value'] ?? $this->filterManager->stripTags($option['value']);
            $text = [];
            foreach (explode(', ', str_replace(PHP_EOL, ', ', $printValue)) as $value) {
                foreach ($this->string->split($value, self::WRAP_OPTION_VALUE, true, true) as $subValue) {
                    $text[] = $subValue;
                }
            }

            $lines[][] = ['text' => $text, 'feed' => self::FEED_OPTION_VALUE];
        }

        $lineBlock = ['lines' => $lines, 'height' => self::LINE_HEIGHT, 'shift' => self::LINE_SHIFT];

        $this->setPage($pdf->drawLineBlocks($page, [$lineBlock], ['table_header' => true]));
    }

    /**
     * Decode entities and, on an RTL store, reverse the run — the same preparation core's
     * DefaultInvoice applies before measuring and drawing a string.
     */
    private function prepareText(string $string): string
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        return $this->rtlTextHandler->reverseRtlText(html_entity_decode($string));
    }

    /**
     * The order item is its own order item.
     *
     * @param mixed $item
     * @return mixed
     */
    public function getSku($item)
    {
        return $item->getProductOptionByCode('simple_sku') ?: $item->getSku();
    }

    /**
     * @inheritDoc
     */
    public function getItemOptions()
    {
        $options = $this->getItem()->getProductOptions();
        if (!$options) {
            return [];
        }

        $result = [];
        foreach (['options', 'additional_options', 'attributes_info'] as $bucket) {
            if (isset($options[$bucket])) {
                $result[] = $options[$bucket];
            }
        }

        return array_merge([], ...$result);
    }
}
