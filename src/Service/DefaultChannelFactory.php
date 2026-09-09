<?php

declare(strict_types=1);

namespace Atoolo\Rss\Service;

use Atoolo\Resource\Resource;
use Atoolo\Resource\ResourceChannel;
use Atoolo\Rewrite\Dto\UrlRewriteOptions;
use Atoolo\Rewrite\Dto\UrlRewriteType;
use Atoolo\Rewrite\Service\UrlRewriter;
use Atoolo\Rss\Dto\Channel;
use Atoolo\Rss\Dto\Image;

/**
 * Reads the channel metadata off the resource the feed belongs to, or off the
 * site itself when there is none.
 */
class DefaultChannelFactory implements ChannelFactory
{
    public function __construct(
        private readonly UrlRewriter $urlRewriter,
        private readonly ResourceChannel $resourceChannel,
        private readonly ?string $generator = null,
        private readonly ?string $copyright = null,
        private readonly ?string $description = null,
    ) {}

    public function create(?Resource $resource, string $selfLink): Channel
    {
        return $resource === null
            ? $this->fromSite($selfLink)
            : $this->fromResource($resource, $selfLink);
    }

    private function fromResource(Resource $resource, string $selfLink): Channel
    {
        $lang = $resource->lang->code;
        $title = $resource->data->getString(
            'base.teaser.headline',
            $resource->data->getString(
                'metadata.headline',
                $resource->data->getString('base.title'),
            ),
        );
        if ($title === '') {
            $title = $this->resourceChannel->name;
        }
        $description = $resource->data->getString('metadata.description');
        $link = $this->url(UrlRewriteType::LINK, $resource->location, $lang);

        return new Channel(
            title: $title,
            link: $link,
            selfLink: $selfLink,
            description: $description !== '' ? $description : $this->description,
            language: $lang !== '' ? $lang : $this->siteLanguage(),
            copyright: $this->copyright,
            generator: $this->generator,
            image: $this->image($resource, $title, $link, $lang),
        );
    }

    private function fromSite(string $selfLink): Channel
    {
        $lang = $this->siteLanguage();

        return new Channel(
            title: $this->resourceChannel->name,
            link: $this->url(UrlRewriteType::LINK, '/', $lang ?? ''),
            selfLink: $selfLink,
            description: $this->description,
            language: $lang,
            copyright: $this->copyright,
            generator: $this->generator,
        );
    }

    /**
     * The channel locale is a full locale ('de_DE'); RSS wants the language.
     */
    private function siteLanguage(): ?string
    {
        $locale = $this->resourceChannel->locale;
        if ($locale === '') {
            return null;
        }
        return explode('_', $locale)[0];
    }

    private function image(
        Resource $resource,
        string $title,
        string $link,
        string $lang,
    ): ?Image {
        $sources = $resource->data->getArray('metadata.image.sources');
        $url = is_array($sources[0] ?? null) ? ($sources[0]['url'] ?? null) : null;
        if (!is_string($url) || $url === '') {
            return null;
        }
        return new Image(
            $this->url(UrlRewriteType::IMAGE, $url, $lang),
            $title,
            $link,
        );
    }

    /**
     * Fully qualified: a feed is read away from the site.
     */
    private function url(
        UrlRewriteType $type,
        string $url,
        string $lang,
    ): string {
        return $this->urlRewriter->rewrite(
            $type,
            $url,
            UrlRewriteOptions::builder()
                ->toFullyQualifiedUrl(true)
                ->lang($lang)
                ->build(),
        );
    }
}
