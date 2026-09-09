<?php

declare(strict_types=1);

namespace Atoolo\Rss\Dto;

/**
 * Feed-level metadata, from the resource the feed belongs to or, without one,
 * from the site itself.
 */
class Channel
{
    public function __construct(
        public readonly string $title,
        /** the page the feed belongs to, or the site root */
        public readonly string $link,
        /** the feed's own url, for `atom:link rel="self"` */
        public readonly string $selfLink,
        public readonly ?string $description = null,
        public readonly ?string $language = null,
        public readonly ?string $copyright = null,
        public readonly ?string $generator = null,
        public readonly ?Image $image = null,
    ) {}
}
