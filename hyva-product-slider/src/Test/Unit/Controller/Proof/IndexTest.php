<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Test\Unit\Controller\Proof;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\HyvaProductSlider\Controller\Proof\Index;
use Scr1be\HyvaProductSlider\Model\Config;
use Scr1be\HyvaProductSlider\Model\SocialProof\ProofBuilder;

class IndexTest extends TestCase
{
    private RequestInterface&MockObject $request;
    private ProofBuilder&MockObject $proofBuilder;
    private Config&MockObject $config;
    private Json&MockObject $result;

    protected function setUp(): void
    {
        $this->request = $this->createMock(RequestInterface::class);
        $this->proofBuilder = $this->createMock(ProofBuilder::class);

        $this->config = $this->createMock(Config::class);
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getProofEndpointTtl')->willReturn(120);

        $this->result = $this->createMock(Json::class);
        $this->result->method('setHeader')->willReturnSelf();
        $this->result->method('setData')->willReturnSelf();
    }

    public function testIdsAreSortedSoThatTwoSlidersShareOneCacheEntry(): void
    {
        // The url is the cache key. Two sliders holding the same products in a different order must
        // not mint two entries for one answer.
        $this->stubIds('9,3,7');

        $this->proofBuilder->expects($this->once())
            ->method('build')
            ->with([3, 7, 9], 1)
            ->willReturn([]);

        $this->controller()->execute();
    }

    public function testRepeatedIdsAreCollapsed(): void
    {
        $this->stubIds('4,4,4');

        $this->proofBuilder->expects($this->once())->method('build')->with([4], 1)->willReturn([]);

        $this->controller()->execute();
    }

    public function testGarbageIdsAreDropped(): void
    {
        $this->stubIds('4,,abc,-2,0, 5 ');

        $this->proofBuilder->expects($this->once())->method('build')->with([4, 5], 1)->willReturn([]);

        $this->controller()->execute();
    }

    public function testTheIdListIsCappedSoTheEndpointCannotBeWalked(): void
    {
        $this->stubIds(implode(',', range(1, 200)));

        $this->proofBuilder->expects($this->once())
            ->method('build')
            ->with($this->countOf(60), 1)
            ->willReturn([]);

        $this->controller()->execute();
    }

    public function testAnEmptyIdListNeverReachesTheBuilder(): void
    {
        $this->stubIds('');

        $this->proofBuilder->expects($this->never())->method('build');

        $this->controller()->execute();
    }

    public function testThePublicHeaderIsWhatMakesTheResponseCacheableAtAllInFrontOfVarnish(): void
    {
        // The shipped varnish7.vcl marks any response whose Cache-Control matches "private"
        // uncacheable — which is exactly what a Magento controller emits if left alone.
        $this->stubIds('1');
        $this->proofBuilder->method('build')->willReturn([]);

        $this->result->expects($this->once())
            ->method('setHeader')
            ->with('cache-control', 'public, max-age=120, s-maxage=120', true)
            ->willReturnSelf();

        $this->controller()->execute();
    }

    public function testATtlOfZeroMeansNoStoreRatherThanAZeroMaxAge(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('getProofEndpointTtl')->willReturn(0);

        $this->stubIds('1');
        $this->proofBuilder->method('build')->willReturn([]);

        $this->result->expects($this->once())
            ->method('setHeader')
            ->with('cache-control', 'no-store, no-cache, must-revalidate, max-age=0', true)
            ->willReturnSelf();

        $this->controller($config)->execute();
    }

    public function testTheModuleSwitchStopsTheQueryButStillSendsTheHeader(): void
    {
        // The header comes first on purpose: a response that answers "nothing" without a caching
        // instruction is a response the CDN has to ask for again on every card.
        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn(false);
        $config->method('getProofEndpointTtl')->willReturn(120);

        $this->stubIds('1,2');
        $this->proofBuilder->expects($this->never())->method('build');
        $this->result->expects($this->once())->method('setHeader')->willReturnSelf();

        $this->controller($config)->execute();
    }

    private function stubIds(string $ids): void
    {
        $this->request->method('getParam')->willReturn($ids);
    }

    private function controller(?Config $config = null): Index
    {
        $resultFactory = $this->createMock(JsonFactory::class);
        $resultFactory->method('create')->willReturn($this->result);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new Index(
            $this->request,
            $resultFactory,
            $this->proofBuilder,
            $storeManager,
            $config ?? $this->config
        );
    }
}
