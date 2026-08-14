<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Model;

/**
 * Lowercase, hyphen-separated, ASCII-only.
 *
 * Used to build bundle keys out of admin-typed text, where the only requirements are that the same
 * input always produces the same output and that the output reads like the input did. Uniqueness is
 * not one of them — callers that need it detect collisions and say so, because a slugifier that
 * quietly appends `-2` produces keys that depend on the order rows came back in.
 */
class Slug
{
    public function of(string $value): string
    {
        $lowered = strtolower($value);
        $ascii = (string)preg_replace('/[^a-z0-9]+/', '-', $lowered);

        return trim($ascii, '-');
    }
}
