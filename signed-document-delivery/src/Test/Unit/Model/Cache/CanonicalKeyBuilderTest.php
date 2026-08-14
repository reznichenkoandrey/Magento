<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Test\Unit\Model\Cache;

use Magento\Framework\Model\AbstractModel;
use PHPUnit\Framework\TestCase;
use Scr1be\SignedDocumentDelivery\Model\Cache\CanonicalKeyBuilder;
use Scr1be\SignedDocumentDelivery\Model\Document\LoadedDocument;
use Scr1be\SignedDocumentDelivery\Model\DocumentType;

class CanonicalKeyBuilderTest extends TestCase
{
    private CanonicalKeyBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new CanonicalKeyBuilder();
    }

    public function testTheKeyIsASha256Hex(): void
    {
        $key = $this->builder->build($this->document(), 42);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key);
    }

    public function testTheKeyIsTheHashOfTheCanonicalString(): void
    {
        $document = $this->document();

        $this->assertSame(
            hash('sha256', $this->builder->canonicalString($document, 42)),
            $this->builder->build($document, 42)
        );
    }

    public function testTheCanonicalStringHasEveryFieldThatChangesTheBytes(): void
    {
        $canonical = $this->builder->canonicalString($this->document(), 42);

        $this->assertSame(
            'v1|1|INVOICE|4|1|42|2026-08-01 10:00:00|2026-08-01 09:00:00',
            $canonical
        );
    }

    /**
     * Each of these is a reason two requests must not share a cached file.
     *
     * @dataProvider distinguishingFields
     */
    public function testTwoDocumentsThatRenderDifferentlyGetDifferentKeys(
        LoadedDocument $other,
        int $otherCustomerId
    ): void {
        $this->assertNotSame(
            $this->builder->build($this->document(), 42),
            $this->builder->build($other, $otherCustomerId)
        );
    }

    /**
     * @return array<string, array{0: LoadedDocument, 1: int}>
     */
    public static function distinguishingFields(): array
    {
        return [
            'a shipment numbered the same as the invoice' => [
                self::documentWith(type: DocumentType::SHIPMENT),
                42,
            ],
            'a different entity' => [self::documentWith(entityId: 5), 42],
            'the same invoice in another store view' => [self::documentWith(storeId: 2), 42],
            'the same invoice for another customer' => [self::documentWith(), 43],
            'the invoice after it was edited' => [
                self::documentWith(fingerprint: '2026-08-02 10:00:00|2026-08-01 09:00:00'),
                42,
            ],
            'the invoice after its order was edited' => [
                self::documentWith(fingerprint: '2026-08-01 10:00:00|2026-08-02 09:00:00'),
                42,
            ],
        ];
    }

    public function testTheSameRequestTwiceIsTheSameKey(): void
    {
        // Without this the cache is a write-only directory.
        $this->assertSame(
            $this->builder->build($this->document(), 42),
            $this->builder->build($this->document(), 42)
        );
    }

    public function testTheUidDoesNotAffectTheKey(): void
    {
        // A shipment can be asked for by increment id or by entity id; both resolve to the same
        // shipment and must therefore hit the same cached file.
        $this->assertSame(
            $this->builder->build(self::documentWith(uid: 'MDAwMDAwMDAx'), 42),
            $this->builder->build(self::documentWith(uid: 'NA=='), 42)
        );
    }

    private function document(): LoadedDocument
    {
        return self::documentWith();
    }

    private static function documentWith(
        DocumentType $type = DocumentType::INVOICE,
        string $uid = 'NA==',
        int $entityId = 4,
        int $storeId = 1,
        string $fingerprint = '2026-08-01 10:00:00|2026-08-01 09:00:00'
    ): LoadedDocument {
        return new LoadedDocument(
            $type,
            $uid,
            $entityId,
            '000000004',
            $storeId,
            $fingerprint,
            self::entity()
        );
    }

    /**
     * The key builder never touches the entity, so a bare AbstractModel is enough — and a static
     * data provider cannot reach PHPUnit's mock builder anyway. The empty constructor is what keeps
     * AbstractModel's Context and Registry out of it.
     */
    private static function entity(): AbstractModel
    {
        return new class extends AbstractModel {
            // phpcs:ignore Magento2.Functions.ConstructorEmptyBody
            public function __construct()
            {
            }
        };
    }
}
