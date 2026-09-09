<?php

declare(strict_types=1);

namespace Atoolo\Rss\Dto;

class Item
{
    /**
     * @param list<Category> $categories
     */
    public function __construct(
        public readonly string $title,
        public readonly string $link,
        public readonly string $guid,
        public readonly ?string $description = null,
        public readonly ?\DateTimeImmutable $pubDate = null,
        public readonly ?Media $media = null,
        public readonly array $categories = [],
    ) {}
}
