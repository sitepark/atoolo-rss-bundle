<?php

declare(strict_types=1);

namespace Atoolo\Rss\Service;

use Atoolo\Resource\Resource;
use Atoolo\Rss\Dto\Item;

/**
 * Maps one search hit onto a feed item.
 *
 * The seam a project overrides to change what a feed item says, without
 * touching the rest of the bundle.
 */
interface ItemFactory
{
    /**
     * Null skips the resource, so a hit that cannot make a usable item does not
     * become an empty entry in the feed.
     */
    public function create(Resource $resource): ?Item;
}
