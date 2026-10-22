<?php

declare(strict_types=1);

namespace Atoolo\Rss\Service;

use Atoolo\Rss\Dto\Category;
use Atoolo\Rss\Dto\Channel;
use Atoolo\Rss\Dto\Feed;
use Atoolo\Rss\Dto\Image;
use Atoolo\Rss\Dto\Item;
use Atoolo\Rss\Dto\Media;
use Atoolo\Rss\Exception\FeedParseException;

/**
 * Parses RSS 2.0 and Atom 1.0 documents, including the Media RSS and
 * `content:encoded` extensions. The counterpart of `FeedWriter`.
 *
 * Values the DTOs require but a feed may omit are filled with fallbacks:
 * an item's `guid` from its link (and vice versa for permalink guids), the
 * channel's links from the url the feed was loaded from, and the title and
 * link of the channel image from the channel.
 */
class FeedParser
{
    private const ATOM_NAMESPACE = 'http://www.w3.org/2005/Atom';

    private const MEDIA_NAMESPACE = 'http://search.yahoo.com/mrss/';

    private const CONTENT_NAMESPACE = 'http://purl.org/rss/1.0/modules/content/';

    private const DC_NAMESPACE = 'http://purl.org/dc/elements/1.1/';

    private const XML_NAMESPACE = 'http://www.w3.org/XML/1998/namespace';

    /**
     * @param string $url where the document was loaded from
     * @throws FeedParseException
     */
    public function parse(string $xml, string $url): Feed
    {
        $root = $this->load($xml);

        if ($root->namespaceURI === null && $root->localName === 'rss') {
            return $this->parseRss($root, $url);
        }
        if (
            $root->namespaceURI === self::ATOM_NAMESPACE
            && $root->localName === 'feed'
        ) {
            return $this->parseAtom($root, $url);
        }

        throw new FeedParseException(
            'unsupported feed format, root element is <'
                . $root->nodeName . '>',
        );
    }

    /**
     * @throws FeedParseException
     */
    private function load(string $xml): \DOMElement
    {
        if (trim($xml) === '') {
            throw new FeedParseException('empty document');
        }

        $document = new \DOMDocument();
        $useInternalErrors = libxml_use_internal_errors(true);
        try {
            // No LIBXML_NOENT: entities stay unresolved, external ones are
            // never loaded.
            $loaded = $document->loadXML($xml, LIBXML_NONET);
            $error = libxml_get_last_error();
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($useInternalErrors);
        }

        if (!$loaded || $document->documentElement === null) {
            throw new FeedParseException(
                'not a well-formed XML document'
                    . ($error !== false ? ': ' . trim($error->message) : ''),
            );
        }

        return $document->documentElement;
    }

    /**
     * @throws FeedParseException
     */
    private function parseRss(\DOMElement $rss, string $url): Feed
    {
        $channel = $this->child($rss, 'channel')
            ?? throw new FeedParseException('RSS document without <channel>');

        $title = $this->text($this->child($channel, 'title')) ?? '';
        $link = $this->text($this->child($channel, 'link')) ?? $url;

        $image = null;
        $imageElement = $this->child($channel, 'image');
        $imageUrl = $this->text($this->child($imageElement, 'url'));
        if ($imageUrl !== null) {
            $image = new Image(
                $imageUrl,
                $this->text($this->child($imageElement, 'title')) ?? $title,
                $this->text($this->child($imageElement, 'link')) ?? $link,
            );
        }

        $items = [];
        foreach ($this->children($channel, 'item') as $item) {
            $items[] = $this->parseRssItem($item);
        }

        return new Feed(
            new Channel(
                title: $title,
                link: $link,
                selfLink: $this->atomLink($channel, 'self') ?? $url,
                description: $this->text($this->child($channel, 'description')),
                language: $this->text($this->child($channel, 'language')),
                copyright: $this->text($this->child($channel, 'copyright')),
                generator: $this->text($this->child($channel, 'generator')),
                image: $image,
            ),
            $items,
        );
    }

    private function parseRssItem(\DOMElement $item): Item
    {
        $guidElement = $this->child($item, 'guid');
        $guid = $this->text($guidElement);
        $link = $this->text($this->child($item, 'link'));
        if (
            $link === null
            && $guid !== null
            && $guidElement?->getAttribute('isPermaLink') !== 'false'
        ) {
            $link = $guid;
        }
        $link ??= '';

        $categories = [];
        foreach ($this->children($item, 'category') as $category) {
            $name = $this->text($category);
            if ($name !== null) {
                $categories[] = new Category(
                    $name,
                    $this->attribute($category, 'domain'),
                );
            }
        }

        $enclosures = [];
        foreach ($this->children($item, 'enclosure') as $enclosure) {
            $enclosures[] = $this->media(
                $enclosure,
                'url',
                'length',
            );
        }
        [$media, $thumbnail] = $this->parseMedia($item, $enclosures);

        return new Item(
            title: $this->text($this->child($item, 'title')) ?? '',
            link: $link,
            guid: $guid ?? $link,
            description: $this->text($this->child($item, 'description')),
            pubDate: $this->date(
                $this->text($this->child($item, 'pubDate'))
                    ?? $this->text(
                        $this->child($item, 'date', self::DC_NAMESPACE),
                    ),
            ),
            media: $media,
            categories: $categories,
            content: $this->text(
                $this->child($item, 'encoded', self::CONTENT_NAMESPACE),
            ),
            thumbnail: $thumbnail,
        );
    }

    private function parseAtom(\DOMElement $feed, string $url): Feed
    {
        $title = $this->atomText($this->atomChild($feed, 'title')) ?? '';
        $link = $this->atomLink($feed, 'alternate') ?? $url;

        $image = null;
        $imageUrl = $this->text($this->atomChild($feed, 'logo'))
            ?? $this->text($this->atomChild($feed, 'icon'));
        if ($imageUrl !== null) {
            $image = new Image($imageUrl, $title, $link);
        }

        $language = $feed->getAttributeNS(self::XML_NAMESPACE, 'lang');

        $items = [];
        foreach ($this->children($feed, 'entry', self::ATOM_NAMESPACE) as $entry) {
            $items[] = $this->parseAtomEntry($entry);
        }

        return new Feed(
            new Channel(
                title: $title,
                link: $link,
                selfLink: $this->atomLink($feed, 'self') ?? $url,
                description: $this->atomText($this->atomChild($feed, 'subtitle')),
                language: $language !== '' ? $language : null,
                copyright: $this->atomText($this->atomChild($feed, 'rights')),
                generator: $this->text($this->atomChild($feed, 'generator')),
                image: $image,
            ),
            $items,
        );
    }

    private function parseAtomEntry(\DOMElement $entry): Item
    {
        $link = $this->atomLink($entry, 'alternate') ?? '';

        $categories = [];
        foreach ($this->children($entry, 'category', self::ATOM_NAMESPACE) as $category) {
            $term = $this->attribute($category, 'term');
            if ($term !== null) {
                $categories[] = new Category(
                    $this->attribute($category, 'label') ?? $term,
                    $this->attribute($category, 'scheme'),
                );
            }
        }

        $enclosures = [];
        foreach ($this->children($entry, 'link', self::ATOM_NAMESPACE) as $atomLink) {
            if ($atomLink->getAttribute('rel') === 'enclosure') {
                $enclosures[] = $this->media($atomLink, 'href', 'length');
            }
        }
        [$media, $thumbnail] = $this->parseMedia($entry, $enclosures);

        // Content given by reference (`src`) is not loaded.
        $content = $this->atomChild($entry, 'content');
        if ($content?->hasAttribute('src')) {
            $content = null;
        }

        return new Item(
            title: $this->atomText($this->atomChild($entry, 'title')) ?? '',
            link: $link,
            guid: $this->text($this->atomChild($entry, 'id')) ?? $link,
            description: $this->atomText($this->atomChild($entry, 'summary')),
            pubDate: $this->date(
                $this->text($this->atomChild($entry, 'published'))
                    ?? $this->text($this->atomChild($entry, 'updated')),
            ),
            media: $media,
            categories: $categories,
            content: $this->atomText($content),
            thumbnail: $thumbnail,
        );
    }

    /**
     * Picks the item's media from its `media:content` entries (also inside a
     * `media:group`) and its enclosures; an image is preferred, as that is
     * what a feed's single media is usually displayed as. The thumbnail is
     * the first `media:thumbnail` of the item, its group or its media.
     *
     * @param list<?Media> $enclosures
     * @return array{?Media, ?Media}
     */
    private function parseMedia(\DOMElement $element, array $enclosures): array
    {
        $containers = [$element];
        foreach ($this->children($element, 'group', self::MEDIA_NAMESPACE) as $group) {
            $containers[] = $group;
        }

        $contents = [];
        foreach ($containers as $container) {
            foreach ($this->children($container, 'content', self::MEDIA_NAMESPACE) as $content) {
                $contents[] = $content;
            }
        }

        $candidates = [];
        foreach ($contents as $content) {
            $candidates[] = $this->media($content, 'url', 'fileSize');
        }
        $candidates = array_values(
            array_filter([...$candidates, ...$enclosures]),
        );

        $media = $candidates[0] ?? null;
        foreach ($candidates as $candidate) {
            if ($this->isImage($candidate)) {
                $media = $candidate;
                break;
            }
        }

        $thumbnail = null;
        foreach ([...$containers, ...$contents] as $container) {
            foreach ($this->children($container, 'thumbnail', self::MEDIA_NAMESPACE) as $candidate) {
                $thumbnail = $this->media($candidate, 'url', null);
                if ($thumbnail !== null) {
                    break 2;
                }
            }
        }

        return [$media, $thumbnail];
    }

    private function media(
        \DOMElement $element,
        string $urlAttribute,
        ?string $sizeAttribute,
    ): ?Media {
        $url = $this->attribute($element, $urlAttribute);
        if ($url === null) {
            return null;
        }
        $size = $sizeAttribute !== null
            ? $this->attribute($element, $sizeAttribute)
            : null;
        return new Media(
            $url,
            $this->attribute($element, 'type'),
            // An unknown size is often given as 0.
            $size !== null && ctype_digit($size) && (int) $size > 0
                ? (int) $size
                : null,
            $this->attribute($element, 'medium'),
        );
    }

    private function isImage(Media $media): bool
    {
        return $media->medium === 'image'
            || str_starts_with($media->type ?? '', 'image/');
    }

    /**
     * The `href` of the first `atom:link` with the given relation; a link
     * without `rel` is an alternate one.
     */
    private function atomLink(\DOMElement $element, string $rel): ?string
    {
        foreach ($this->children($element, 'link', self::ATOM_NAMESPACE) as $link) {
            $linkRel = $link->getAttribute('rel');
            if (($linkRel !== '' ? $linkRel : 'alternate') !== $rel) {
                continue;
            }
            $href = $this->attribute($link, 'href');
            if ($href !== null) {
                return $href;
            }
        }
        return null;
    }

    private function atomChild(\DOMElement $element, string $name): ?\DOMElement
    {
        return $this->child($element, $name, self::ATOM_NAMESPACE);
    }

    /**
     * An Atom text construct: `xhtml` is returned as the markup inside its
     * wrapping `div`, `text` and `html` as their text.
     */
    private function atomText(?\DOMElement $element): ?string
    {
        if ($element === null || $element->getAttribute('type') !== 'xhtml') {
            return $this->text($element);
        }

        $div = null;
        foreach ($element->childNodes as $node) {
            if ($node instanceof \DOMElement) {
                $div = $node;
                break;
            }
        }
        if ($div === null || $element->ownerDocument === null) {
            return null;
        }

        $markup = '';
        foreach ($div->childNodes as $node) {
            $markup .= $element->ownerDocument->saveXML($node);
        }
        $markup = trim($markup);
        return $markup !== '' ? $markup : null;
    }

    private function date(?string $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function child(
        ?\DOMElement $element,
        string $name,
        ?string $namespace = null,
    ): ?\DOMElement {
        if ($element === null) {
            return null;
        }
        foreach ($this->children($element, $name, $namespace) as $child) {
            return $child;
        }
        return null;
    }

    /**
     * @return iterable<\DOMElement>
     */
    private function children(
        \DOMElement $element,
        string $name,
        ?string $namespace = null,
    ): iterable {
        foreach ($element->childNodes as $node) {
            if (
                $node instanceof \DOMElement
                && $node->localName === $name
                && $node->namespaceURI === $namespace
            ) {
                yield $node;
            }
        }
    }

    private function attribute(\DOMElement $element, string $name): ?string
    {
        $value = trim($element->getAttribute($name));
        return $value !== '' ? $value : null;
    }

    private function text(?\DOMElement $element): ?string
    {
        $text = trim($element?->textContent ?? '');
        return $text !== '' ? $text : null;
    }
}
