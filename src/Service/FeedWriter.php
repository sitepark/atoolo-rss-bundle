<?php

declare(strict_types=1);

namespace Atoolo\Rss\Service;

use Atoolo\Rss\Dto\Feed;
use Atoolo\Rss\Dto\Item;
use Atoolo\Rss\Dto\Media;

/**
 * Serialises a feed to RSS 2.0. The only class that knows about XML.
 */
class FeedWriter
{
    private const MEDIA_NAMESPACE = 'http://search.yahoo.com/mrss/';

    private const ATOM_NAMESPACE = 'http://www.w3.org/2005/Atom';

    private const CONTENT_NAMESPACE = 'http://purl.org/rss/1.0/modules/content/';

    public function write(Feed $feed): string
    {
        $writer = new \XMLWriter();
        $writer->openMemory();
        $writer->setIndent(true);
        $writer->startDocument('1.0', 'UTF-8');

        $writer->startElement('rss');
        $writer->writeAttribute('version', '2.0');
        $writer->writeAttribute('xmlns:atom', self::ATOM_NAMESPACE);
        $writer->writeAttribute('xmlns:media', self::MEDIA_NAMESPACE);

        $this->writeChannel($writer, $feed);

        $writer->endElement();
        $writer->endDocument();

        return $writer->outputMemory();
    }

    private function writeChannel(\XMLWriter $writer, Feed $feed): void
    {
        $channel = $feed->channel;

        $writer->startElement('channel');
        $writer->writeElement('title', $channel->title);
        $writer->writeElement('link', $channel->link);
        // Required by RSS 2.0, so written even when empty.
        $writer->writeElement('description', $channel->description ?? '');
        $this->writeOptionalElement($writer, 'language', $channel->language);
        $this->writeOptionalElement($writer, 'copyright', $channel->copyright);
        $this->writeOptionalElement($writer, 'generator', $channel->generator);

        $writer->startElement('atom:link');
        $writer->writeAttribute('href', $channel->selfLink);
        $writer->writeAttribute('rel', 'self');
        $writer->writeAttribute('type', 'application/rss+xml');
        $writer->endElement();

        $lastBuildDate = $feed->getLastBuildDate();
        if ($lastBuildDate !== null) {
            $writer->writeElement(
                'lastBuildDate',
                $lastBuildDate->format(\DateTimeInterface::RSS),
            );
        }

        if ($channel->image !== null) {
            $writer->startElement('image');
            $writer->writeElement('url', $channel->image->url);
            $writer->writeElement('title', $channel->image->title);
            $writer->writeElement('link', $channel->image->link);
            $writer->endElement();
        }

        foreach ($feed->items as $item) {
            $this->writeItem($writer, $item);
        }

        $writer->endElement();
    }

    private function writeItem(\XMLWriter $writer, Item $item): void
    {
        $writer->startElement('item');
        $writer->writeElement('title', $item->title);
        $writer->writeElement('link', $item->link);

        $writer->startElement('guid');
        $writer->writeAttribute('isPermaLink', 'true');
        $writer->text($item->guid);
        $writer->endElement();

        $this->writeOptionalElement($writer, 'description', $item->description);

        if ($item->pubDate !== null) {
            $writer->writeElement(
                'pubDate',
                $item->pubDate->format(\DateTimeInterface::RSS),
            );
        }

        if ($item->content !== null && $item->content !== '') {
            $writer->writeElementNs(
                'content',
                'encoded',
                self::CONTENT_NAMESPACE,
                $item->content,
            );
        }

        if ($item->media !== null) {
            $writer->startElement('media:content');
            $this->writeMediaAttributes($writer, $item->media);
            $writer->endElement();
        }

        if ($item->thumbnail !== null) {
            $writer->startElement('media:thumbnail');
            $writer->writeAttribute('url', $item->thumbnail->url);
            $writer->endElement();
        }

        foreach ($item->categories as $category) {
            $writer->startElement('category');
            if ($category->domain !== null) {
                $writer->writeAttribute('domain', $category->domain);
            }
            $writer->text($category->name);
            $writer->endElement();
        }

        $writer->endElement();
    }

    private function writeMediaAttributes(\XMLWriter $writer, Media $media): void
    {
        $writer->writeAttribute('url', $media->url);
        if ($media->type !== null) {
            $writer->writeAttribute('type', $media->type);
        }
        if ($media->fileSize !== null) {
            $writer->writeAttribute('fileSize', (string) $media->fileSize);
        }
        if ($media->medium !== null) {
            $writer->writeAttribute('medium', $media->medium);
        }
    }

    private function writeOptionalElement(
        \XMLWriter $writer,
        string $name,
        ?string $value,
    ): void {
        if ($value !== null && $value !== '') {
            $writer->writeElement($name, $value);
        }
    }
}
