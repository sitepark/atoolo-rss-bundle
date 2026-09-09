<?php

declare(strict_types=1);

namespace Atoolo\Rss\Dto;

class Feed
{
    /**
     * @param list<Item> $items
     */
    public function __construct(
        public readonly Channel $channel,
        public readonly array $items = [],
    ) {}

    public function getLastBuildDate(): ?\DateTimeImmutable
    {
        $latest = null;
        foreach ($this->items as $item) {
            if ($item->pubDate !== null && ($latest === null || $item->pubDate > $latest)) {
                $latest = $item->pubDate;
            }
        }
        return $latest;
    }
}
