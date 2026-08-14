<?php
declare(strict_types=1);

namespace Scr1be\PushNotifications\Test\Unit\Model\Fcm;

use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Scr1be\PushNotifications\Model\Fcm\ServiceAccount;

class ServiceAccountTest extends TestCase
{
    private Json $json;

    protected function setUp(): void
    {
        $this->json = new Json();
    }

    public function testParsesTheThreeFieldsItNeeds(): void
    {
        $account = ServiceAccount::fromJson($this->key(), $this->json);

        $this->assertSame('demo-project', $account->projectId);
        $this->assertSame('push@demo-project.iam.gserviceaccount.com', $account->clientEmail);
        $this->assertStringStartsWith('-----BEGIN PRIVATE KEY-----', $account->privateKey);
    }

    /**
     * A key pasted through anything that escapes newlines arrives with literal `\n` sequences, and
     * OpenSSL rejects the PEM with an error code that says nothing about why.
     */
    public function testTurnsEscapedNewlinesBackIntoRealOnes(): void
    {
        $account = ServiceAccount::fromJson($this->key(), $this->json);

        $this->assertStringNotContainsString('\\n', $account->privateKey);
        $this->assertSame(3, substr_count($account->privateKey, "\n"));
    }

    /**
     * @dataProvider malformedKeys
     */
    public function testRejectsAMalformedKeyWithAReason(string $json, string $expectedFragment): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expectedFragment, '/') . '/');

        ServiceAccount::fromJson($json, $this->json);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function malformedKeys(): array
    {
        return [
            'empty' => ['', 'No service account key'],
            'not json' => ['{nope', 'not valid JSON'],
            'not an object' => ['"a string"', 'not a JSON object'],
            'no project' => ['{"client_email":"a@b","private_key":"x"}', 'project_id'],
            'no email' => ['{"project_id":"p","private_key":"x"}', 'client_email'],
            'no key' => ['{"project_id":"p","client_email":"a@b"}', 'private_key'],
            'blank field' => ['{"project_id":"  ","client_email":"a@b","private_key":"x"}', 'project_id'],
        ];
    }

    private function key(): string
    {
        return (string)json_encode([
            'type' => 'service_account',
            'project_id' => 'demo-project',
            'client_email' => 'push@demo-project.iam.gserviceaccount.com',
            'private_key' => '-----BEGIN PRIVATE KEY-----\nMIIE\n-----END PRIVATE KEY-----\n',
        ]);
    }
}
