<?php

declare(strict_types=1);

namespace Atoolo\Rss\Test\Service;

use Atoolo\Rss\Dto\Category;
use Atoolo\Rss\Dto\Channel;
use Atoolo\Rss\Dto\Feed;
use Atoolo\Rss\Dto\Image;
use Atoolo\Rss\Dto\Item;
use Atoolo\Rss\Dto\Media;
use Atoolo\Rss\Exception\FeedParseException;
use Atoolo\Rss\Service\FeedParser;
use Atoolo\Rss\Service\FeedWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(FeedParser::class)]
class FeedParserTest extends TestCase
{
    private const URL = 'https://feeds.example.com/news.xml';

    private FeedParser $parser;

    public function setUp(): void
    {
        $this->parser = new FeedParser();
    }

    public function testParseRss(): void
    {
        $xml = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0"
            xmlns:atom="http://www.w3.org/2005/Atom"
            xmlns:media="http://search.yahoo.com/mrss/"
            xmlns:content="http://purl.org/rss/1.0/modules/content/">
         <channel>
          <title>Presse &amp; Aktuelles</title>
          <link>https://www.example.com/presse.php</link>
          <description>Meldungen</description>
          <language>de</language>
          <copyright>Copyright 2026</copyright>
          <generator>IES</generator>
          <atom:link href="https://www.example.com/feed.xml" rel="self" type="application/rss+xml"/>
          <image>
           <url>https://www.example.com/logo.png</url>
           <title>Presse</title>
           <link>https://www.example.com/</link>
          </image>
          <item>
           <title>Rat beschließt Haushalt</title>
           <link>https://www.example.com/a.php</link>
           <guid isPermaLink="false">a-1</guid>
           <description><![CDATA[Text mit <b>Markup</b>]]></description>
           <content:encoded><![CDATA[<p>Volltext</p>]]></content:encoded>
           <pubDate>Sun, 01 Mar 2026 12:00:00 +0100</pubDate>
           <media:content url="https://www.example.com/i.jpg" type="image/jpeg" fileSize="12345" medium="image">
            <media:thumbnail url="https://www.example.com/t.jpg"/>
           </media:content>
           <category domain="https://www.example.com/kategorien/">Rat</category>
           <category>Stadt</category>
          </item>
         </channel>
        </rss>
        XML;

        $expected = new Feed(
            new Channel(
                title: 'Presse & Aktuelles',
                link: 'https://www.example.com/presse.php',
                selfLink: 'https://www.example.com/feed.xml',
                description: 'Meldungen',
                language: 'de',
                copyright: 'Copyright 2026',
                generator: 'IES',
                image: new Image(
                    'https://www.example.com/logo.png',
                    'Presse',
                    'https://www.example.com/',
                ),
            ),
            [
                new Item(
                    title: 'Rat beschließt Haushalt',
                    link: 'https://www.example.com/a.php',
                    guid: 'a-1',
                    description: 'Text mit <b>Markup</b>',
                    pubDate: new \DateTimeImmutable('2026-03-01T12:00:00+01:00'),
                    media: new Media(
                        'https://www.example.com/i.jpg',
                        'image/jpeg',
                        12345,
                        'image',
                    ),
                    categories: [
                        new Category('Rat', 'https://www.example.com/kategorien/'),
                        new Category('Stadt'),
                    ],
                    content: '<p>Volltext</p>',
                    thumbnail: new Media('https://www.example.com/t.jpg'),
                ),
            ],
        );

        $this->assertEquals(
            $expected,
            $this->parser->parse($xml, self::URL),
            'all RSS elements should be read',
        );
    }

    public function testParseMinimalRssFallsBackToUrl(): void
    {
        $xml = '<rss version="2.0"><channel><item>'
            . '<guid isPermaLink="false">a-1</guid>'
            . '<description>Nur Text</description>'
            . '</item></channel></rss>';

        $expected = new Feed(
            new Channel(title: '', link: self::URL, selfLink: self::URL),
            [new Item(title: '', link: '', guid: 'a-1', description: 'Nur Text')],
        );

        $this->assertEquals($expected, $this->parser->parse($xml, self::URL));
    }

    public function testParseRssPermaLinkGuidAsLink(): void
    {
        $feed = $this->parseRssItems(
            '<item><guid>https://www.example.com/a.php</guid></item>',
        );

        $this->assertSame(
            'https://www.example.com/a.php',
            $feed->items[0]->link,
            'a guid is a permalink unless isPermaLink="false"',
        );
    }

    public function testParseRssNonPermaLinkGuidIsNoLink(): void
    {
        $feed = $this->parseRssItems(
            '<item><guid isPermaLink="false">a-1</guid></item>',
        );

        $this->assertSame('', $feed->items[0]->link);
    }

    public function testParseRssGuidFallsBackToLink(): void
    {
        $feed = $this->parseRssItems(
            '<item><link>https://www.example.com/a.php</link></item>',
        );

        $this->assertSame('https://www.example.com/a.php', $feed->items[0]->guid);
    }

    public function testParseRssImageFallsBackToChannel(): void
    {
        $xml = '<rss version="2.0"><channel>'
            . '<title>T</title><link>https://www.example.com/</link>'
            . '<image><url>https://www.example.com/logo.png</url></image>'
            . '</channel></rss>';

        $this->assertEquals(
            new Image(
                'https://www.example.com/logo.png',
                'T',
                'https://www.example.com/',
            ),
            $this->parser->parse($xml, self::URL)->channel->image,
        );
    }

    public function testParseRssDublinCoreDate(): void
    {
        $feed = $this->parseRssItems(
            '<item xmlns:dc="http://purl.org/dc/elements/1.1/">'
                . '<link>https://www.example.com/a.php</link><dc:date>2026-03-01T12:00:00+01:00</dc:date></item>',
        );

        $this->assertEquals(
            new \DateTimeImmutable('2026-03-01T12:00:00+01:00'),
            $feed->items[0]->pubDate,
        );
    }

    public function testParseInvalidDateIsIgnored(): void
    {
        $feed = $this->parseRssItems(
            '<item><link>https://www.example.com/a.php</link><pubDate>gestern</pubDate></item>',
        );

        $this->assertNull($feed->items[0]->pubDate);
    }

    public function testParseEnclosure(): void
    {
        $feed = $this->parseRssItems(
            '<item><link>https://www.example.com/a.php</link><enclosure url="https://www.example.com/i.png" '
                . 'type="image/png" length="0"/></item>',
        );

        $this->assertEquals(
            new Media('https://www.example.com/i.png', 'image/png'),
            $feed->items[0]->media,
            'a length of 0 means an unknown size',
        );
    }

    public function testParseMediaPrefersImage(): void
    {
        $feed = $this->parseRssItems(
            '<item xmlns:media="http://search.yahoo.com/mrss/"><link>https://www.example.com/a.php</link>'
                . '<enclosure url="https://www.example.com/a.mp3" type="audio/mpeg" length="100"/>'
                . '<media:content url="https://www.example.com/v.mp4" medium="video"/>'
                . '<media:content url="https://www.example.com/i.jpg" type="image/jpeg"/>'
                . '</item>',
        );

        $this->assertSame(
            'https://www.example.com/i.jpg',
            $feed->items[0]->media?->url,
        );
    }

    public function testParseMediaWithoutImage(): void
    {
        $feed = $this->parseRssItems(
            '<item><link>https://www.example.com/a.php</link><enclosure url="https://www.example.com/a.mp3" '
                . 'type="audio/mpeg" length="100"/></item>',
        );

        $this->assertEquals(
            new Media('https://www.example.com/a.mp3', 'audio/mpeg', 100),
            $feed->items[0]->media,
        );
    }

    public function testParseMediaGroup(): void
    {
        $feed = $this->parseRssItems(
            '<item xmlns:media="http://search.yahoo.com/mrss/"><link>https://www.example.com/a.php</link><media:group>'
                . '<media:content url="https://www.example.com/v.mp4" medium="video"/>'
                . '<media:thumbnail url="https://www.example.com/t.jpg"/>'
                . '</media:group></item>',
        );

        $this->assertSame(
            'https://www.example.com/v.mp4',
            $feed->items[0]->media?->url,
        );
        $this->assertSame(
            'https://www.example.com/t.jpg',
            $feed->items[0]->thumbnail?->url,
        );
    }

    public function testParseMediaWithoutUrlIsIgnored(): void
    {
        $feed = $this->parseRssItems(
            '<item xmlns:media="http://search.yahoo.com/mrss/"><link>https://www.example.com/a.php</link>'
                . '<media:content medium="image"/><media:thumbnail/></item>',
        );

        $this->assertNull($feed->items[0]->media);
        $this->assertNull($feed->items[0]->thumbnail);
    }

    public function testParseAtom(): void
    {
        $xml = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <feed xmlns="http://www.w3.org/2005/Atom"
            xmlns:media="http://search.yahoo.com/mrss/" xml:lang="de">
         <title>Presse</title>
         <subtitle>Meldungen</subtitle>
         <link href="https://www.example.com/presse.php"/>
         <link rel="self" href="https://www.example.com/atom.xml"/>
         <rights>Copyright 2026</rights>
         <generator>IES</generator>
         <icon>https://www.example.com/favicon.ico</icon>
         <logo>https://www.example.com/logo.png</logo>
         <entry>
          <id>urn:uuid:1</id>
          <title type="html">Rat &lt;b&gt;beschließt&lt;/b&gt; &amp;amp; mehr</title>
          <link rel="alternate" type="text/html" href="https://www.example.com/a.php"/>
          <link rel="enclosure" type="image/png" length="42" href="https://www.example.com/i.png"/>
          <summary>Zusammenfassung</summary>
          <content type="xhtml"><div xmlns="http://www.w3.org/1999/xhtml"><p>Volltext</p></div></content>
          <updated>2026-03-02T12:00:00+01:00</updated>
          <published>2026-03-01T12:00:00+01:00</published>
          <category term="rat" label="Rat" scheme="https://www.example.com/kategorien/"/>
          <category term="stadt"/>
          <media:thumbnail url="https://www.example.com/t.jpg"/>
         </entry>
        </feed>
        XML;

        $expected = new Feed(
            new Channel(
                title: 'Presse',
                link: 'https://www.example.com/presse.php',
                selfLink: 'https://www.example.com/atom.xml',
                description: 'Meldungen',
                language: 'de',
                copyright: 'Copyright 2026',
                generator: 'IES',
                image: new Image(
                    'https://www.example.com/logo.png',
                    'Presse',
                    'https://www.example.com/presse.php',
                ),
            ),
            [
                new Item(
                    title: 'Rat beschließt & mehr',
                    link: 'https://www.example.com/a.php',
                    guid: 'urn:uuid:1',
                    description: 'Zusammenfassung',
                    pubDate: new \DateTimeImmutable('2026-03-01T12:00:00+01:00'),
                    media: new Media(
                        'https://www.example.com/i.png',
                        'image/png',
                        42,
                    ),
                    categories: [
                        new Category('Rat', 'https://www.example.com/kategorien/'),
                        new Category('stadt'),
                    ],
                    content: '<p>Volltext</p>',
                    thumbnail: new Media('https://www.example.com/t.jpg'),
                ),
            ],
        );

        $this->assertEquals(
            $expected,
            $this->parser->parse($xml, self::URL),
            'all Atom elements should be read',
        );
    }

    public function testParseMinimalAtomFallsBackToUrl(): void
    {
        $xml = '<feed xmlns="http://www.w3.org/2005/Atom"><entry>'
            . '<id>urn:uuid:2</id>'
            . '<updated>2026-03-02T12:00:00Z</updated>'
            . '<content src="https://www.example.com/a.html"/>'
            . '</entry></feed>';

        $expected = new Feed(
            new Channel(title: '', link: self::URL, selfLink: self::URL),
            [
                new Item(
                    title: '',
                    link: '',
                    guid: 'urn:uuid:2',
                    pubDate: new \DateTimeImmutable('2026-03-02T12:00:00Z'),
                ),
            ],
        );

        $this->assertEquals(
            $expected,
            $this->parser->parse($xml, self::URL),
            'updated should be used without published, content by '
                . 'reference should not be loaded',
        );
    }

    public function testParseAtomIconAsImage(): void
    {
        $xml = '<feed xmlns="http://www.w3.org/2005/Atom">'
            . '<icon>https://www.example.com/favicon.ico</icon></feed>';

        $this->assertSame(
            'https://www.example.com/favicon.ico',
            $this->parser->parse($xml, self::URL)->channel->image?->url,
        );
    }

    public function testParseEmptyAtomXhtml(): void
    {
        $xml = '<feed xmlns="http://www.w3.org/2005/Atom"><entry>'
            . '<id>urn:uuid:3</id>'
            . '<summary type="xhtml"><div xmlns="http://www.w3.org/1999/xhtml"> </div></summary>'
            . '<content type="xhtml"/>'
            . '</entry></feed>';

        $item = $this->parser->parse($xml, self::URL)->items[0];

        $this->assertNull($item->description);
        $this->assertNull($item->content);
    }

    public function testParseWhatFeedWriterWrites(): void
    {
        $feed = new Feed(
            new Channel(
                title: 'Presse',
                link: 'https://www.example.com/presse.php',
                selfLink: 'https://www.example.com/feed.xml',
                description: 'Meldungen',
                language: 'de',
                image: new Image(
                    'https://www.example.com/logo.png',
                    'Presse',
                    'https://www.example.com/presse.php',
                ),
            ),
            [
                new Item(
                    title: 'A & B',
                    link: 'https://www.example.com/a.php?x=1&y=2',
                    guid: 'https://www.example.com/a.php?x=1&y=2',
                    description: 'Text mit <b>Markup</b>',
                    pubDate: new \DateTimeImmutable('2026-03-01T12:00:00+01:00'),
                    media: new Media(
                        'https://www.example.com/i.jpg',
                        'image/jpeg',
                        12345,
                        'image',
                    ),
                    categories: [new Category('Rat', 'https://www.example.com/k/')],
                    content: '<p>Volltext</p>',
                    thumbnail: new Media('https://www.example.com/t.jpg'),
                ),
            ],
        );

        $this->assertEquals(
            $feed,
            $this->parser->parse((new FeedWriter())->write($feed), self::URL),
            'parsing a written feed should restore it',
        );
    }

    public function testParseAtomTextAsEscapedHtml(): void
    {
        $xml = '<feed xmlns="http://www.w3.org/2005/Atom"><entry>'
            . '<id>urn:uuid:1</id>'
            . '<summary type="text">a &lt;script&gt;alert(1)&lt;/script&gt;</summary>'
            . '<content>Tom &amp; "Jerry"</content>'
            . '</entry></feed>';

        $item = $this->parser->parse($xml, self::URL)->items[0];

        $this->assertSame(
            'a &lt;script&gt;alert(1)&lt;/script&gt;',
            $item->description,
            'text should be escaped, as description is always HTML',
        );
        $this->assertSame(
            'Tom &amp; &quot;Jerry&quot;',
            $item->content,
            'content without type is text',
        );
    }

    public function testParseAtomContentOfOtherMediaTypeIsIgnored(): void
    {
        $xml = '<feed xmlns="http://www.w3.org/2005/Atom"><entry>'
            . '<id>urn:uuid:1</id>'
            . '<content type="image/png">iVBORw0KGgo=</content>'
            . '</entry></feed>';

        $this->assertNull($this->parser->parse($xml, self::URL)->items[0]->content);
    }

    public function testParseAtomXhtmlTitleAsText(): void
    {
        $xml = '<feed xmlns="http://www.w3.org/2005/Atom"><entry>'
            . '<id>urn:uuid:1</id>'
            . '<title type="xhtml"><div xmlns="http://www.w3.org/1999/xhtml">'
            . 'Rat <b>beschließt</b></div></title>'
            . '</entry></feed>';

        $this->assertSame(
            'Rat beschließt',
            $this->parser->parse($xml, self::URL)->items[0]->title,
        );
    }

    public function testParseRssGuidWithoutUrlIsNoLink(): void
    {
        $feed = $this->parseRssItems('<item><guid>12345@example</guid></item>');

        $this->assertSame(
            '',
            $feed->items[0]->link,
            'a guid should only become the link if it is an http url',
        );
        $this->assertSame('12345@example', $feed->items[0]->guid);
    }

    public function testParseSkipsItemsWithoutLinkAndGuid(): void
    {
        $rss = $this->parseRssItems(
            '<item><title>ohne Link</title></item>'
                . '<item><link>https://www.example.com/a.php</link></item>',
        );
        $atom = $this->parser->parse(
            '<feed xmlns="http://www.w3.org/2005/Atom">'
                . '<entry><title>ohne Link</title></entry>'
                . '<entry><id>urn:uuid:1</id></entry></feed>',
            self::URL,
        );

        $this->assertCount(1, $rss->items);
        $this->assertCount(1, $atom->items);
    }

    public function testParseLimitsAtomEntries(): void
    {
        $entries = str_repeat('<entry><id>urn:uuid:1</id></entry>', 5);

        $feed = (new FeedParser(3))->parse(
            '<feed xmlns="http://www.w3.org/2005/Atom">' . $entries . '</feed>',
            self::URL,
        );

        $this->assertCount(3, $feed->items);
    }

    public function testParseAtomHtmlAsIs(): void
    {
        $xml = '<feed xmlns="http://www.w3.org/2005/Atom"><entry>'
            . '<id>urn:uuid:1</id>'
            . '<summary type="html">Text mit &lt;b&gt;Markup&lt;/b&gt;</summary>'
            . '<content type="text/html">&lt;p&gt;Volltext&lt;/p&gt;</content>'
            . '</entry></feed>';

        $item = $this->parser->parse($xml, self::URL)->items[0];

        $this->assertSame('Text mit <b>Markup</b>', $item->description);
        $this->assertSame('<p>Volltext</p>', $item->content);
    }

    public function testParseDropsUrlsOtherThanHttp(): void
    {
        $xml = '<rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/"><channel>'
            . '<link>JaVaScRiPt:alert(2)</link>'
            . '<image><url>file:///etc/passwd</url></image>'
            . '<item>'
            . '<link>javascript:alert(1)</link>'
            . '<guid>javascript:alert(3)</guid>'
            . '<enclosure url="data:image/svg+xml,&lt;svg onload=alert(1)&gt;" type="image/svg+xml"/>'
            . '<media:thumbnail url="//"/>'
            . '</item>'
            . '<item><link>javascript:alert(4)</link></item>'
            . '</channel></rss>';

        $feed = $this->parser->parse($xml, self::URL);

        $this->assertSame(self::URL, $feed->channel->link, 'falls back to the feed url');
        $this->assertNull($feed->channel->image);
        $this->assertCount(1, $feed->items, 'an item without any usable link or guid is skipped');
        $this->assertSame('', $feed->items[0]->link);
        $this->assertSame('javascript:alert(3)', $feed->items[0]->guid, 'a guid is an identifier, not a url');
        $this->assertNull($feed->items[0]->media);
        $this->assertNull($feed->items[0]->thumbnail);
    }

    public function testParseWithoutItems(): void
    {
        $feed = (new FeedParser(0))->parse(
            '<rss version="2.0"><channel>'
                . '<item><link>https://www.example.com/a.php</link></item>'
                . '</channel></rss>',
            self::URL,
        );

        $this->assertSame([], $feed->items);
    }

    public function testParseLimitsItems(): void
    {
        $items = str_repeat(
            '<item><link>https://www.example.com/a.php</link></item>',
            5,
        );

        $feed = (new FeedParser(3))->parse(
            '<rss version="2.0"><channel>' . $items . '</channel></rss>',
            self::URL,
        );

        $this->assertCount(3, $feed->items);
    }

    public function testParseResolvesRelativeRssUrls(): void
    {
        $xml = '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"'
            . ' xmlns:media="http://search.yahoo.com/mrss/"><channel>'
            . '<link>/</link>'
            . '<atom:link rel="self" href="news.xml"/>'
            . '<image><url>../logo.png</url><link>/presse/</link></image>'
            . '<item>'
            . '<link>a.php</link>'
            . '<enclosure url="/img/a.jpg" type="image/jpeg"/>'
            . '<media:thumbnail url="t.jpg"/>'
            . '</item></channel></rss>';

        $feed = $this->parser->parse($xml, 'https://feeds.example.com/rss/news.xml');

        $this->assertSame('https://feeds.example.com/', $feed->channel->link);
        $this->assertSame(
            'https://feeds.example.com/rss/news.xml',
            $feed->channel->selfLink,
        );
        $this->assertSame(
            'https://feeds.example.com/logo.png',
            $feed->channel->image?->url,
        );
        $this->assertSame(
            'https://feeds.example.com/presse/',
            $feed->channel->image?->link,
        );
        $this->assertSame(
            'https://feeds.example.com/rss/a.php',
            $feed->items[0]->link,
        );
        $this->assertSame(
            'https://feeds.example.com/img/a.jpg',
            $feed->items[0]->media?->url,
        );
        $this->assertSame(
            'https://feeds.example.com/rss/t.jpg',
            $feed->items[0]->thumbnail?->url,
        );
    }

    public function testParseResolvesAtomUrlsAgainstXmlBase(): void
    {
        $xml = '<feed xmlns="http://www.w3.org/2005/Atom" xml:base="https://ex.org/blog/">'
            . '<link href="./"/>'
            . '<logo>logo.png</logo>'
            . '<entry xml:base="2026/">'
            . '<id>urn:uuid:1</id>'
            . '<link href="post-1"/>'
            . '<link rel="enclosure" href="/img/a.jpg" type="image/jpeg"/>'
            . '</entry></feed>';

        $feed = $this->parser->parse($xml, self::URL);

        $this->assertSame('https://ex.org/blog/', $feed->channel->link);
        $this->assertSame(
            'https://ex.org/blog/logo.png',
            $feed->channel->image?->url,
        );
        $this->assertSame(
            'https://ex.org/blog/2026/post-1',
            $feed->items[0]->link,
            'nested xml:base should be applied',
        );
        $this->assertSame(
            'https://ex.org/img/a.jpg',
            $feed->items[0]->media?->url,
        );
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function dates(): array
    {
        return [
            'rfc 822' => ['Sun, 01 Mar 2026 12:00:00 +0100', '2026-03-01T12:00:00+01:00'],
            'zone name' => ['Sun, 01 Mar 2026 11:00:00 GMT', '2026-03-01T11:00:00+00:00'],
            'wrong weekday' => ['Mon, 01 Mar 2026 12:00:00 +0100', '2026-03-01T12:00:00+01:00'],
            'without weekday and seconds' => ['1 Mar 2026 12:00 +0100', '2026-03-01T12:00:00+01:00'],
            'rfc 3339' => ['2026-03-01T12:00:00+01:00', '2026-03-01T12:00:00+01:00'],
            'rfc 3339 with fraction' => ['2026-03-01T11:00:00.123Z', '2026-03-01T11:00:00+00:00'],
            'other absolute date' => ['2026-03-01 12:00:00 +0100', '2026-03-01T12:00:00+01:00'],
            'relative' => ['tomorrow', null],
            'weekday' => ['Monday', null],
            'time only' => ['12:00', null],
            'garbage' => ['gestern', null],
            'invalid rfc 822' => ['Tue, 31 Feb 2026 12:00:00 +0100', null],
            'invalid other' => ['2026-02-31', null],
        ];
    }

    #[DataProvider('dates')]
    public function testParseDate(string $value, ?string $expected): void
    {
        $feed = $this->parseRssItems(
            '<item><guid isPermaLink="false">a-1</guid>'
                . '<pubDate>' . $value . '</pubDate></item>',
        );

        $this->assertSame(
            $expected,
            $feed->items[0]->pubDate?->format(\DateTimeInterface::RFC3339),
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidDocuments(): array
    {
        return [
            'empty' => [' ', 'empty document'],
            'malformed' => ['<rss>', 'not a well-formed XML document'],
            'html' => ['<html/>', 'unsupported feed format, root element is <html>'],
            'rss 1.0' => [
                '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"/>',
                'unsupported feed format, root element is <rdf:RDF>',
            ],
            'feed without atom namespace' => [
                '<feed/>',
                'unsupported feed format, root element is <feed>',
            ],
            'rss without channel' => ['<rss/>', 'RSS document without <channel>'],
        ];
    }

    #[DataProvider('invalidDocuments')]
    public function testParseInvalidDocument(string $xml, string $message): void
    {
        $this->expectException(FeedParseException::class);
        $this->expectExceptionMessage($message);

        $this->parser->parse($xml, self::URL);
    }

    public function testParseDoesNotResolveExternalEntities(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'atoolo-rss');
        $this->assertIsString($file);
        file_put_contents($file, 'geheim');
        try {
            $xml = '<?xml version="1.0"?>'
                . '<!DOCTYPE rss [<!ENTITY secret SYSTEM "file://' . $file . '">]>'
                . '<rss version="2.0"><channel><title>&secret;</title></channel></rss>';

            $title = $this->parser->parse($xml, self::URL)->channel->title;
        } finally {
            unlink($file);
        }

        $this->assertStringNotContainsString('geheim', $title);
    }

    private function parseRssItems(string $items): Feed
    {
        return $this->parser->parse(
            '<rss version="2.0"><channel>' . $items . '</channel></rss>',
            self::URL,
        );
    }
}
