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
 * The result is normalised across both formats:
 * - titles are plain text, descriptions and contents are HTML. That HTML
 *   comes from a foreign site and has to be sanitised before it is output.
 * - urls are absolute, resolved against `xml:base` and the feed's url.
 * - values the DTOs require but a feed may omit are filled in: an item's
 *   `guid` from its link and vice versa (for permalink guids that are http
 *   urls), the channel's links from the feed's url, and the title and link
 *   of the channel image from the channel. Items with neither link nor guid
 *   are skipped.
 */
class FeedParser
{
    private const ATOM_NAMESPACE = 'http://www.w3.org/2005/Atom';

    private const MEDIA_NAMESPACE = 'http://search.yahoo.com/mrss/';

    private const CONTENT_NAMESPACE = 'http://purl.org/rss/1.0/modules/content/';

    private const DC_NAMESPACE = 'http://purl.org/dc/elements/1.1/';

    private const XML_NAMESPACE = 'http://www.w3.org/XML/1998/namespace';

    /**
     * RFC 822 (without the weekday, see `date()`) and RFC 3339; `|` resets
     * the fields a format does not contain instead of taking them from now.
     */
    private const DATE_FORMATS = [
        'd M Y H:i:s T|',
        'd M Y H:i T|',
        'Y-m-d\TH:i:sP|',
        'Y-m-d\TH:i:s.uP|',
    ];

    public function __construct(
        /** items read from a feed at most, the rest is ignored */
        private readonly int $maxItems = 500,
        private readonly UrlResolver $urlResolver = new UrlResolver(),
    ) {}

    /**
     * @param string $url where the document was loaded from
     * @throws FeedParseException
     */
    public function parse(string $xml, string $url): Feed
    {
        $root = $this->load($xml, $url);

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
    private function load(string $xml, string $url): \DOMElement
    {
        if (trim($xml) === '') {
            throw new FeedParseException('empty document');
        }

        $document = new \DOMDocument();
        $useInternalErrors = libxml_use_internal_errors(true);
        try {
            // LIBXML_NONET and no LIBXML_DTDLOAD: external entities and DTDs
            // are never loaded. Internal entities are expanded; libxml aborts
            // recursive ones.
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

        // the base of all relative urls, unless overridden by xml:base
        $document->documentURI = $url;

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
        $link = $this->urlText($this->child($channel, 'link')) ?? $url;

        $image = null;
        $imageElement = $this->child($channel, 'image');
        $imageUrl = $this->urlText($this->child($imageElement, 'url'));
        if ($imageUrl !== null) {
            $image = new Image(
                $imageUrl,
                $this->text($this->child($imageElement, 'title')) ?? $title,
                $this->urlText($this->child($imageElement, 'link')) ?? $link,
            );
        }

        $items = [];
        foreach ($this->children($channel, 'item') as $element) {
            $item = $this->parseRssItem($element);
            if ($item !== null) {
                $items[] = $item;
            }
            if (count($items) >= $this->maxItems) {
                break;
            }
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

    private function parseRssItem(\DOMElement $item): ?Item
    {
        $guidElement = $this->child($item, 'guid');
        $guid = $this->text($guidElement);
        $link = $this->urlText($this->child($item, 'link'));
        // Many feeds omit isPermaLink="false" on guids that are no urls.
        if (
            $link === null
            && $guid !== null
            && $guidElement?->getAttribute('isPermaLink') !== 'false'
            && $this->isHttpUrl($guid)
        ) {
            $link = $guid;
        }
        if ($link === null && $guid === null) {
            return null;
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
            $enclosures[] = $this->media($enclosure, 'url', 'length');
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
        $title = $this->atomPlainText($this->atomChild($feed, 'title')) ?? '';
        $link = $this->atomLink($feed, 'alternate') ?? $url;

        $image = null;
        $imageUrl = $this->urlText($this->atomChild($feed, 'logo'))
            ?? $this->urlText($this->atomChild($feed, 'icon'));
        if ($imageUrl !== null) {
            $image = new Image($imageUrl, $title, $link);
        }

        $language = $feed->getAttributeNS(self::XML_NAMESPACE, 'lang');

        $items = [];
        foreach ($this->children($feed, 'entry', self::ATOM_NAMESPACE) as $entry) {
            $item = $this->parseAtomEntry($entry);
            if ($item !== null) {
                $items[] = $item;
            }
            if (count($items) >= $this->maxItems) {
                break;
            }
        }

        return new Feed(
            new Channel(
                title: $title,
                link: $link,
                selfLink: $this->atomLink($feed, 'self') ?? $url,
                description: $this->atomHtml($this->atomChild($feed, 'subtitle')),
                language: $language !== '' ? $language : null,
                copyright: $this->atomPlainText($this->atomChild($feed, 'rights')),
                generator: $this->text($this->atomChild($feed, 'generator')),
                image: $image,
            ),
            $items,
        );
    }

    private function parseAtomEntry(\DOMElement $entry): ?Item
    {
        $link = $this->atomLink($entry, 'alternate');
        $guid = $this->text($this->atomChild($entry, 'id'));
        if ($link === null && $guid === null) {
            return null;
        }
        $link ??= '';

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
            title: $this->atomPlainText($this->atomChild($entry, 'title')) ?? '',
            link: $link,
            guid: $guid ?? $link,
            description: $this->atomHtml($this->atomChild($entry, 'summary')),
            pubDate: $this->date(
                $this->text($this->atomChild($entry, 'published'))
                    ?? $this->text($this->atomChild($entry, 'updated')),
            ),
            media: $media,
            categories: $categories,
            content: $this->atomHtml($content),
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
        $url = $this->url($element, $this->attribute($element, $urlAttribute));
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
     * The resolved `href` of the first `atom:link` with the given relation;
     * a link without `rel` is an alternate one.
     */
    private function atomLink(\DOMElement $element, string $rel): ?string
    {
        foreach ($this->children($element, 'link', self::ATOM_NAMESPACE) as $link) {
            $linkRel = $link->getAttribute('rel');
            if (($linkRel !== '' ? $linkRel : 'alternate') !== $rel) {
                continue;
            }
            $href = $this->url($link, $this->attribute($link, 'href'));
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
     * An Atom text construct or content as HTML: `text` is escaped, `html`
     * is taken as it is and `xhtml` is the markup inside its wrapping `div`.
     * Other media types of `content` are not supported.
     */
    private function atomHtml(?\DOMElement $element): ?string
    {
        if ($element === null) {
            return null;
        }
        switch ($element->getAttribute('type')) {
            case '':
            case 'text':
            case 'text/plain':
                $text = $this->text($element);
                return $text !== null
                    ? htmlspecialchars(
                        $text,
                        ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                    )
                    : null;
            case 'html':
            case 'text/html':
                return $this->text($element);
            case 'xhtml':
            case 'application/xhtml+xml':
                return $this->xhtml($element);
            default:
                return null;
        }
    }

    /**
     * An Atom text construct as plain text.
     */
    private function atomPlainText(?\DOMElement $element): ?string
    {
        $text = $this->text($element);
        if ($text === null || $element?->getAttribute('type') !== 'html') {
            return $text;
        }
        $text = trim(html_entity_decode(
            strip_tags($text),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        ));
        return $text !== '' ? $text : null;
    }

    private function xhtml(\DOMElement $element): ?string
    {
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

    /**
     * Absolute dates only: relative ones like "now" or "tomorrow" are
     * ignored.
     */
    private function date(?string $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        // A weekday that does not match the date would move the date to it.
        $value = (string) preg_replace('/^[a-z]+,\s*/i', '', $value);
        foreach (self::DATE_FORMATS as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if ($date !== false) {
                return $date;
            }
        }

        $parsed = date_parse($value);
        if (
            $parsed['error_count'] > 0
            || isset($parsed['relative'])
            || $parsed['year'] === false
            || $parsed['month'] === false
            || $parsed['day'] === false
        ) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function isHttpUrl(string $value): bool
    {
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        return ($scheme === 'http' || $scheme === 'https')
            && parse_url($value, PHP_URL_HOST) !== null;
    }

    /**
     * Resolves a url found in the given element against its base.
     */
    private function url(\DOMElement $element, ?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        return $this->urlResolver->resolve($element->baseURI ?? '', $url);
    }

    private function urlText(?\DOMElement $element): ?string
    {
        return $element !== null
            ? $this->url($element, $this->text($element))
            : null;
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
