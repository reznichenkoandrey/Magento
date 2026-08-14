<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Model\Robots;

use Magento\Framework\Phrase;

/**
 * Line-level validation of admin-entered robots.txt content.
 *
 * The point is not to be a robots.txt parser. It is that this text ends up as a file on disk that
 * nobody looks at again, served to crawlers that report nothing back: a typo here is invisible
 * until traffic has already gone. Every rule below is one that fails silently in production.
 */
class Validator
{
    /**
     * Large enough for any real file — a generous one runs to a few kilobytes — and small enough
     * that a paste accident cannot fill a disk one config save at a time.
     */
    public const MAX_BYTES = 512000;

    /**
     * `Directive: value`. The colon is not optional and the directive is a bare word; a line
     * without one is almost always a URL path someone forgot to prefix with `Disallow:`, which
     * robots.txt ignores rather than rejecting.
     */
    private const DIRECTIVE_PATTERN = '/^([A-Za-z-]+)\s*:\s*(.*)$/';

    private const DIRECTIVE_USER_AGENT = 'user-agent';
    private const DIRECTIVE_SITEMAP = 'sitemap';
    private const DIRECTIVE_ALLOW = 'allow';
    private const DIRECTIVE_DISALLOW = 'disallow';

    /**
     * Directives that only mean anything inside a group, i.e. after a User-agent line.
     */
    private const GROUP_DIRECTIVES = [
        self::DIRECTIVE_ALLOW,
        self::DIRECTIVE_DISALLOW,
        'crawl-delay',
    ];

    /**
     * @return Phrase[] One per violation, empty when the content is publishable.
     */
    public function validate(string $content): array
    {
        $violations = [];

        if (strlen($content) > self::MAX_BYTES) {
            $violations[] = __(
                'The robots.txt content is %1 bytes, which is over the %2 byte limit.',
                strlen($content),
                self::MAX_BYTES
            );
        }

        // A NUL or a stray control byte survives the admin textarea, survives the database, and
        // then makes the served file undiagnosably wrong. `\P{C}` is "not an control/other"
        // character, so the negated class matches exactly the control characters that are not
        // whitespace. A `false` return means the payload is not valid UTF-8, which is its own
        // violation rather than a reason to skip the check.
        $controlMatch = preg_match('/[^\P{C}\t\r\n]/u', $content);

        if ($controlMatch === false) {
            $violations[] = __('The robots.txt content is not valid UTF-8.');
        } elseif ($controlMatch === 1) {
            $violations[] = __('The robots.txt content contains control characters.');
        }

        $seenUserAgent = false;

        foreach (preg_split('/\R/', $content) ?: [] as $index => $rawLine) {
            $line = trim($rawLine);
            $lineNumber = $index + 1;

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match(self::DIRECTIVE_PATTERN, $line, $matches) !== 1) {
                $violations[] = __('Line %1 is not a "Directive: value" pair: "%2".', $lineNumber, $line);
                continue;
            }

            $directive = strtolower($matches[1]);
            $value = trim($matches[2]);

            if ($directive === self::DIRECTIVE_USER_AGENT) {
                $seenUserAgent = true;
                if ($value === '') {
                    $violations[] = __('Line %1 declares a User-agent with no value.', $lineNumber);
                }
                continue;
            }

            if (in_array($directive, self::GROUP_DIRECTIVES, true)) {
                if (!$seenUserAgent) {
                    $violations[] = __(
                        'Line %1 uses "%2" before any User-agent line, so it belongs to no group.',
                        $lineNumber,
                        $matches[1]
                    );
                }

                if (in_array($directive, [self::DIRECTIVE_ALLOW, self::DIRECTIVE_DISALLOW], true)
                    && $value !== ''
                    && !str_starts_with($value, '/')
                    && !str_starts_with($value, '*')
                ) {
                    $violations[] = __(
                        'Line %1 has a path that starts with neither "/" nor "*": "%2".',
                        $lineNumber,
                        $value
                    );
                }
                continue;
            }

            if ($directive === self::DIRECTIVE_SITEMAP && !$this->isAbsoluteHttpUrl($value)) {
                // A relative Sitemap line is the single most common robots.txt mistake and it is
                // ignored outright — the directive is defined as taking a full URL.
                $violations[] = __('Line %1 has a Sitemap that is not an absolute http(s) URL: "%2".', $lineNumber, $value);
            }
        }

        return $violations;
    }

    private function isAbsoluteHttpUrl(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }
}
