<?php

declare(strict_types=1);

namespace Atoolo\Rss\Test\Service;

use Atoolo\Rss\Dto\Category;
use Atoolo\Rss\Dto\Channel;
use Atoolo\Rss\Dto\Feed;
use Atoolo\Rss\Dto\Image;
use Atoolo\Rss\Dto\Item;
use Atoolo\Rss\Dto\Media;
use Atoolo\Rss\Service\FeedWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FeedWriter::class)]
class FeedWriterTest extends TestCase
{
    private FeedWriter $writer;

    public function setUp(): void
    {
        $this->writer = new FeedWriter();
    }

    public function testWriteFullFeed(): void
    {
        $feed = new Feed(
            new Channel(
                title: 'Presse & <Aktuelles>',
                link: 'https://www.example.com/presse.php',
                selfLink: 'https://www.example.com/api/rss/search?query=%7B%7D',
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
                    title: 'Rat beschließt "Haushalt"',
                    link: 'https://www.example.com/a.php?x=1&y=2',
                    guid: 'https://www.example.com/a.php?x=1&y=2',
                    description: 'Text mit <b>Markup</b>',
                    pubDate: $this->date('2026-03-01 12:00:00', '+0100'),
                    media: new Media(
                        'https://www.example.com/i.jpg',
                        'image/jpeg',
                        12345,
                    ),
                    categories: [
                        new Category('Rat', 'https://www.example.com/kategorien/'),
                        new Category('Stadt'),
                    ],
                ),
                new Item(
                    title: 'Zweiter',
                    link: 'https://www.example.com/b.php',
                    guid: 'https://www.example.com/b.php',
                    pubDate: $this->date('2026-04-02 08:30:00', '+0200'),
                ),
            ],
        );

        $expected = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:media="http://search.yahoo.com/mrss/">
         <channel>
          <title>Presse &amp; &lt;Aktuelles&gt;</title>
          <link>https://www.example.com/presse.php</link>
          <description>Meldungen</description>
          <language>de</language>
          <copyright>Copyright 2026</copyright>
          <generator>IES</generator>
          <atom:link href="https://www.example.com/api/rss/search?query=%7B%7D" rel="self" type="application/rss+xml"/>
          <lastBuildDate>Thu, 02 Apr 2026 08:30:00 +0200</lastBuildDate>
          <image>
           <url>https://www.example.com/logo.png</url>
           <title>Presse</title>
           <link>https://www.example.com/presse.php</link>
          </image>
          <item>
           <title>Rat beschließt &quot;Haushalt&quot;</title>
           <link>https://www.example.com/a.php?x=1&amp;y=2</link>
           <guid isPermaLink="true">https://www.example.com/a.php?x=1&amp;y=2</guid>
           <description>Text mit &lt;b&gt;Markup&lt;/b&gt;</description>
           <pubDate>Sun, 01 Mar 2026 12:00:00 +0100</pubDate>
           <media:content url="https://www.example.com/i.jpg" type="image/jpeg" fileSize="12345"/>
           <category domain="https://www.example.com/kategorien/">Rat</category>
           <category>Stadt</category>
          </item>
          <item>
           <title>Zweiter</title>
           <link>https://www.example.com/b.php</link>
           <guid isPermaLink="true">https://www.example.com/b.php</guid>
           <pubDate>Thu, 02 Apr 2026 08:30:00 +0200</pubDate>
          </item>
         </channel>
        </rss>

        XML;

        $this->assertSame(
            $expected,
            $this->writer->write($feed),
            'feed should be serialised as RSS 2.0',
        );
    }

    /**
     * title, link and description are the three elements RSS 2.0 requires of a
     * channel, so an unset description must not drop the element.
     */
    public function testWriteEmptyDescriptionElement(): void
    {
        $xml = $this->writer->write(new Feed($this->minimalChannel()));

        $this->assertStringContainsString(
            '<description></description>',
            $xml,
            'description should be written even when null',
        );
    }

    public function testWriteOmitsUnsetChannelElements(): void
    {
        $xml = $this->writer->write(new Feed($this->minimalChannel()));

        $this->assertStringNotContainsString('<language>', $xml);
        $this->assertStringNotContainsString('<copyright>', $xml);
        $this->assertStringNotContainsString('<generator>', $xml);
        $this->assertStringNotContainsString('<image>', $xml);
    }

    public function testWriteOmitsLastBuildDateWithoutDatedItems(): void
    {
        $feed = new Feed($this->minimalChannel(), [
            new Item('A', 'https://www.example.com/a', 'https://www.example.com/a'),
        ]);

        $this->assertStringNotContainsString(
            '<lastBuildDate>',
            $this->writer->write($feed),
        );
    }

    public function testWriteOmitsPubDateWhenAbsent(): void
    {
        $feed = new Feed($this->minimalChannel(), [
            new Item('A', 'https://www.example.com/a', 'https://www.example.com/a'),
        ]);

        $this->assertStringNotContainsString(
            '<pubDate>',
            $this->writer->write($feed),
        );
    }

    public function testWriteMediaWithoutOptionalAttributes(): void
    {
        $feed = new Feed($this->minimalChannel(), [
            new Item(
                title: 'A',
                link: 'https://www.example.com/a',
                guid: 'https://www.example.com/a',
                media: new Media('https://www.example.com/i.jpg'),
            ),
        ]);

        $this->assertStringContainsString(
            '<media:content url="https://www.example.com/i.jpg"/>',
            $this->writer->write($feed),
            'type and fileSize are optional in MRSS',
        );
    }

    public function testWriteResolvesPrefixedNamespaces(): void
    {
        $feed = new Feed($this->minimalChannel(), [
            new Item(
                title: 'A',
                link: 'https://www.example.com/a',
                guid: 'https://www.example.com/a',
                media: new Media('https://www.example.com/i.jpg'),
            ),
        ]);

        $dom = new \DOMDocument();
        $dom->loadXML($this->writer->write($feed));

        $this->assertCount(
            1,
            $dom->getElementsByTagNameNS('http://search.yahoo.com/mrss/', 'content'),
            'media:content should resolve against the MRSS namespace',
        );
        $this->assertCount(
            1,
            $dom->getElementsByTagNameNS('http://www.w3.org/2005/Atom', 'link'),
            'atom:link should resolve against the Atom namespace',
        );
    }

    private function minimalChannel(): Channel
    {
        return new Channel(
            title: 'T',
            link: 'https://www.example.com/',
            selfLink: 'https://www.example.com/feed',
        );
    }

    private function date(string $date, string $timeZone): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone($timeZone));
    }
}
