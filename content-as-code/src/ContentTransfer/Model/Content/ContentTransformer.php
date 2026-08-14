<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Model\Content;

/**
 * Makes the directives inside a CMS page or block portable, and says out loud what it could not fix.
 *
 * A CMS page's HTML is not just markup — it carries `{{widget}}` and `{{block}}` directives whose
 * parameters are autoincrement ids from the install it was written on. Copy the row and the page
 * renders somebody else's block, or nothing at all. This class is the difference between a content
 * export and a content *transfer*.
 *
 * ### What gets rewritten
 *
 * `block_id` on the two core blocks that read it as a CMS block reference:
 * `Magento\Cms\Block\Widget\Block` (the `cms_static_block` widget, declared in
 * `Magento_Cms/etc/widget.xml`) and the deprecated `Magento\Cms\Block\Block`. Both end up calling
 * `$block->setStoreId($storeId)->load($blockId)`, and `Magento\Cms\Model\ResourceModel\Block` loads
 * by `identifier` whenever the value it is given is not numeric — so a rewritten directive needs no
 * counterpart on import. The value stays an identifier forever, and works.
 *
 * ### What only gets a warning
 *
 * `page_id` on `Magento\Cms\Block\Widget\Page\Link`. Swapping it for an identifier is tempting and
 * half-broken: `getHref()` resolves it through `Magento\Cms\Helper\Page::getPageUrl()`, which loads
 * the page by identifier and would work — but `getTitle()` and `getLabel()` go to
 * `Magento\Cms\Model\ResourceModel\Page::getCmsPageTitleById()`, whose `where` binds `(int)$id`. An
 * identifier casts to 0, the title comes back empty, and the result is a working link with no text
 * unless `anchor_text` was filled in. A rewrite that produces an invisible link on a customer's
 * homepage is worse than a warning, so this one is reported and left alone.
 */
class ContentTransformer
{
    /**
     * Mirrors the opening half of `Magento\Framework\Filter\Template::CONSTRUCTION_PATTERN` —
     * `{{`, a directive name of up to ten lowercase letters, a lazy body, `}}`. The closing-tag
     * half of the core pattern is deliberately dropped: this class rewrites parameters inside the
     * opening tag and must not consume whatever a block-level directive wraps.
     */
    private const DIRECTIVE_PATTERN = '/\{\{([a-z]{0,10})(.*?)\}\}/si';

    private const ATTRIBUTE_PATTERN = '/([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*"([^"]*)"/';

    private const DIRECTIVE_WIDGET = 'widget';

    private const DIRECTIVE_BLOCK = 'block';

    /**
     * `{{widget}}` names its class in `type`, `{{block}}` in `class`.
     */
    private const CLASS_ATTRIBUTE = [
        self::DIRECTIVE_WIDGET => 'type',
        self::DIRECTIVE_BLOCK => 'class',
    ];

    public const BLOCK_ID_PARAMETER = 'block_id';

    public const PAGE_ID_PARAMETER = 'page_id';

    public const PAGE_LINK_CLASS = 'Magento\Cms\Block\Widget\Page\Link';

    /**
     * @param BlockIdentifierMap $blockIdentifierMap
     * @param string[] $blockReferenceClasses Block classes whose `block_id` is a CMS block
     *        reference. Extendable through `di.xml` for a third-party widget that takes one too;
     *        adding a class that reads `block_id` as something else would corrupt its content, so
     *        the default list is exactly the two core classes that were checked.
     */
    public function __construct(
        private readonly BlockIdentifierMap $blockIdentifierMap,
        private readonly array $blockReferenceClasses = [
            'Magento\Cms\Block\Widget\Block',
            'Magento\Cms\Block\Block',
        ]
    ) {
    }

    /**
     * @param string $label How the caller refers to this content in a message, e.g. `cms_page/home`.
     */
    public function toPortable(?string $content, string $label): RewriteResult
    {
        if ($content === null || $content === '') {
            return new RewriteResult((string)$content);
        }

        $transforms = [];
        $warnings = [];

        $rewritten = preg_replace_callback(
            self::DIRECTIVE_PATTERN,
            function (array $match) use ($label, &$transforms, &$warnings): string {
                return $this->rewriteDirective($match, $label, $transforms, $warnings);
            },
            $content
        );

        // preg_replace_callback returns null only on a backtrack-limit or bad-UTF-8 failure. Content
        // is the one thing this module must never silently truncate, so the original is kept and the
        // operator is told the directives in it were not checked.
        if ($rewritten === null) {
            return new RewriteResult(
                $content,
                [],
                [
                    sprintf(
                        '%s: the content could not be scanned for directives (%s); it was copied '
                        . 'verbatim and any ids inside it are still local to this install.',
                        $label,
                        preg_last_error_msg()
                    ),
                ]
            );
        }

        return new RewriteResult($rewritten, $transforms, $warnings);
    }

    /**
     * The same `block_id` rewrite, for a widget **instance**'s stored parameters rather than for a
     * directive in someone's markup.
     *
     * A `cms_static_block` instance keeps its reference in `widget_instance.widget_parameters` as
     * `['block_id' => 42]` — Luma's own sample data writes it that way, in
     * `Magento\WidgetSampleData\Model\CmsBlock`, which does
     * `setWidgetParameters(['block_id' => $block->getId()])`. That id is as local as any other, and
     * the block it resolves to on the target install is whichever one happens to hold the number.
     *
     * No counterpart on import, for the same reason as the directive rewrite:
     * `Magento\Cms\Block\Widget\Block::getBlock()` loads through
     * `Magento\Cms\Model\ResourceModel\Block`, which treats a non-numeric value as an identifier.
     *
     * @param array<string, mixed> $parameters
     * @param string[] $transforms
     * @param string[] $warnings
     * @return array<string, mixed>
     */
    public function toPortableParameters(
        string $instanceType,
        array $parameters,
        string $label,
        array &$transforms,
        array &$warnings
    ): array {
        if (!in_array(ltrim($instanceType, '\\'), $this->blockReferenceClasses, true)) {
            return $parameters;
        }

        $blockId = (string)($parameters[self::BLOCK_ID_PARAMETER] ?? '');

        if ($blockId === '' || !ctype_digit($blockId)) {
            return $parameters;
        }

        $identifier = $this->blockIdentifierMap->identifierFor((int)$blockId);

        if ($identifier === null) {
            $warnings[] = sprintf(
                '%s: renders CMS block id %s, which does not exist on this install. The widget was '
                . 'captured as-is and will render nothing after import.',
                $label,
                $blockId
            );

            return $parameters;
        }

        $transforms[] = sprintf('%s: block_id %s -> "%s".', $label, $blockId, $identifier);
        $parameters[self::BLOCK_ID_PARAMETER] = $identifier;

        return $parameters;
    }

    /**
     * @param array{0: string, 1: string, 2: string} $match
     * @param string[] $transforms
     * @param string[] $warnings
     */
    private function rewriteDirective(array $match, string $label, array &$transforms, array &$warnings): string
    {
        [$directive, $name, $body] = $match;

        if (!isset(self::CLASS_ATTRIBUTE[$name])) {
            return $directive;
        }

        $attributes = $this->attributes($body);
        $class = ltrim($attributes[self::CLASS_ATTRIBUTE[$name]] ?? '', '\\');

        if ($class === self::PAGE_LINK_CLASS
            && ctype_digit($attributes[self::PAGE_ID_PARAMETER] ?? '')
        ) {
            $warnings[] = sprintf(
                '%s: a CMS Page Link widget points at page_id "%s". Page ids differ between '
                . 'installs and this one cannot be rewritten safely — set the widget\'s Anchor '
                . 'Custom Text and re-point it after import.',
                $label,
                $attributes[self::PAGE_ID_PARAMETER]
            );

            return $directive;
        }

        if (!in_array($class, $this->blockReferenceClasses, true)) {
            return $directive;
        }

        $blockId = $attributes[self::BLOCK_ID_PARAMETER] ?? null;

        if ($blockId === null || !ctype_digit($blockId)) {
            // Already an identifier, or absent. Both are fine and neither is worth a line of output.
            return $directive;
        }

        $identifier = $this->blockIdentifierMap->identifierFor((int)$blockId);

        if ($identifier === null) {
            $warnings[] = sprintf(
                '%s: references CMS block id %s, which does not exist on this install. The '
                . 'directive was left as-is and will render nothing after import.',
                $label,
                $blockId
            );

            return $directive;
        }

        $transforms[] = sprintf('%s: block_id %s -> "%s".', $label, $blockId, $identifier);

        return '{{' . $name . $this->replaceAttribute($body, self::BLOCK_ID_PARAMETER, $identifier) . '}}';
    }

    /**
     * @return array<string, string>
     */
    private function attributes(string $body): array
    {
        preg_match_all(self::ATTRIBUTE_PATTERN, $body, $matches, PREG_SET_ORDER);

        $attributes = [];

        foreach ($matches as $match) {
            $attributes[$match[1]] = $match[2];
        }

        return $attributes;
    }

    /**
     * Replaced through a callback rather than a replacement string: an identifier is free-form text
     * and `preg_replace` would read a `$1` in it as a back-reference.
     */
    private function replaceAttribute(string $body, string $attribute, string $value): string
    {
        return (string)preg_replace_callback(
            '/\b' . preg_quote($attribute, '/') . '\s*=\s*"[^"]*"/',
            static fn (): string => $attribute . '="' . $value . '"',
            $body,
            1
        );
    }
}
