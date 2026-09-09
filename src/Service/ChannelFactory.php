<?php

declare(strict_types=1);

namespace Atoolo\Rss\Service;

use Atoolo\Resource\Resource;
use Atoolo\Rss\Dto\Channel;

/**
 * Supplies the feed-level metadata.
 */
interface ChannelFactory
{
    /**
     * @param ?Resource $resource the page the feed belongs to, if any
     */
    public function create(?Resource $resource, string $selfLink): Channel;
}
