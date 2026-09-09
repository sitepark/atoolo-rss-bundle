<?php

declare(strict_types=1);

namespace Atoolo\Rss\Service;

/**
 * Resolves a media url to a file on disk.
 *
 * Only needed to fill the optional `type` and `fileSize` attributes of a
 * `media:content` element. Where a media file lives depends on how the channel
 * publishes its resources, which this bundle cannot know - hence the seam.
 * Without an implementation the feed stays valid, just without those two
 * attributes.
 */
interface MediaFileLocator
{
    public function locate(string $url): ?string;
}
