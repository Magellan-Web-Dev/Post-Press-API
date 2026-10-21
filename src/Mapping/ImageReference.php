<?php

declare(strict_types=1);

namespace PostPressAPI\Mapping;

if (!defined('ABSPATH')) exit;

use PostPressAPI\Http\Url;

/**
 * A deferred image write carried through mapping evaluation and fingerprinting.
 *
 * The mapping engine creates this value without making HTTP requests or writing
 * attachments. The sync layer resolves it only when a record needs a write.
 * Serializing the source URL keeps fingerprints independent of local media IDs.
 */
final class ImageReference implements \JsonSerializable
{
    /**
     * @param string $url    Absolute image URL, including significant query parameters.
     * @param string $output Local value to write: attachment "id" or media "url".
     *
     * @throws MappingError When the URL is malformed or contains credentials.
     */
    public function __construct(public readonly string $url, public readonly string $output = 'id')
    {
        if (!Url::is_valid_endpoint($url)) {
            throw new MappingError(__('Image download requires an absolute HTTP(S) URL without embedded credentials.', 'post-press-api'));
        }
    }

    /**
     * Stable description used in mapped-record fingerprints.
     *
     * @return array{image_url: string, output: string}
     */
    public function jsonSerialize(): array
    {
        return ['image_url' => $this->url, 'output' => $this->output];
    }

    /**
     * Validates an explicitly mapped local image ID without downloading anything.
     *
     * @param mixed $value Source value or literal fallback.
     *
     * @return int Local image attachment ID.
     *
     * @throws MappingError When the value does not identify a usable local image.
     */
    public static function attachment_id(mixed $value): int
    {
        if ((!is_int($value) && !(is_string($value) && ctype_digit($value))) || (int) $value < 1
            || get_post_type((int) $value) !== 'attachment' || get_post_status((int) $value) === 'trash'
            || !wp_attachment_is_image((int) $value)) {
            throw new MappingError(__('Choose a local image attachment ID, or enable Download image URL to Media Library.', 'post-press-api'));
        }
        return (int) $value;
    }

    /**
     * Whether a destination can receive a downloaded image ID or local URL.
     *
     * @param Destination $destination Resolved mapping destination.
     *
     * @return bool
     */
    public static function supports(Destination $destination): bool
    {
        return $destination->category === Destination::META
            || ($destination->category === Destination::CORE && $destination->key === CoreFields::IMAGE)
            || ($destination->category === Destination::ACF && in_array($destination->acf_type(), ['image', 'url', 'text', 'textarea'], true));
    }

    /**
     * Checks nested mappings for downloads that require upload permissions.
     *
     * @param list<array<string, mixed>> $rows Normalized mappings.
     *
     * @return bool
     */
    public static function used(array $rows): bool
    {
        foreach ($rows as $row) {
            if (!empty($row['media']['download']) || self::used($row['children'] ?? [])) {
                return true;
            }
        }
        return false;
    }
}
