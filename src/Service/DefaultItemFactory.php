<?php

declare(strict_types=1);

namespace Atoolo\Rss\Service;

use Atoolo\Resource\Resource;
use Atoolo\Rewrite\Dto\UrlRewriteOptions;
use Atoolo\Rewrite\Dto\UrlRewriteType;
use Atoolo\Rewrite\Service\UrlRewriter;
use Atoolo\Rss\Dto\Item;
use Atoolo\Rss\Dto\Media;

/**
 * Builds a feed item from a resource's teaser data, falling back to its
 * metadata and then its title.
 */
class DefaultItemFactory implements ItemFactory
{
    public function __construct(
        private readonly UrlRewriter $urlRewriter,
        private readonly MediaFileLocator $mediaFileLocator,
        private readonly CategoryFactory $categoryFactory,
    ) {}

    public function create(Resource $resource): ?Item
    {
        $title = $resource->data->getString(
            'base.teaser.headline',
            $resource->data->getString(
                'metadata.headline',
                $resource->data->getString('base.title'),
            ),
        );
        if ($title === '') {
            return null;
        }

        $link = $this->url(UrlRewriteType::LINK, $resource->location, $resource);
        $description = $resource->data->getString(
            'base.teaser.text',
            $resource->data->getString('metadata.description'),
        );

        return new Item(
            title: $title,
            link: $link,
            // The link doubles as the guid. Readers key their "already seen"
            // state on it, so changing this would resurface every old item.
            guid: $link,
            description: $description === '' ? null : $description,
            pubDate: $this->pubDate($resource),
            media: $this->media($resource),
            categories: $this->categoryFactory->create($resource),
        );
    }

    /**
     * Absent rather than epoch: a resource without a date would otherwise show
     * up as 1970 and sort to the bottom of every reader for good.
     */
    private function pubDate(Resource $resource): ?\DateTimeImmutable
    {
        $timestamp = $resource->data->getInt(
            'base.date',
            $resource->data->getInt('created'),
        );
        return $timestamp > 0
            ? (new \DateTimeImmutable())->setTimestamp($timestamp)
            : null;
    }

    private function media(Resource $resource): ?Media
    {
        $sources = $resource->data->getArray('metadata.image.sources');
        $url = is_array($sources[0] ?? null) ? ($sources[0]['url'] ?? null) : null;
        if (!is_string($url) || $url === '') {
            return null;
        }

        $absoluteUrl = $this->url(UrlRewriteType::IMAGE, $url, $resource);
        $path = $this->mediaFileLocator->locate($url);
        if ($path === null || !is_file($path)) {
            return new Media($absoluteUrl);
        }

        $fileSize = filesize($path);
        $type = mime_content_type($path);

        return new Media(
            $absoluteUrl,
            $type === false ? null : $type,
            $fileSize === false ? null : $fileSize,
        );
    }

    /**
     * Rewritten and fully qualified: a feed is read away from the site, and its
     * links must match the ones the site itself renders.
     */
    private function url(
        UrlRewriteType $type,
        string $url,
        Resource $resource,
    ): string {
        return $this->urlRewriter->rewrite(
            $type,
            $url,
            UrlRewriteOptions::builder()
                ->toFullyQualifiedUrl(true)
                ->lang($resource->lang->code)
                ->build(),
        );
    }
}
