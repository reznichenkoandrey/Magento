<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Model\Pdf;

use Magento\Framework\DataObject;
use Magento\Sales\Model\Order as OrderModel;
use Magento\Sales\Model\Order\Pdf\AbstractPdf;
use Magento\Sales\Model\Order\Pdf\Config;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The order PDF Magento does not ship.
 *
 * `Magento\Sales\Model\Order\Pdf` ships exactly three concrete PDF models — Invoice, Shipment and
 * Creditmemo — beside AbstractPdf, the renderer config and the item/total renderer trees. The
 * closest core gets to an order document is the `sales/order/print` route, and
 * `Magento\Sales\Controller\AbstractController\PrintAction::execute()` returns a `Result\Page` with
 * the `print` layout handle added — an HTML page, not a file. Since the brief covers four document
 * types and core covers three, this is the fourth, built on the same
 * `AbstractPdf` base the other three use rather than on a second PDF stack: same fonts, same page
 * geometry, same logo and address blocks, same `insertOrder()` header, and the same item-renderer
 * pool, extended with an `order` page type in this module's etc/pdf.xml.
 *
 * Two things are deliberately *not* inherited.
 *
 * `AbstractPdf::_drawItem()` resolves the renderer with `$item->getOrderItem()->getProductType()`,
 * which is how an invoice line finds the order line behind it. An order line has no `getOrderItem()`
 * — the magic getter returns null and the next arrow fatals — so `_drawItem()` is overridden to read
 * the product type off the item itself.
 *
 * `AbstractPdf::insertTotals()` is not reused either, and this one is worth being explicit about
 * because the failure is data-dependent. It starts with `$order = $source->getOrder()` and hands
 * that to every total model; `Magento\Sales\Model\Order\Pdf\Total\DefaultTotal::getTitleDescription()`
 * then calls `$this->getSource()->getOrder()->getData(...)`. When the source *is* the order,
 * `getOrder()` is an unset magic getter returning null. Nothing breaks on an order without a
 * discount, because `getTitleDescription()` is only reached for a total that declares
 * `title_source_field` and passes `canDisplay()` — and in Magento_Sales' pdf.xml exactly one does,
 * `discount`, with `display_zero` false. So the inherited version would render every order in
 * testing and fatal on the first one with a coupon on it. This class draws its own totals block
 * instead.
 */
class Order extends AbstractPdf
{
    /**
     * The page type this class initialises its item renderers from; see etc/pdf.xml.
     */
    public const PAGE_TYPE = 'order';

    private const PAGE_TOP = 800;

    private const HEADER_LEFT = 25;
    private const HEADER_RIGHT = 570;
    private const HEADER_HEIGHT = 15;

    private const FEED_NAME = 35;
    private const FEED_SKU = 290;
    private const FEED_PRICE = 360;
    private const FEED_QTY = 435;
    private const FEED_TAX = 495;
    private const FEED_SUBTOTAL = 565;

    private const TOTALS_LABEL_FEED = 475;
    private const TOTALS_AMOUNT_FEED = 565;
    private const TOTALS_TOP_GAP = 20;
    private const TOTALS_LINE_HEIGHT = 15;
    private const TOTALS_FONT_SIZE = 8;
    private const GRAND_TOTAL_FONT_SIZE = 10;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var Emulation
     */
    private $appEmulation;

    /**
     * @param \Magento\Payment\Helper\Data $paymentData
     * @param \Magento\Framework\Stdlib\StringUtils $string
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Framework\Filesystem $filesystem
     * @param Config $pdfConfig
     * @param \Magento\Sales\Model\Order\Pdf\Total\Factory $pdfTotalFactory
     * @param \Magento\Sales\Model\Order\Pdf\ItemsFactory $pdfItemsFactory
     * @param \Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate
     * @param \Magento\Framework\Translate\Inline\StateInterface $inlineTranslation
     * @param \Magento\Sales\Model\Order\Address\Renderer $addressRenderer
     * @param StoreManagerInterface $storeManager
     * @param Emulation $appEmulation
     * @param array $data
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\Stdlib\StringUtils $string,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\Filesystem $filesystem,
        Config $pdfConfig,
        \Magento\Sales\Model\Order\Pdf\Total\Factory $pdfTotalFactory,
        \Magento\Sales\Model\Order\Pdf\ItemsFactory $pdfItemsFactory,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate,
        \Magento\Framework\Translate\Inline\StateInterface $inlineTranslation,
        \Magento\Sales\Model\Order\Address\Renderer $addressRenderer,
        StoreManagerInterface $storeManager,
        Emulation $appEmulation,
        array $data = []
    ) {
        $this->storeManager = $storeManager;
        $this->appEmulation = $appEmulation;
        parent::__construct(
            $paymentData,
            $string,
            $scopeConfig,
            $filesystem,
            $pdfConfig,
            $pdfTotalFactory,
            $pdfItemsFactory,
            $localeDate,
            $inlineTranslation,
            $addressRenderer,
            $data
        );
    }

    /**
     * Return the PDF document for the given orders.
     *
     * @param OrderModel[] $orders
     * @return \Zend_Pdf
     */
    public function getPdf($orders = [])
    {
        $this->_beforeGetPdf();
        $this->_initRenderer(self::PAGE_TYPE);

        $pdf = new \Zend_Pdf();
        $this->_setPdf($pdf);

        foreach ($orders as $order) {
            // Same emulation core's Invoice::getPdf() performs: the logo, the store address, the
            // currency and the translations all belong to the store the order was placed in, not to
            // the store that happens to be current on the request.
            if ($order->getStoreId()) {
                $this->appEmulation->startEnvironmentEmulation(
                    $order->getStoreId(),
                    \Magento\Framework\App\Area::AREA_FRONTEND,
                    true
                );
                $this->storeManager->setCurrentStore($order->getStoreId());
            }

            $page = $this->newPage();
            $this->insertLogo($page, $order->getStore());
            $this->insertAddress($page, $order->getStore());
            // False: insertOrder() would print "Order # …" inside the grey header rectangle, and
            // insertDocumentNumber() is about to print the same number in the same rectangle —
            // it draws at the coordinates insertOrder() just recorded with setDocHeaderCoordinates().
            $this->insertOrder($page, $order, false);
            $this->insertDocumentNumber($page, __('Order # ') . $order->getRealOrderId());
            $this->_drawHeader($page);

            foreach ($order->getAllVisibleItems() as $item) {
                $this->_drawItem($item, $page, $order);
                $page = end($pdf->pages);
            }

            $this->insertOrderTotals($page, $order);

            if ($order->getStoreId()) {
                $this->appEmulation->stopEnvironmentEmulation();
            }
        }

        $this->_afterGetPdf();

        return $pdf;
    }

    /**
     * Create a new page and assign it to the PDF object.
     *
     * @param array $settings
     * @return \Zend_Pdf_Page
     */
    public function newPage(array $settings = [])
    {
        $page = $this->_getPdf()->newPage(\Zend_Pdf_Page::SIZE_A4);
        $this->_getPdf()->pages[] = $page;
        $this->y = self::PAGE_TOP;

        if (!empty($settings['table_header'])) {
            $this->_drawHeader($page);
        }

        return $page;
    }

    /**
     * Draw the item table heading.
     *
     * @param \Zend_Pdf_Page $page
     * @return void
     */
    protected function _drawHeader(\Zend_Pdf_Page $page)
    {
        $this->_setFontRegular($page, 10);
        $page->setFillColor(new \Zend_Pdf_Color_Rgb(0.93, 0.92, 0.92));
        $page->setLineColor(new \Zend_Pdf_Color_GrayScale(0.5));
        $page->setLineWidth(0.5);
        $page->drawRectangle(self::HEADER_LEFT, $this->y, self::HEADER_RIGHT, $this->y - self::HEADER_HEIGHT);
        $this->y -= 10;
        $page->setFillColor(new \Zend_Pdf_Color_Rgb(0, 0, 0));

        $lines = [[
            ['text' => __('Products'), 'feed' => self::FEED_NAME],
            ['text' => __('SKU'), 'feed' => self::FEED_SKU, 'align' => 'right'],
            ['text' => __('Price'), 'feed' => self::FEED_PRICE, 'align' => 'right'],
            ['text' => __('Qty'), 'feed' => self::FEED_QTY, 'align' => 'right'],
            ['text' => __('Tax'), 'feed' => self::FEED_TAX, 'align' => 'right'],
            ['text' => __('Subtotal'), 'feed' => self::FEED_SUBTOTAL, 'align' => 'right'],
        ]];

        $this->drawLineBlocks($page, [['lines' => $lines, 'height' => 5]], ['table_header' => true]);
        $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0));
        $this->y -= 20;
    }

    /**
     * Draw one order line through the renderer registered for its product type.
     *
     * @param DataObject $item
     * @param \Zend_Pdf_Page $page
     * @param OrderModel $order
     * @return \Zend_Pdf_Page
     */
    protected function _drawItem(DataObject $item, \Zend_Pdf_Page $page, OrderModel $order)
    {
        $renderer = $this->_getRenderer($item->getProductType());
        $renderer->setOrder($order);
        $renderer->setItem($item);
        $renderer->setPdf($this);
        $renderer->setPage($page);
        // Core sets this too, and third-party item renderers registered against our page type may
        // read it back through the magic getter.
        $renderer->setRenderedModel($this);

        $renderer->draw();

        return $renderer->getPage();
    }

    /**
     * Draw the totals block.
     *
     * Shipping is omitted for virtual orders, where core leaves `shipping_amount` at zero and a
     * "Shipping & Handling: $0.00" line is noise. Discount and tax are omitted when zero for the
     * same reason — which is also what Magento_Sales' own pdf.xml asks for through `display_zero`.
     *
     * @param \Zend_Pdf_Page $page
     * @param OrderModel $order
     * @return \Zend_Pdf_Page
     */
    private function insertOrderTotals(\Zend_Pdf_Page $page, OrderModel $order): \Zend_Pdf_Page
    {
        $totals = [
            [__('Subtotal'), (float) $order->getSubtotal(), true],
            [__('Discount'), (float) $order->getDiscountAmount(), false],
            [__('Shipping & Handling'), (float) $order->getShippingAmount(), !$order->getIsVirtual()],
            [__('Tax'), (float) $order->getTaxAmount(), false],
        ];

        $lines = [];
        foreach ($totals as [$label, $amount, $alwaysShow]) {
            if (!$alwaysShow && $amount == 0.0) {
                continue;
            }

            $lines[] = $this->totalsLine($label, $order->formatPriceTxt($amount), self::TOTALS_FONT_SIZE);
        }

        $lines[] = $this->totalsLine(
            __('Grand Total'),
            $order->formatPriceTxt((float) $order->getGrandTotal()),
            self::GRAND_TOTAL_FONT_SIZE
        );

        $this->y -= self::TOTALS_TOP_GAP;

        return $this->drawLineBlocks($page, [['lines' => $lines, 'height' => self::TOTALS_LINE_HEIGHT]]);
    }

    /**
     * One right-aligned label/amount pair in the shape drawLineBlocks() expects.
     *
     * @param \Magento\Framework\Phrase|string $label
     * @param string $amount
     * @param int $fontSize
     * @return array
     */
    private function totalsLine($label, string $amount, int $fontSize): array
    {
        return [
            [
                'text' => $label . ':',
                'feed' => self::TOTALS_LABEL_FEED,
                'align' => 'right',
                'font_size' => $fontSize,
                'font' => 'bold',
            ],
            [
                'text' => $amount,
                'feed' => self::TOTALS_AMOUNT_FEED,
                'align' => 'right',
                'font_size' => $fontSize,
                'font' => 'bold',
            ],
        ];
    }
}
