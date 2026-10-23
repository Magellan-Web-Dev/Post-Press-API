<?php

declare(strict_types=1);

namespace PostPressAPI\Mapping;

if (!defined('ABSPATH')) exit;

/**
 * Decides whether imported rich text may contain executable WordPress markup.
 *
 * Trust boundary: wp_kses_post() removes unsafe HTML, but it keeps shortcodes
 * ("[tag]") and block delimiters ("<!-- wp:name /-->"). Both are executed when
 * the post is rendered — by any shortcode or dynamic block installed on the
 * site, now or later — so content from an external API could run code the
 * administrator never chose. This policy applies to the fields WordPress or ACF
 * render as rich content: post content, post excerpt, and ACF WYSIWYG fields.
 * Other meta and ACF values are stored as data and left unchanged; themes must
 * not pass them through do_shortcode() or do_blocks().
 *
 * Modes:
 *  - neutralize (default for new presets): every "[" becomes the entity "&#91;"
 *    (it still displays as "["), and block delimiter comments are removed, so
 *    the content renders as plain HTML;
 *  - allowlist: as neutralize, except a "[" that opens or closes a listed
 *    shortcode stays active. The tag is compared by its complete name as
 *    WordPress reads it, so "[gallery"x]" is not treated as "gallery";
 *  - trusted: content is kept as filtered by wp_kses_post() (the behavior of
 *    presets saved before this setting existed; see PresetRepository::find()).
 */
final class MarkupPolicy
{
    public const NEUTRALIZE = 'neutralize';
    public const ALLOWLIST  = 'allowlist';
    public const TRUSTED    = 'trusted';

    /** @var list<string> */
    public const MODES = [self::NEUTRALIZE, self::ALLOWLIST, self::TRUSTED];

    /** @var int Shortcode tags an allowlist may hold. */
    public const MAX_TAGS = 20;

    /** @var string Shortcode tag names accepted in an allowlist. */
    public const TAG_PATTERN = '/^[A-Za-z0-9_-]{1,50}$/';

    /**
     * Every "[": 1 = a second "[" ("[[tag]]" escape), 2 = "/" of a closing tag,
     * 3 = the complete name that follows, read as do_shortcode() reads it (any
     * characters add_shortcode() accepts, quotes included; possibly empty).
     *
     * @var string
     */
    private const BRACKET = '/\[(\[?)(\/?)([^<>&\/\[\]\x00-\x20=]*)/';

    /** @var string A block delimiter comment, as WordPress's block parser recognizes it. */
    private const BLOCK_DELIMITER = '/<!--\s*\/?wp:.*?-->/is';

    /**
     * @param string       $mode    One of MODES.
     * @param list<string> $allowed Shortcode tags left active in allowlist mode.
     */
    public function __construct(
        public readonly string $mode = self::NEUTRALIZE,
        public readonly array $allowed = []
    ) {}

    /**
     * Policy configured on a preset. A preset without the setting (a run snapshot
     * taken by an earlier version) keeps the earlier, trusted behavior.
     *
     * @param array<string, mixed> $preset Preset.
     *
     * @return self
     */
    public static function from_preset(array $preset): self
    {
        $destination = is_array($preset['destination'] ?? null) ? $preset['destination'] : [];
        $mode        = (string) ($destination['markup'] ?? self::TRUSTED);

        return new self(
            in_array($mode, self::MODES, true) ? $mode : self::NEUTRALIZE,
            self::tags($destination['shortcodes'] ?? [])
        );
    }

    /**
     * Normalizes an allowlist (array or comma/space-separated text) to valid, unique tags.
     *
     * @param mixed $value Value.
     *
     * @return list<string>
     */
    public static function tags(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value) ?: [];
        }

        $tags = [];
        foreach (is_array($value) ? $value : [] as $tag) {
            $tag = is_string($tag) ? trim($tag, " \t\n\r\0\x0B[]") : '';
            if (preg_match(self::TAG_PATTERN, $tag) === 1 && !in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
        }

        return array_slice($tags, 0, self::MAX_TAGS);
    }

    /**
     * Applies the policy to rich text that already passed wp_kses_post().
     *
     * The result is idempotent, so applying it again never changes a stored
     * value (and never makes change detection rewrite a post).
     *
     * @param string $html Filtered HTML.
     *
     * @return string
     */
    public function apply(string $html): string
    {
        if ($this->mode === self::TRUSTED || $html === '') {
            return $html;
        }

        $stripped = preg_replace(self::BLOCK_DELIMITER, '', $html);
        if (!is_string($stripped)) {
            // Fail closed: without block parsing, neutralize every bracket and comment opener.
            return str_replace(['[', '<!--'], ['&#91;', '&lt;!--'], $html);
        }

        if ($this->mode !== self::ALLOWLIST || $this->allowed === []) {
            return str_replace('[', '&#91;', $stripped);
        }

        // Listed names use only [A-Za-z0-9_-], so no other registered tag can
        // match where a complete listed name follows the bracket.
        $allowed     = $this->allowed;
        $neutralized = preg_replace_callback(
            self::BRACKET,
            static fn (array $match): string => in_array($match[3], $allowed, true)
                ? $match[0]
                : str_repeat('&#91;', 1 + strlen($match[1])) . $match[2] . $match[3],
            $stripped
        );

        return is_string($neutralized) ? $neutralized : str_replace('[', '&#91;', $stripped);
    }
}
