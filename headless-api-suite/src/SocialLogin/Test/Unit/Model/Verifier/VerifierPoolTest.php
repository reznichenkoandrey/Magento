<?php
declare(strict_types=1);

namespace Scr1be\SocialLogin\Test\Unit\Model\Verifier;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\SocialLogin\Model\SocialLoginException;
use Scr1be\SocialLogin\Model\Verifier\VerifierInterface;
use Scr1be\SocialLogin\Model\Verifier\VerifierPool;

class VerifierPoolTest extends TestCase
{
    public function testResolvesAConfiguredProvider(): void
    {
        $google = $this->verifier('google', true);
        $pool = new VerifierPool(['google' => $google]);

        $this->assertSame($google, $pool->get('google', 1));
    }

    /**
     * "No such provider" and "provider switched off for this store" get the same answer. Telling an
     * unauthenticated caller which one it is describes the merchant's setup for free.
     */
    public function testAnUnknownAndADisabledProviderFailIdentically(): void
    {
        $pool = new VerifierPool(['apple' => $this->verifier('apple', false)]);

        $unknown = $this->captureFailure($pool, 'facebook');
        $disabled = $this->captureFailure($pool, 'apple');

        $this->assertSame($unknown[0], $disabled[0]);
        $this->assertSame($unknown[1], $disabled[1]);
        $this->assertSame(SocialLoginException::PROVIDER_UNAVAILABLE, $unknown[0]);
    }

    public function testListsOnlyAvailableCodes(): void
    {
        $pool = new VerifierPool([
            'google' => $this->verifier('google', true),
            'apple' => $this->verifier('apple', false),
        ]);

        $this->assertSame(['google'], $pool->getAvailableCodes(1));
    }

    /**
     * A mistyped class in di.xml should fail when the container is built, not on somebody's sign-in.
     */
    public function testRefusesAPoolEntryThatIsNotAVerifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new VerifierPool(['bogus' => new \stdClass()]);
    }

    /**
     * The array key in di.xml is a label; the provider code is what the verifier says it is. Keying
     * on the class's own answer keeps the mutation argument and the database column in step even if
     * somebody names the XML item something else.
     */
    public function testTheProviderCodeComesFromTheVerifierNotTheArrayKey(): void
    {
        $pool = new VerifierPool(['anything_at_all' => $this->verifier('google', true)]);

        $this->assertSame(['google'], $pool->getAvailableCodes(1));
    }

    /**
     * @param VerifierPool $pool
     * @param string $code
     * @return array{0: string, 1: string}
     */
    private function captureFailure(VerifierPool $pool, string $code): array
    {
        try {
            $pool->get($code, 1);
            $this->fail('Expected refusal for ' . $code);
        } catch (SocialLoginException $e) {
            return [$e->getErrorCode(), $e->getMessage()];
        }
    }

    private function verifier(string $code, bool $available): VerifierInterface&MockObject
    {
        $verifier = $this->createMock(VerifierInterface::class);
        $verifier->method('getProviderCode')->willReturn($code);
        $verifier->method('isAvailable')->willReturn($available);

        return $verifier;
    }
}
