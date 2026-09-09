<?php

declare(strict_types=1);

namespace Atoolo\Rss\Service;

use Atoolo\Resource\Resource;
use Atoolo\Rss\Dto\Feed;

/**
 * Assembles a feed from the resource it belongs to and the search hits.
 */
class FeedFactory
{
    public function __construct(
        private readonly ChannelFactory $channelFactory,
        private readonly ItemFactory $itemFactory,
    ) {}

    /**
     * @param ?Resource $resource the page the feed belongs to, if any
     * @param list<Resource> $resources the search hits
     */
    public function create(
        ?Resource $resource,
        array $resources,
        string $selfLink,
    ): Feed {
        $items = [];
        foreach ($resources as $hit) {
            $item = $this->itemFactory->create($hit);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return new Feed(
            $this->channelFactory->create($resource, $selfLink),
            $items,
        );
    }
}
