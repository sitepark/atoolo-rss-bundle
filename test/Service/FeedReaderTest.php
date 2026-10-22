<?php

declare(strict_types=1);

namespace Atoolo\Rss\Test\Service;

use Atoolo\Rss\Exception\FeedReadException;
use Atoolo\Rss\Service\FeedParser;
use Atoolo\Rss\Service\FeedReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(FeedReader::class)]
class FeedReaderTest extends TestCase
{
    private const URL = 'https://feeds.example.com/news.xml';

    private const FEED = '<rss version="2.0"><channel><title>News</title>'
        . '</channel></rss>';

    public function testRead(): void
    {
        $client = new MockHttpClient([new MockResponse(self::FEED)]);
        $reader = $this->createReader($client);

        $feed = $reader->read(self::URL);

        $this->assertSame('News', $feed->channel->title);
        $this->assertSame(self::URL, $feed->channel->link);
    }

    public function testReadRequestsFeedFormats(): void
    {
        $response = new MockResponse(self::FEED);
        $reader = $this->createReader(new MockHttpClient([$response]));

        $reader->read(self::URL);

        $this->assertSame('GET', $response->getRequestMethod());
        $this->assertSame(self::URL, $response->getRequestUrl());
        $this->assertContains(
            'Accept: application/rss+xml, application/atom+xml, '
                . 'application/xml;q=0.9, text/xml;q=0.9, */*;q=0.1',
            $response->getRequestOptions()['headers'],
        );
    }

    public function testReadIsCached(): void
    {
        $client = new MockHttpClient([new MockResponse(self::FEED)]);
        $reader = $this->createReader($client);

        $first = $reader->read(self::URL);
        $second = $reader->read(self::URL);

        $this->assertSame(1, $client->getRequestsCount());
        $this->assertEquals($first, $second);
    }

    public function testReadWithoutCache(): void
    {
        $client = new MockHttpClient([
            new MockResponse(self::FEED),
            new MockResponse(self::FEED),
        ]);
        $reader = $this->createReader($client, 0);

        $reader->read(self::URL);
        $reader->read(self::URL);

        $this->assertSame(2, $client->getRequestsCount());
    }

    public function testReadFailureIsNotCached(): void
    {
        $client = new MockHttpClient([
            new MockResponse('', ['http_code' => 503]),
            new MockResponse(self::FEED),
        ]);
        $reader = $this->createReader($client);

        try {
            $reader->read(self::URL);
            $this->fail('the first read should fail');
        } catch (FeedReadException) {
        }

        $this->assertSame('News', $reader->read(self::URL)->channel->title);
    }

    public function testReadHttpError(): void
    {
        $reader = $this->createReader(new MockHttpClient([
            new MockResponse('', ['http_code' => 404]),
        ]));

        $this->expectException(FeedReadException::class);
        $this->expectExceptionMessage("unable to load feed '" . self::URL . "'");

        $reader->read(self::URL);
    }

    public function testReadTransportError(): void
    {
        $reader = $this->createReader(new MockHttpClient([
            new MockResponse('', ['error' => 'timeout']),
        ]));

        $this->expectException(FeedReadException::class);
        $this->expectExceptionMessage("unable to load feed '" . self::URL . "'");

        $reader->read(self::URL);
    }

    public function testReadInvalidFeed(): void
    {
        $reader = $this->createReader(new MockHttpClient([
            new MockResponse('<html/>'),
        ]));

        $this->expectException(FeedReadException::class);
        $this->expectExceptionMessage(
            "unable to parse feed '" . self::URL . "': unsupported feed format",
        );

        $reader->read(self::URL);
    }

    public function testReadRejectsNonHttpUrl(): void
    {
        $client = new MockHttpClient();
        $reader = $this->createReader($client);

        try {
            $reader->read('file:///etc/passwd');
            $this->fail('a file url should be rejected');
        } catch (FeedReadException $e) {
            $this->assertStringContainsString(
                'only http(s) urls are supported',
                $e->getMessage(),
            );
        }
        $this->assertSame(0, $client->getRequestsCount());
    }

    private function createReader(
        MockHttpClient $client,
        int $cacheTtl = 900,
    ): FeedReader {
        return new FeedReader(
            $client,
            new FeedParser(),
            new ArrayAdapter(),
            $cacheTtl,
        );
    }
}
