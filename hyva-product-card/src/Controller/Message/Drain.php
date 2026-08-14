<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Controller\Message;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\MessageInterface;
use Magento\Framework\View\Element\Message\InterpretationStrategyInterface;
use Magento\Theme\CustomerData\MessagesProviderInterface;

/**
 * Takes the flash messages out of the session and hands them to the caller.
 *
 * Magento's flash messages are a *page-load* mechanism: `Checkout\Controller\Cart\Add` pushes "You
 * added Chaz Kangeroo Hoodie to your shopping cart." into the session with `addSuccessMessage()` and
 * the next rendered page prints it. It does that whether or not the request was an XHR — only the
 * *response* differs, because `goBack()` branches on `getRequest()->isAjax()` and answers a small
 * JSON body rather than a redirect. So when the card adds to cart over `fetch` there is no next
 * rendered page, the message sits in the session until something renders, and the shopper sees it
 * out of context whenever they next click a link. Two adds in a row, and the third page shows both.
 *
 * Core drains the queue in exactly one place: `Magento\Theme\CustomerData\MessagesProvider::
 * getMessages()` calls `$messageManager->getMessages(true)`, the `true` being the clear flag. That
 * provider is what the `messages` customer-data section is built on. This controller reuses the
 * same provider and the same `InterpretationStrategyInterface` the section uses, so the text a
 * shopper sees after an XHR add is byte-identical to the text they would have seen after a full
 * page load — and the queue is empty afterwards either way.
 *
 * It is a separate endpoint rather than a section reload because the card's *other* endpoint (stock
 * status) is deliberately session-free and CDN-cacheable; the card needs one place where being
 * session-bound and uncacheable is the point, and one where it is forbidden. Mixing them is how
 * private data ends up in a shared cache.
 */
class Drain implements HttpPostActionInterface
{
    public function __construct(
        private readonly JsonFactory $resultJsonFactory,
        private readonly MessagesProviderInterface $messagesProvider,
        private readonly InterpretationStrategyInterface $interpretationStrategy
    ) {
    }

    public function execute(): ResultInterface
    {
        $messages = [];
        foreach ($this->messagesProvider->getMessages()->getItems() as $message) {
            if (!$message instanceof MessageInterface) {
                continue;
            }
            $messages[] = [
                'type' => $message->getType(),
                'text' => $this->interpretationStrategy->interpret($message),
            ];
        }

        return $this->resultJsonFactory->create()
            // Whatever else happens, this response must never be stored. It is one shopper's
            // session state, and it is already gone by the time the response is written.
            ->setHeader('cache-control', 'no-store, no-cache, must-revalidate, max-age=0', true)
            ->setData(['messages' => $messages]);
    }
}
