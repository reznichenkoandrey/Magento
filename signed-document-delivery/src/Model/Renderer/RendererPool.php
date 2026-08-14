<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Model\Renderer;

use Magento\Framework\Exception\ConfigurationMismatchException;
use Scr1be\SignedDocumentDelivery\Model\DocumentType;

/**
 * The registry: DocumentType → renderer, wired in di.xml.
 *
 * A map rather than a switch so a fifth document type is an entry in somebody else's di.xml and
 * nothing else. The map is validated in the constructor rather than on first use, because a
 * mistyped class name in di.xml should fail at compile time and not on the one request a year that
 * asks for a credit memo.
 */
class RendererPool
{
    /**
     * @var array<string, DocumentRendererInterface>
     */
    private readonly array $renderers;

    /**
     * @param array<string, DocumentRendererInterface> $renderers Keyed by DocumentType value
     * @throws ConfigurationMismatchException
     */
    public function __construct(array $renderers)
    {
        foreach ($renderers as $type => $renderer) {
            if (DocumentType::tryFrom((string) $type) === null) {
                throw new ConfigurationMismatchException(
                    __('"%1" is not a document type this module knows about.', $type)
                );
            }

            if (!$renderer instanceof DocumentRendererInterface) {
                throw new ConfigurationMismatchException(
                    __('The renderer registered for "%1" does not implement DocumentRendererInterface.', $type)
                );
            }
        }

        $this->renderers = $renderers;
    }

    /**
     * @throws ConfigurationMismatchException When a supported type has no renderer wired to it
     */
    public function get(DocumentType $type): DocumentRendererInterface
    {
        if (!isset($this->renderers[$type->value])) {
            throw new ConfigurationMismatchException(
                __('No renderer is registered for "%1" documents.', $type->value)
            );
        }

        return $this->renderers[$type->value];
    }

    /**
     * @return DocumentType[]
     */
    public function supportedTypes(): array
    {
        return array_values(
            array_filter(
                array_map(
                    static fn (string $type): ?DocumentType => DocumentType::tryFrom($type),
                    array_keys($this->renderers)
                )
            )
        );
    }
}
