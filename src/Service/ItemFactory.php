<?php

declare(strict_types=1);

namespace Atoolo\Rss\Service;

use Atoolo\Resource\Resource;
use Atoolo\Rss\Dto\Item;

/**
 * Maps one search hit onto a feed item.
 */
interface ItemFactory
{
    /**
     * Null skips the resource.
     */
    public function create(Resource $resource): ?Item;
}
