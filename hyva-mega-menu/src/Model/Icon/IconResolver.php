<?php
declare(strict_types=1);

namespace Scr1be\HyvaMegaMenu\Model\Icon;

use Magento\Catalog\Model\Category;
use Psr\Log\LoggerInterface;

/**
 * Turns the four icon attributes on a category into the one icon the template will draw.
 *
 * The order is sprite key → media image → icon class → colour square, and it is a *fallback
 * ladder*, not a switch: a value that cannot be used steps aside for the next one instead of
 * producing a broken icon. A sprite key that no longer exists in the registry, a hex colour typed
 * as a word, an icon class carrying characters that have no business in a class attribute — each
 * of those hands over to the next source, and a category with nothing usable draws no icon at
 * all, which is the same thing every category looks like before anyone fills the fields in.
 *
 * The order is deliberate rather than alphabetical. The sprite is the only source that inherits
 * the text colour, so it wins whenever it is available. A media image is next because it is the
 * only source that carries real artwork. An icon class comes third because it depends on a font
 * or an icon set the theme may or may not still ship. The colour square is last because it is
 * the fallback a merchant reaches for when nothing else is ready.
 */
class IconResolver
{
    public const ATTRIBUTE_SPRITE = 'megamenu_icon_key';
    public const ATTRIBUTE_IMAGE = 'megamenu_icon_image';
    public const ATTRIBUTE_CLASS = 'megamenu_icon_class';
    public const ATTRIBUTE_COLOR = 'megamenu_icon_color';

    /**
     * Every attribute this module needs on a category, so the tree query can select them in one go.
     */
    public const ATTRIBUTES = [
        self::ATTRIBUTE_SPRITE,
        self::ATTRIBUTE_IMAGE,
        self::ATTRIBUTE_CLASS,
        self::ATTRIBUTE_COLOR,
    ];

    /**
     * Three- and six-digit hex only. The value is written into a CSS custom property, and a custom
     * property is one of the few places in a page where escaping is not enough on its own: the
     * declaration is parsed as CSS, so an unvalidated value could close it and open another. An
     * allowlist that admits exactly what a colour looks like leaves nothing to reason about.
     */
    private const HEX_COLOR_PATTERN = '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i';

    /**
     * Class-attribute characters, plus the colon and slash that utility frameworks use for
     * variants and fractions. Anything else — quotes, angle brackets, whitespace runs that could
     * smuggle another attribute — takes the value out of the ladder.
     */
    private const CSS_CLASS_PATTERN = '/^[A-Za-z0-9_:\/\.\- ]+$/';

    private const MAX_CSS_CLASS_LENGTH = 120;

    public function __construct(
        private readonly SpriteRegistry $spriteRegistry,
        private readonly LoggerInterface $logger
    ) {
    }

    public function resolve(Category $category): Icon
    {
        return $this->fromSprite($category)
            ?? $this->fromImage($category)
            ?? $this->fromCssClass($category)
            ?? $this->fromColor($category)
            ?? Icon::none();
    }

    private function fromSprite(Category $category): ?Icon
    {
        $key = $this->readString($category, self::ATTRIBUTE_SPRITE);

        return $key !== null && $this->spriteRegistry->has($key) ? Icon::sprite($key) : null;
    }

    /**
     * Category::getImageUrl() reads the stored file name and joins it with the media base url,
     * handling the two shapes core's own image backend writes: a bare file name for something
     * uploaded into the category media folder, and a leading-slash path for a file picked from
     * elsewhere in the gallery. Rebuilding that by hand is how a module ends up with a menu that
     * works until someone reuses an existing asset.
     */
    private function fromImage(Category $category): ?Icon
    {
        if ($this->readString($category, self::ATTRIBUTE_IMAGE) === null) {
            return null;
        }

        try {
            $url = $category->getImageUrl(self::ATTRIBUTE_IMAGE);
        } catch (\Throwable $error) {
            // getImageUrl() throws when the stored value is not a string, which means the row was
            // written by something other than the image backend. One broken category is not a
            // reason to take the header menu off every page.
            $this->logger->warning(
                'Mega menu could not resolve an icon image',
                ['category_id' => $category->getId(), 'exception' => $error]
            );

            return null;
        }

        return is_string($url) && $url !== '' ? Icon::image($url) : null;
    }

    private function fromCssClass(Category $category): ?Icon
    {
        $class = $this->readString($category, self::ATTRIBUTE_CLASS);

        if ($class === null || strlen($class) > self::MAX_CSS_CLASS_LENGTH) {
            return null;
        }

        return preg_match(self::CSS_CLASS_PATTERN, $class) === 1 ? Icon::cssClass($class) : null;
    }

    private function fromColor(Category $category): ?Icon
    {
        $color = $this->readString($category, self::ATTRIBUTE_COLOR);

        if ($color === null) {
            return null;
        }

        return preg_match(self::HEX_COLOR_PATTERN, $color) === 1 ? Icon::color(strtolower($color)) : null;
    }

    private function readString(Category $category, string $attributeCode): ?string
    {
        $value = $category->getData($attributeCode);

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
