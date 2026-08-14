<?php
declare(strict_types=1);

namespace Scr1be\HyvaMegaMenu\Model\Icon;

/**
 * What the template should draw next to a menu entry, and nothing more.
 *
 * The four icon sources render as four different elements — a `<use>` into the inline sprite, an
 * `<img>`, a `<span>` carrying somebody else's icon-font class, or a coloured square — so the
 * template has to branch on the kind. Keeping the kind in a value object rather than in four
 * nullable strings means the branch is exhaustive by construction: there is exactly one `type`,
 * and `NONE` is one of the values rather than a fifth combination of nulls to remember.
 */
final class Icon
{
    public const TYPE_NONE = 'none';
    public const TYPE_SPRITE = 'sprite';
    public const TYPE_IMAGE = 'image';
    public const TYPE_CLASS = 'class';
    public const TYPE_COLOR = 'color';

    private function __construct(
        public readonly string $type,
        public readonly string $value
    ) {
    }

    public static function none(): self
    {
        return new self(self::TYPE_NONE, '');
    }

    public static function sprite(string $key): self
    {
        return new self(self::TYPE_SPRITE, $key);
    }

    public static function image(string $url): self
    {
        return new self(self::TYPE_IMAGE, $url);
    }

    public static function cssClass(string $class): self
    {
        return new self(self::TYPE_CLASS, $class);
    }

    public static function color(string $hex): self
    {
        return new self(self::TYPE_COLOR, $hex);
    }

    public function isPresent(): bool
    {
        return $this->type !== self::TYPE_NONE;
    }

    /**
     * The shape the JSON island carries for third-level entries.
     *
     * Short keys because this array is repeated once per third-level category and the whole
     * island ships inside every cached page.
     *
     * @return array{t: string, v: string}|null
     */
    public function toIslandArray(): ?array
    {
        return $this->isPresent() ? ['t' => $this->type, 'v' => $this->value] : null;
    }
}
