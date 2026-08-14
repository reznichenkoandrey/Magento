<?php
declare(strict_types=1);

namespace Scr1be\FpcInspector\Model;

use Magento\Framework\App\Request\Http as HttpRequest;

/**
 * The four questions asked before either hook does any work, in ascending order of cost.
 *
 * The re-entrancy check comes first and is a plain property read, because it is the one that has to
 * hold even when everything else says yes: the no-cache hook asks the HTTP context for a vary
 * string while assembling its record, and that question travels through the interceptor the vary
 * hook is attached to.
 */
class RecordingGate
{
    public function __construct(
        private readonly Config $config,
        private readonly RequestScope $scope,
        private readonly HttpRequest $request
    ) {
    }

    public function allows(string $channel): bool
    {
        if ($this->scope->isRecording()) {
            return false;
        }

        if (!$this->config->isEnabled()) {
            return false;
        }

        if (!$this->isChannelEnabled($channel)) {
            return false;
        }

        return $this->config->matchesUri($this->request->getUriString());
    }

    private function isChannelEnabled(string $channel): bool
    {
        return match ($channel) {
            RecordBuilder::CHANNEL_VARY => $this->config->isVaryChannelEnabled(),
            RecordBuilder::CHANNEL_NO_CACHE => $this->config->isNoCacheChannelEnabled(),
            default => false,
        };
    }
}
