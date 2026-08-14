<?php
declare(strict_types=1);

namespace Scr1be\SocialLogin\Model\Jwt;

/**
 * Turns a JWK's `n`/`e` pair into a PEM public key that `openssl_verify()` accepts.
 *
 * OpenSSL wants a SubjectPublicKeyInfo structure; a JWK is two base64url integers. The conversion is
 * about thirty lines of DER and is written out here rather than pulled in as a dependency, because
 * the alternative is a JWT library and its transitive tree in a module whose entire job is to verify
 * one signature. `ext-openssl` does the cryptography; this only reshapes the key.
 *
 * ASN.1 being built:
 *
 *   SubjectPublicKeyInfo ::= SEQUENCE {
 *       algorithm  AlgorithmIdentifier { OID 1.2.840.113549.1.1.1, NULL },
 *       subjectPublicKey BIT STRING wrapping
 *           RSAPublicKey ::= SEQUENCE { modulus INTEGER, publicExponent INTEGER }
 *   }
 */
final class RsaPublicKey
{
    /**
     * DER for `AlgorithmIdentifier { rsaEncryption, NULL }`.
     *
     * `06 09 2a 86 48 86 f7 0d 01 01 01` is OBJECT IDENTIFIER 1.2.840.113549.1.1.1 (rsaEncryption);
     * `05 00` is the NULL parameters the RSA algorithm identifier requires.
     */
    private const RSA_ALGORITHM_IDENTIFIER = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";

    private const PEM_LINE_LENGTH = 64;

    /**
     * @param string $modulusB64Url The JWK `n` member.
     * @param string $exponentB64Url The JWK `e` member.
     * @return string|null PEM, or null when either member is not decodable.
     */
    public static function toPem(string $modulusB64Url, string $exponentB64Url): ?string
    {
        $modulus = Base64Url::decode($modulusB64Url);
        $exponent = Base64Url::decode($exponentB64Url);

        if ($modulus === null || $exponent === null || $modulus === '' || $exponent === '') {
            return null;
        }

        $rsaPublicKey = self::sequence(self::integer($modulus) . self::integer($exponent));
        $subjectPublicKeyInfo = self::sequence(
            self::RSA_ALGORITHM_IDENTIFIER . self::bitString($rsaPublicKey)
        );

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($subjectPublicKeyInfo), self::PEM_LINE_LENGTH, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    /**
     * DER length octets: short form below 128, long form above.
     *
     * @param int $length
     * @return string
     */
    private static function length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    /**
     * DER INTEGER.
     *
     * DER integers are signed two's complement, so a leading byte with the high bit set has to be
     * prefixed with a zero or the modulus reads as negative — which produces a PEM OpenSSL parses
     * without complaint and then fails every signature against. That single byte is the whole trap
     * in this conversion.
     *
     * @param string $binary
     * @return string
     */
    private static function integer(string $binary): string
    {
        $binary = ltrim($binary, "\x00");
        if ($binary === '') {
            $binary = "\x00";
        }
        if ((ord($binary[0]) & 0x80) !== 0) {
            $binary = "\x00" . $binary;
        }

        return "\x02" . self::length(strlen($binary)) . $binary;
    }

    /**
     * DER SEQUENCE.
     *
     * @param string $contents
     * @return string
     */
    private static function sequence(string $contents): string
    {
        return "\x30" . self::length(strlen($contents)) . $contents;
    }

    /**
     * DER BIT STRING with a zero "unused bits" octet.
     *
     * @param string $contents
     * @return string
     */
    private static function bitString(string $contents): string
    {
        return "\x03" . self::length(strlen($contents) + 1) . "\x00" . $contents;
    }
}
