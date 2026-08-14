<?php
declare(strict_types=1);

namespace Scr1be\SocialLogin\Model\Cache;

use Magento\Framework\App\Cache\Type\FrontendPool;
use Magento\Framework\Cache\Frontend\Decorator\TagScope;

/**
 * Dedicated cache type for provider signing keys.
 *
 * A type of its own rather than a corner of `config`, for two reasons. It has to be flushable on its
 * own — when a provider rotates a key early, the fix is to purge these entries, and telling an
 * operator to flush the configuration cache on a live site to fix a login problem is not a fix. And
 * it has to survive `cache:flush` being scoped to something else: JWKS is the only cached thing here
 * whose absence costs an outbound HTTPS round trip per sign-in.
 */
class JwksCache extends TagScope
{
    public const TYPE_IDENTIFIER = 'scr1be_social_jwks';
    public const CACHE_TAG = 'SCR1BE_SOCIAL_JWKS';

    /**
     * @param FrontendPool $cacheFrontendPool
     */
    public function __construct(FrontendPool $cacheFrontendPool)
    {
        parent::__construct($cacheFrontendPool->get(self::TYPE_IDENTIFIER), self::CACHE_TAG);
    }
}
