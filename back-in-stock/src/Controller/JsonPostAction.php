<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Controller;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;

/**
 * The shared half of the three JSON endpoints: form-key validation that an XHR cannot skip.
 *
 * This is not belt and braces. `Magento\Framework\App\Request\CsrfValidator::validateRequest()`
 * reads, in full:
 *
 * ```php
 * $valid = !$request->isPost()
 *     || $request->isXmlHttpRequest()
 *     || $this->formKeyValidator->validate($request);
 * ```
 *
 * — so any POST that carries `X-Requested-With: XMLHttpRequest` is waved through *without* a form
 * key, on every controller in Magento that does not implement `CsrfAwareActionInterface`. That
 * header is not a secret and not a preflight-triggering one in the general case; treating its
 * presence as proof of same-origin intent is exactly the assumption CSRF tokens exist to avoid.
 *
 * Implementing `validateForCsrf()` returns a non-null value, which the same method short-circuits
 * on, so these endpoints are checked whether or not the caller claims to be an XHR.
 */
abstract class JsonPostAction implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        protected readonly RequestInterface $request,
        protected readonly JsonFactory $resultJsonFactory,
        private readonly FormKeyValidator $formKeyValidator
    ) {
    }

    /**
     * @inheritdoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        // Core's default is a 302 to the referer with a session message on it. These endpoints are
        // called by `fetch()`, where a redirect is followed silently and the caller is handed the
        // HTML of some other page to parse as JSON.
        return new InvalidRequestException(
            $this->json(403, ['success' => false, 'message' => __('Invalid form key. Please refresh the page.')])
        );
    }

    /**
     * @inheritdoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return $this->formKeyValidator->validate($request);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function json(int $status, array $data): Json
    {
        return $this->resultJsonFactory->create()
            ->setHttpResponseCode($status)
            ->setData($data);
    }

    /**
     * Alert ids as the browser sends them: either a repeated `alert_ids[]` field or a comma
     * separated list, normalised to positive ints with the duplicates gone.
     *
     * @return int[]
     */
    protected function readAlertIds(string $parameter = 'alert_ids'): array
    {
        $raw = $this->request->getParam($parameter);

        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }

        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int)$id, $raw),
            static fn (int $id): bool => $id > 0
        )));
    }
}
