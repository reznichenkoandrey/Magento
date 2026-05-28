<?php
declare(strict_types=1);

namespace Scr1be\HyvaLazyImages\ViewModel;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\ScopeInterface;

class LazyImage implements ArgumentInterface
{
    private const CONFIG_CDN_BASE     = 'scr1be_lazy_images/cdn/cdn_base';
    private const CONFIG_CDN_PATH     = 'scr1be_lazy_images/cdn/path_template';
    private const CONFIG_LQIP_SIZE    = 'scr1be_lazy_images/output/lqip_size';
    private const CONFIG_BREAKPOINTS  = 'scr1be_lazy_images/output/breakpoints';
    private const CONFIG_JPEG_QUALITY = 'scr1be_lazy_images/output/jpeg_quality';

    private const DEFAULT_CDN_PATH    = '/img';
    private const DEFAULT_LQIP_SIZE   = 32;
    private const DEFAULT_BREAKPOINTS = '480,768,1024,1440';
    private const DEFAULT_QUALITY     = 80;
    private const LQIP_CACHE_DIR      = 'lqip';

    private ?WriteInterface $varDir = null;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Filesystem $filesystem,
    ) {
    }

    /**
     * @return array{
     *     avif_srcset: string,
     *     webp_srcset: string,
     *     jpg_srcset:  string,
     *     lqip:        string,
     *     sizes:       string
     * }
     */
    public function generate(string $imagePath, string $sizes = '100vw'): array
    {
        $cdnBase = $this->getCdnBase();
        $breakpoints = $this->getBreakpoints();

        return [
            'avif_srcset' => $this->buildSrcset($cdnBase, $imagePath, 'avif', $breakpoints),
            'webp_srcset' => $this->buildSrcset($cdnBase, $imagePath, 'webp', $breakpoints),
            'jpg_srcset'  => $this->buildSrcset($cdnBase, $imagePath, 'jpg', $breakpoints),
            'lqip'        => $this->getLqip($imagePath),
            'sizes'       => $sizes,
        ];
    }

    private function buildSrcset(string $cdnBase, string $path, string $format, array $widths): string
    {
        $entries = [];
        foreach ($widths as $width) {
            $url = $this->buildUrl($cdnBase, $path, $format, $width);
            $entries[] = sprintf('%s %dw', $url, $width);
        }
        return implode(', ', $entries);
    }

    private function buildUrl(string $cdnBase, string $path, string $format, int $width): string
    {
        $query = http_build_query([
            'src'    => $path,
            'format' => $format,
            'w'      => $width,
            'q'      => $this->getJpegQuality(),
        ]);
        return rtrim($cdnBase, '/') . '/' . ltrim($this->getCdnPath(), '/') . '?' . $query;
    }

    private function getLqip(string $imagePath): string
    {
        $hash = substr(sha1($imagePath), 0, 16);
        $cachePath = self::LQIP_CACHE_DIR . '/' . $hash . '.txt';
        $varDir = $this->getVarDir();

        if ($varDir->isFile($cachePath)) {
            return 'data:image/jpeg;base64,' . trim($varDir->readFile($cachePath));
        }

        $size = (int) ($this->scopeConfig->getValue(self::CONFIG_LQIP_SIZE, ScopeInterface::SCOPE_STORE) ?: self::DEFAULT_LQIP_SIZE);
        $url = $this->buildUrl($this->getCdnBase(), $imagePath, 'jpg', $size);

        $raw = @file_get_contents($url);
        if ($raw === false) {
            return $this->getInlinePlaceholder();
        }

        $encoded = base64_encode($raw);
        $varDir->writeFile($cachePath, $encoded);

        return 'data:image/jpeg;base64,' . $encoded;
    }

    private function getInlinePlaceholder(): string
    {
        // 1x1 transparent gif fallback when CDN unreachable
        return 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
    }

    private function getCdnBase(): string
    {
        return (string) $this->scopeConfig->getValue(self::CONFIG_CDN_BASE, ScopeInterface::SCOPE_STORE);
    }

    private function getCdnPath(): string
    {
        $raw = (string) ($this->scopeConfig->getValue(self::CONFIG_CDN_PATH, ScopeInterface::SCOPE_STORE) ?: self::DEFAULT_CDN_PATH);
        return $raw === '' ? self::DEFAULT_CDN_PATH : $raw;
    }

    private function getJpegQuality(): int
    {
        $value = (int) ($this->scopeConfig->getValue(self::CONFIG_JPEG_QUALITY, ScopeInterface::SCOPE_STORE) ?: self::DEFAULT_QUALITY);
        // Clamp to a valid encoder range so an admin typo (e.g. 200) can't break the proxy URL.
        return max(1, min(100, $value));
    }

    /**
     * @return int[]
     */
    private function getBreakpoints(): array
    {
        $raw = (string) ($this->scopeConfig->getValue(self::CONFIG_BREAKPOINTS, ScopeInterface::SCOPE_STORE) ?: self::DEFAULT_BREAKPOINTS);
        return array_map('intval', array_filter(array_map('trim', explode(',', $raw))));
    }

    private function getVarDir(): WriteInterface
    {
        if ($this->varDir === null) {
            $this->varDir = $this->filesystem->getDirectoryWrite(\Magento\Framework\App\Filesystem\DirectoryList::VAR_DIR);
            if (!$this->varDir->isDirectory(self::LQIP_CACHE_DIR)) {
                $this->varDir->create(self::LQIP_CACHE_DIR);
            }
        }
        return $this->varDir;
    }
}
