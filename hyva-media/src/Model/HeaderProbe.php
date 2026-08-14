<?php
declare(strict_types=1);

namespace Scr1be\HyvaMedia\Model;

/**
 * Reads intrinsic pixel dimensions out of an image header, without decoding the image.
 *
 * getimagesize() would do the same job in one line, and it is the wrong line twice over. It takes a
 * filesystem path, which under remote storage does not exist (see MediaStorage), and its
 * "unsupported format" answer is indistinguishable from its "corrupt file" answer. Parsing the four
 * container headers by hand costs about a hundred lines and buys a bounded read, a driver-agnostic
 * call path, and a null that means exactly one thing.
 *
 * Dimensions are needed before anything else happens: they decide which rungs exist, whether the
 * megapixel ceiling is breached, and what width/height a template must emit to keep layout stable.
 * Doing that with a 64 KB read instead of a full decode is the difference between a stat-cost and a
 * memory-cost per image per render.
 */
class HeaderProbe
{
    /**
     * JPEG is the only format here whose size can sit arbitrarily deep: comment and EXIF segments
     * precede the frame header and an embedded thumbnail alone can run past 48 KB. 64 KB clears
     * every JPEG produced by a camera or an image editor; the rest are answered in 32 bytes.
     */
    public const HEADER_BYTES = 65536;

    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    /**
     * Start-of-frame markers. The excluded values inside the 0xC0-0xCF run are not frame headers:
     * 0xC4 is the Huffman table, 0xC8 a JPEG extension, 0xCC the arithmetic coding table.
     */
    private const SOF_MARKERS = [
        0xC0, 0xC1, 0xC2, 0xC3,
        0xC5, 0xC6, 0xC7,
        0xC9, 0xCA, 0xCB,
        0xCD, 0xCE, 0xCF,
    ];

    public function __construct(
        private readonly MediaStorage $storage,
    ) {
    }

    public function probe(string $mediaRelativePath): ?ImageDimensions
    {
        $header = $this->storage->readHead($mediaRelativePath, self::HEADER_BYTES);
        if ($header === null) {
            return null;
        }

        return $this->probeBytes($header);
    }

    /**
     * Pure half of the probe: takes bytes, returns dimensions. Kept separate from the I/O so the
     * format parsers can be exercised against hand-built headers.
     */
    public function probeBytes(string $header): ?ImageDimensions
    {
        if (str_starts_with($header, self::PNG_SIGNATURE)) {
            return $this->readPng($header);
        }

        if (str_starts_with($header, 'GIF87a') || str_starts_with($header, 'GIF89a')) {
            return $this->readGif($header);
        }

        if (str_starts_with($header, 'RIFF') && substr($header, 8, 4) === 'WEBP') {
            return $this->readWebp($header);
        }

        if (str_starts_with($header, "\xFF\xD8")) {
            return $this->readJpeg($header);
        }

        return null;
    }

    /**
     * The IHDR chunk is mandatory and must come first, so width and height sit at fixed offsets:
     * 8-byte signature, 4-byte chunk length, 4-byte "IHDR", then two big-endian 32-bit integers.
     */
    private function readPng(string $header): ?ImageDimensions
    {
        if (strlen($header) < 24 || substr($header, 12, 4) !== 'IHDR') {
            return null;
        }

        return $this->dimensions(
            $this->uint32be($header, 16),
            $this->uint32be($header, 20)
        );
    }

    /**
     * Logical screen descriptor, immediately after the 6-byte signature: two little-endian 16-bit
     * integers. Note this is the canvas, which for a well-formed GIF is also the frame size.
     */
    private function readGif(string $header): ?ImageDimensions
    {
        if (strlen($header) < 10) {
            return null;
        }

        return $this->dimensions(
            $this->uint16le($header, 6),
            $this->uint16le($header, 8)
        );
    }

    /**
     * Three sub-formats share the RIFF/WEBP container and none of them stores size the same way.
     */
    private function readWebp(string $header): ?ImageDimensions
    {
        $chunk = substr($header, 12, 4);

        // Lossy: a 3-byte frame tag, the 3-byte sync code 9D 01 2A, then two 14-bit dimensions
        // packed into 16-bit little-endian words whose top two bits are the scaling hint.
        if ($chunk === 'VP8 ') {
            if (strlen($header) < 30 || substr($header, 23, 3) !== "\x9D\x01\x2A") {
                return null;
            }

            return $this->dimensions(
                $this->uint16le($header, 26) & 0x3FFF,
                $this->uint16le($header, 28) & 0x3FFF
            );
        }

        // Lossless: a 0x2F signature byte, then width-1 and height-1 as consecutive 14-bit fields
        // inside one little-endian 32-bit word.
        if ($chunk === 'VP8L') {
            if (strlen($header) < 25 || $header[20] !== "\x2F") {
                return null;
            }

            $packed = $this->uint32le($header, 21);

            return $this->dimensions(
                ($packed & 0x3FFF) + 1,
                (($packed >> 14) & 0x3FFF) + 1
            );
        }

        // Extended: a flags byte, three reserved bytes, then canvas width-1 and height-1 as 24-bit
        // little-endian values. This is the chunk that carries alpha and animation.
        if ($chunk === 'VP8X') {
            if (strlen($header) < 30) {
                return null;
            }

            return $this->dimensions(
                $this->uint24le($header, 24) + 1,
                $this->uint24le($header, 27) + 1
            );
        }

        return null;
    }

    /**
     * Walks the marker chain from the SOI until a start-of-frame appears. Everything before it —
     * EXIF, ICC profiles, comments, an embedded thumbnail — is length-prefixed and skipped whole.
     */
    private function readJpeg(string $header): ?ImageDimensions
    {
        $length = strlen($header);
        $offset = 2;

        while ($offset + 1 < $length) {
            if ($header[$offset] !== "\xFF") {
                // Marker chains are byte-aligned; a non-FF here means the stream is not a marker
                // sequence any more and guessing forward would find dimensions in pixel data.
                return null;
            }

            $marker = ord($header[$offset + 1]);

            // Fill bytes: any number of FFs may pad the gap before a marker.
            if ($marker === 0xFF) {
                ++$offset;
                continue;
            }

            // Standalone markers carry no length field. SOI/EOI/TEM plus the eight restart markers.
            if ($marker === 0xD8 || $marker === 0xD9 || $marker === 0x01
                || ($marker >= 0xD0 && $marker <= 0xD7)
            ) {
                $offset += 2;
                continue;
            }

            // Start of scan: compressed data begins here and there is no frame header behind it.
            if ($marker === 0xDA) {
                return null;
            }

            if ($offset + 3 >= $length) {
                return null;
            }

            $segmentLength = $this->uint16be($header, $offset + 2);
            if ($segmentLength < 2) {
                return null;
            }

            if (in_array($marker, self::SOF_MARKERS, true)) {
                // Segment body: 2-byte length, 1-byte sample precision, then height before width,
                // both big-endian 16-bit. The order is the trap — every other format here is
                // width-first.
                if ($offset + 8 >= $length) {
                    return null;
                }

                return $this->dimensions(
                    $this->uint16be($header, $offset + 7),
                    $this->uint16be($header, $offset + 5)
                );
            }

            $offset += 2 + $segmentLength;
        }

        return null;
    }

    private function dimensions(int $width, int $height): ?ImageDimensions
    {
        if ($width <= 0 || $height <= 0) {
            return null;
        }

        return new ImageDimensions($width, $height);
    }

    private function uint16be(string $bytes, int $offset): int
    {
        return (ord($bytes[$offset]) << 8) | ord($bytes[$offset + 1]);
    }

    private function uint16le(string $bytes, int $offset): int
    {
        return ord($bytes[$offset]) | (ord($bytes[$offset + 1]) << 8);
    }

    private function uint24le(string $bytes, int $offset): int
    {
        return ord($bytes[$offset])
            | (ord($bytes[$offset + 1]) << 8)
            | (ord($bytes[$offset + 2]) << 16);
    }

    private function uint32be(string $bytes, int $offset): int
    {
        return (ord($bytes[$offset]) << 24)
            | (ord($bytes[$offset + 1]) << 16)
            | (ord($bytes[$offset + 2]) << 8)
            | ord($bytes[$offset + 3]);
    }

    private function uint32le(string $bytes, int $offset): int
    {
        return ord($bytes[$offset])
            | (ord($bytes[$offset + 1]) << 8)
            | (ord($bytes[$offset + 2]) << 16)
            | (ord($bytes[$offset + 3]) << 24);
    }
}
