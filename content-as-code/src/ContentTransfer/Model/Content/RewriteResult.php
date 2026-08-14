<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Model\Content;

/**
 * Rewritten text plus the running commentary the capture prints.
 */
class RewriteResult
{
    /**
     * @param string[] $transforms
     * @param string[] $warnings
     */
    public function __construct(
        private readonly string $content,
        private readonly array $transforms = [],
        private readonly array $warnings = []
    ) {
    }

    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * @return string[]
     */
    public function getTransforms(): array
    {
        return $this->transforms;
    }

    /**
     * @return string[]
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }
}
