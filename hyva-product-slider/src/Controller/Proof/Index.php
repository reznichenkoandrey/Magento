<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Controller\Proof;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Scr1be\HyvaProductSlider\Model\Config;
use Scr1be\HyvaProductSlider\Model\SocialProof\Proof;
use Scr1be\HyvaProductSlider\Model\SocialProof\ProofBuilder;

/**
 * The volatile half of a slider, served separately so the other half can stay cached.
 *
 * The slider's HTML is block-cached and page-cached; "17 minutes ago" is true for one minute. Baking
 * the line into the markup would force the whole carousel to be uncacheable to keep one sentence
 * fresh. Fetching it afterwards inverts that: the expensive part is cached for an hour, the cheap
 * part is a small JSON response with a short public TTL.
 *
 * The response is identical for every visitor — no prices, no customer data, no session — which is
 * what makes `public, max-age` honest. Two things had to be true for that, and the second is the one
 * that is easy to miss:
 *
 * 1. **No session may start.** {@see \Scr1be\HyvaProductSlider\Plugin\Session\SuppressProofEndpointSession}.
 * 2. **The `Cache-Control` header must say `public`.** The VCL Magento ships
 *    (`module-page-cache/etc/varnish7.vcl`) treats any response matching `Cache-Control ~ "private"`
 *    as uncacheable, and that is exactly what a Magento controller emits if left alone.
 */
class Index implements HttpGetActionInterface
{
    /**
     * A slider holds at most a few dozen products. A longer list is somebody walking the catalogue
     * through a cacheable endpoint, minting one cache entry per permutation.
     */
    private const MAX_IDS = 60;

    private const PARAM_IDS = 'ids';

    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly ProofBuilder $proofBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config
    ) {
    }

    public function execute(): ResultInterface
    {
        $storeId = $this->getStoreId();
        /** @var Json $result */
        $result = $this->resultJsonFactory->create();

        $ttl = $this->config->getProofEndpointTtl($storeId);
        $result->setHeader(
            'cache-control',
            $ttl > 0
                ? sprintf('public, max-age=%d, s-maxage=%d', $ttl, $ttl)
                : 'no-store, no-cache, must-revalidate, max-age=0',
            true
        );

        if (!$this->config->isEnabled($storeId)) {
            return $result->setData(['items' => []]);
        }

        $productIds = $this->readIds();
        if ($productIds === []) {
            return $result->setData(['items' => []]);
        }

        $items = [];
        foreach ($this->proofBuilder->build($productIds, $storeId) as $productId => $proof) {
            /** @var Proof $proof */
            $items[$productId] = $proof->jsonSerialize();
        }

        return $result->setData(['items' => $items]);
    }

    /**
     * @return int[]
     */
    private function readIds(): array
    {
        $raw = (string) $this->request->getParam(self::PARAM_IDS, '');

        $ids = [];
        foreach (explode(',', $raw) as $candidate) {
            $id = (int) trim($candidate);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        // Sorted so that two sliders showing the same products in a different order share one cache
        // entry instead of minting two.
        ksort($ids);

        return array_slice(array_values($ids), 0, self::MAX_IDS);
    }

    private function getStoreId(): int
    {
        try {
            return (int) $this->storeManager->getStore()->getId();
        } catch (NoSuchEntityException) {
            return 0;
        }
    }
}
