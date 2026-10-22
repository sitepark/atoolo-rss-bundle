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
            . '<description>Nur Text</description>'
            . '</item></channel></rss>';

        $expected = new Feed(
            new Channel(title: '', link: self::URL, selfLink: self::URL),
            [new Item(title: '', link: '', guid: '', description: 'Nur Text')],
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
                . '<dc:date>2026-03-01T12:00:00+01:00</dc:date></item>',
        );

        $this->assertEquals(
            new \DateTimeImmutable('2026-03-01T12:00:00+01:00'),
            $feed->items[0]->pubDate,
        );
    }

    public function testParseInvalidDateIsIgnored(): void
    {
        $feed = $this->parseRssItems(
            '<item><pubDate>gestern</pubDate></item>',
        );

        $this->assertNull($feed->items[0]->pubDate);
    }

    public function testParseEnclosure(): void
    {
        $feed = $this->parseRssItems(
            '<item><enclosure url="https://www.example.com/i.png" '
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
            '<item xmlns:media="http://search.yahoo.com/mrss/">'
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
            '<item><enclosure url="https://www.example.com/a.mp3" '
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
            '<item xmlns:media="http://search.yahoo.com/mrss/"><media:group>'
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
            '<item xmlns:media="http://search.yahoo.com/mrss/">'
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
          <title type="html">Rat &lt;b&gt;beschließt&lt;/b&gt;</title>
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
                    title: 'Rat <b>beschließt</b>',
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
            . '<updated>2026-03-02T12:00:00Z</updated>'
            . '<content src="https://www.example.com/a.html"/>'
            . '</entry></feed>';

        $expected = new Feed(
            new Channel(title: '', link: self::URL, selfLink: self::URL),
            [
                new Item(
                    title: '',
                    link: '',
                    guid: '',
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
