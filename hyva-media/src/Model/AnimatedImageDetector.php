<?php
declare(strict_types=1);

namespace Scr1be\HyvaMedia\Model;

/**
 * Answers one question: would re-encoding these bytes destroy motion?
 *
 * imagecreatefromstring() takes the first frame of an animated GIF and says nothing about the rest,
 * so a resizer that does not ask this question turns a spinning badge into a still one and reports
 * success. The failure is silent, it only shows up on the storefront, and it is exactly the sort of
 * thing that survives a code review because the derivative *is* a valid image.
 *
 * There is no equivalent question to ask about JPEG or PNG. Animated WebP exists, and GD cannot
 * write it either — a re-encode of one would flatten it the same way.
 */
class AnimatedImageDetector
{
    /**
     * A Graphic Control Extension: introducer 0x21, label 0xF9, block size 0x04, four bytes of
     * payload, block terminator 0x00, then either an Image Descriptor (0x2C) or another extension
     * (0x21). One of these precedes each animated frame, so two or more means motion.
     *
     * This is a scan rather than a block-walk, and it can in principle match inside compressed pixel
     * data. That bias is the safe one: a false positive costs one image its derivatives, a false
     * negative ships a broken animation.
     */
    private const GIF_FRAME_PATTERN = '/\x00\x21\xF9\x04.{4}\x00[\x2C\x21]/s';

    /**
     * The ANIM chunk of an extended-format WebP. Its presence in the RIFF header is definitive;
     * unlike the GIF case there is nothing to guess at.
     */
    private const WEBP_ANIMATION_CHUNK = 'ANIM';

    public function isAnimated(string $bytes, string $format): bool
    {
        if ($format === GdEncoder::FORMAT_GIF) {
            return preg_match_all(self::GIF_FRAME_PATTERN, $bytes) > 1;
        }

        if ($format === GdEncoder::FORMAT_WEBP) {
            // The animation flag lives in the VP8X chunk, which if present is always the first
            // chunk; the ANIM chunk follows it. Both sit inside the first few dozen bytes, so the
            // search is bounded rather than run over a whole file.
            return str_contains(substr($bytes, 0, 64), self::WEBP_ANIMATION_CHUNK);
        }

        return false;
    }
}
