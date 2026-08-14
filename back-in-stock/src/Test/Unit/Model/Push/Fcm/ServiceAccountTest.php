<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Test\Unit\Model\Push\Fcm;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Scr1be\BackInStock\Model\Push\Fcm\ServiceAccount;

class ServiceAccountTest extends TestCase
{
    private Json $serializer;

    protected function setUp(): void
    {
        $this->serializer = new Json();
    }

    public function testAKeyFileIsReadIntoTheFourFieldsThatMatter(): void
    {
        $account = ServiceAccount::fromJson($this->keyFile(), $this->serializer);

        $this->assertSame('push@my-project.iam.gserviceaccount.com', $account->clientEmail);
        $this->assertSame('my-project', $account->projectId);
        $this->assertSame('https://oauth2.googleapis.com/token', $account->tokenUri);
    }

    public function testAPemThatCameThroughUnharmedIsLeftAlone(): void
    {
        $account = ServiceAccount::fromJson($this->keyFile(), $this->serializer);

        $this->assertStringContainsString("-----BEGIN PRIVATE KEY-----\nMIIB", $account->privateKey);
    }

    public function testADoubleEscapedPrivateKeyIsRepairedRatherThanHandedToOpenssl(): void
    {
        // A key that travelled through an env var or a second round of JSON encoding arrives with a
        // literal backslash-n where the PEM needs a line break. `openssl_sign()`'s answer to that is
        // "cannot be coerced into a private key", which names neither the field nor the cause.
        // The JSON below carries `\\n`, which decodes to two characters rather than to a newline.
        $json = '{"client_email":"a@b.c","private_key":"-----BEGIN PRIVATE KEY-----\\\\nMIIB\\\\n-----END PRIVATE KEY-----"}';

        $account = ServiceAccount::fromJson($json, $this->serializer);

        $this->assertStringContainsString("-----BEGIN PRIVATE KEY-----\nMIIB", $account->privateKey);
        $this->assertStringNotContainsString('\n', $account->privateKey);
    }

    public function testAKeyFileWithoutATokenUriFallsBackToGoogles(): void
    {
        $json = '{"client_email":"a@b.c","private_key":"key"}';

        $this->assertSame(
            ServiceAccount::DEFAULT_TOKEN_URI,
            ServiceAccount::fromJson($json, $this->serializer)->tokenUri
        );
    }

    public function testABlankTokenUriIsTreatedAsAbsentRatherThanAsAUrl(): void
    {
        $json = '{"client_email":"a@b.c","private_key":"key","token_uri":"   "}';

        $this->assertSame(
            ServiceAccount::DEFAULT_TOKEN_URI,
            ServiceAccount::fromJson($json, $this->serializer)->tokenUri
        );
    }

    public function testAnUnconfiguredChannelSaysSoRatherThanFailingLater(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('No Firebase service account is configured.');

        ServiceAccount::fromJson('   ', $this->serializer);
    }

    public function testATruncatedPasteIsRejectedByName(): void
    {
        // The failure everybody hits, and the message is the whole value of catching it here.
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The Firebase service account is missing "private_key".');

        ServiceAccount::fromJson('{"client_email":"a@b.c"}', $this->serializer);
    }

    public function testAnEmptyRequiredFieldCountsAsMissing(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The Firebase service account is missing "client_email".');

        ServiceAccount::fromJson('{"client_email":"  ","private_key":"key"}', $this->serializer);
    }

    public function testSomethingThatIsNotJsonIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The Firebase service account is not valid JSON.');

        ServiceAccount::fromJson('-----BEGIN PRIVATE KEY-----', $this->serializer);
    }

    public function testJsonThatIsNotAnObjectIsRejected(): void
    {
        $this->expectException(LocalizedException::class);

        ServiceAccount::fromJson('"a string"', $this->serializer);
    }

    private function keyFile(): string
    {
        return '{'
            . '"type":"service_account",'
            . '"project_id":"my-project",'
            . '"private_key":"-----BEGIN PRIVATE KEY-----\\nMIIB\\n-----END PRIVATE KEY-----\\n",'
            . '"client_email":"push@my-project.iam.gserviceaccount.com",'
            . '"token_uri":"https://oauth2.googleapis.com/token"'
            . '}';
    }
}
