<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Model\Resolver;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\GraphQl\Model\Query\ContextInterface;
use Scr1be\SignedDocumentDelivery\Exception\DocumentUnavailableException;
use Scr1be\SignedDocumentDelivery\Model\Config;
use Scr1be\SignedDocumentDelivery\Model\DocumentType;
use Scr1be\SignedDocumentDelivery\Model\DocumentUrlBuilder;
use Scr1be\SignedDocumentDelivery\Model\Renderer\RendererPool;
use Scr1be\SignedDocumentDelivery\Model\Token\TokenIssuer;

/**
 * `requestSalesDocument` — authorize now, render later.
 *
 * The mutation loads the document and runs the full ownership check before it signs anything. It
 * does *not* render: producing a PDF a client may never fetch would make an interactive mutation as
 * slow as the slowest document in the catalogue, and the download path has to authorize again
 * anyway. What the client gets back is a URL and an honest expiry.
 *
 * Failure modes are deliberately lopsided. "You are not logged in" is a
 * GraphQlAuthorizationException, because a client can fix that. Everything about *which* document —
 * missing, someone else's, wrong store — collapses into one GraphQlNoSuchEntityException with one
 * message, because distinguishing them would let a client enumerate the invoice table by watching
 * the error text.
 *
 * It is a mutation rather than a query even though it changes no state, and that is the right
 * classification for a different reason: GraphQL clients cache queries, and a short-lived signed
 * credential is the last thing that should be sitting in Apollo's normalised store keyed by its
 * arguments. Declaring it a mutation puts it on the side of the contract that is never cached and
 * never batched.
 */
class RequestSalesDocument implements ResolverInterface
{
    private const ARG_INPUT = 'input';
    private const ARG_TYPE = 'document_type';
    private const ARG_UID = 'uid';

    private const CONTENT_TYPE = 'application/pdf';

    public function __construct(
        private readonly RendererPool $rendererPool,
        private readonly TokenIssuer $tokenIssuer,
        private readonly DocumentUrlBuilder $urlBuilder,
        private readonly Config $config,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * @inheritDoc
     */
    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null)
    {
        /** @var ContextInterface $context */
        if ($context->getExtensionAttributes()->getIsCustomer() === false) {
            throw new GraphQlAuthorizationException(__('The current customer isn\'t authorized.'));
        }

        $input = $args[self::ARG_INPUT] ?? [];
        if (!is_array($input)) {
            throw new GraphQlInputException(__('Specify the "input" value.'));
        }

        $type = DocumentType::tryFrom((string) ($input[self::ARG_TYPE] ?? ''));
        if ($type === null) {
            // Unreachable through a valid query — the schema's enum rejects anything else first —
            // but the resolver is a public entry point and does not assume its caller was the
            // schema.
            throw new GraphQlInputException(__('Specify a supported "document_type".'));
        }

        $uid = trim((string) ($input[self::ARG_UID] ?? ''));
        if ($uid === '') {
            throw new GraphQlInputException(__('Specify the "uid" value.'));
        }

        $customerId = (int) $context->getUserId();
        $storeId = (int) $context->getExtensionAttributes()->getStore()->getId();

        try {
            $document = $this->rendererPool->get($type)->loadAndAuthorize($uid, $customerId, $storeId);
        } catch (DocumentUnavailableException $e) {
            throw new GraphQlNoSuchEntityException(__($e->getMessage()), $e);
        } catch (LocalizedException $e) {
            // A misconfigured renderer pool is an operator problem, not an input problem.
            throw new GraphQlInputException(__($e->getMessage()), $e);
        }

        $ttl = $this->config->getUrlTtl($storeId);
        $token = $this->tokenIssuer->issue($type, $uid, $customerId, $storeId, $ttl);

        return [
            'document_type' => $type->value,
            'uid' => $document->uid,
            'url' => $this->urlBuilder->build($token->value),
            'filename' => $document->filename(),
            'content_type' => self::CONTENT_TYPE,
            // Both forms, because clients want different ones: `expires_in` for a countdown that
            // does not care about clock skew, `expires_at` for a cache entry that does.
            'expires_in' => max(0, $token->payload->expiresAt - $this->dateTime->gmtTimestamp()),
            'expires_at' => gmdate(\DATE_ATOM, $token->payload->expiresAt),
        ];
    }
}
