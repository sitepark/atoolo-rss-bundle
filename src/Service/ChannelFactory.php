<?php

declare(strict_types=1);

namespace Atoolo\Rss\Service;

use Atoolo\Resource\Resource;
use Atoolo\Rss\Dto\Channel;

/**
 * Supplies the feed-level metadata.
 *
 * The counterpart to {@see ItemFactory}: that one decides what an item says,
 * this one what the channel says. Both read metadata conventions a project can
 * differ on, so both are seams.
 */
interface ChannelFactory
{
    /**
     * @param ?Resource $resource the page the feed belongs to; without one the
     *                            channel falls back to the site's own metadata
     * @param string $selfLink the feed's own url
     */
    public function create(?Resource $resource, string $selfLink): Channel;
}
