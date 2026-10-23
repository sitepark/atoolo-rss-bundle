<?php

declare(strict_types=1);

namespace Atoolo\Rss\Service;

use Atoolo\Rss\Dto\Feed;

/**
 * What `FeedReader` keeps per url: the last feed it loaded and, if the last
 * attempt to reload it failed, when and why.
 *
 * @internal
 */
class CachedFeed
{
    public function __construct(
        public readonly ?Feed $feed,
        /** unix time the feed was loaded */
        public readonly ?int $loadedAt,
        /** unix time of the last failed attempt, null if it succeeded */
        public readonly ?int $failedAt = null,
        public readonly ?string $error = null,
    ) {}
}
