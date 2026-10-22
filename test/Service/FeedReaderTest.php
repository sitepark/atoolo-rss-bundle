<?php

declare(strict_types=1);

namespace Atoolo\Rss\Test\Service;

use Atoolo\Rss\Exception\FeedReadException;
use Atoolo\Rss\Service\CachedFeed;
use Atoolo\Rss\Service\FeedParser;
use Atoolo\Rss\Service\FeedReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(FeedReader::class)]
#[CoversClass(CachedFeed::class)]
class FeedReaderTest extends TestCase
{
    private const URL = 'https://feeds.example.com/news.xml';

    private MockClock $clock;

    /** @var list<string> */
    private array $warnings = [];

    public function setUp(): void
    {
        $this->clock = new MockClock('2026-10-22 12:00:00');
        $this->warnings = [];
    }

    public function testRead(): void
    {
        $reader = $this->createReader([new MockResponse($this->feed('News'))]);

        $feed = $reader->read(self::URL);

        $this->assertSame('News', $feed->channel->title);
        $this->assertSame(self::URL, $feed->channel->link);
    }

    public function testReadRequestsFeedFormats(): void
    {
        $response = new MockResponse($this->feed('News'));
        $reader = $this->createReader([$response]);

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
        $client = new MockHttpClient([new MockResponse($this->feed('News'))]);
        $reader = $this->createReader($client);

        $first = $reader->read(self::URL);
        $this->clock->sleep(899);
        $second = $reader->read(self::URL);

        $this->assertSame(1, $client->getRequestsCount());
        $this->assertEquals($first, $second);
    }

    public function testReadReloadsAfterCacheTtl(): void
    {
        $client = new MockHttpClient([
            new MockResponse($this->feed('Alt')),
            new MockResponse($this->feed('Neu')),
        ]);
        $reader = $this->createReader($client);

        $reader->read(self::URL);
        $this->clock->sleep(900);

        $this->assertSame('Neu', $reader->read(self::URL)->channel->title);
        $this->assertSame(2, $client->getRequestsCount());
    }

    public function testReadWithoutCache(): void
    {
        $client = new MockHttpClient([
            new MockResponse($this->feed('News')),
            new MockResponse($this->feed('News')),
        ]);
        $reader = $this->createReader($client, cacheTtl: 0);

        $reader->read(self::URL);
        $reader->read(self::URL);

        $this->assertSame(2, $client->getRequestsCount());
    }

    public function testReadReturnsStaleFeedIfReloadingFails(): void
    {
        $reader = $this->createReader([
            new MockResponse($this->feed('Alt')),
            new MockResponse('', ['http_code' => 503]),
        ]);

        $reader->read(self::URL);
        $this->clock->sleep(900);

        $this->assertSame('Alt', $reader->read(self::URL)->channel->title);
        $this->assertCount(1, $this->warnings);
        $this->assertStringStartsWith(
            'returning stale feed, reloading failed: unable to load feed',
            $this->warnings[0],
        );
    }

    public function testReadDoesNotRetryBeforeRetryDelay(): void
    {
        $client = new MockHttpClient([
            new MockResponse($this->feed('Alt')),
            new MockResponse('', ['http_code' => 503]),
            new MockResponse($this->feed('Neu')),
        ]);
        $reader = $this->createReader($client);

        $reader->read(self::URL);
        $this->clock->sleep(900);
        $reader->read(self::URL);
        $this->clock->sleep(59);

        $this->assertSame('Alt', $reader->read(self::URL)->channel->title);
        $this->assertSame(2, $client->getRequestsCount());

        $this->clock->sleep(1);

        $this->assertSame('Neu', $reader->read(self::URL)->channel->title);
        $this->assertSame(3, $client->getRequestsCount());
    }

    public function testReadStaleFeedExpires(): void
    {
        $reader = $this->createReader([
            new MockResponse($this->feed('Alt')),
            new MockResponse('', ['http_code' => 503]),
        ]);

        $reader->read(self::URL);
        $this->clock->sleep(86_400);

        $this->expectException(FeedReadException::class);
        $reader->read(self::URL);
    }

    public function testReadFailureWithoutStaleFeed(): void
    {
        $client = new MockHttpClient([
            new MockResponse('', ['http_code' => 503]),
            new MockResponse($this->feed('News')),
        ]);
        $reader = $this->createReader($client);

        try {
            $reader->read(self::URL);
            $this->fail('the first read should fail');
        } catch (FeedReadException $e) {
            $this->assertStringStartsWith(
                "unable to load feed '" . self::URL . "'",
                $e->getMessage(),
            );
        }

        try {
            $reader->read(self::URL);
            $this->fail('a read within the retry delay should fail');
        } catch (FeedReadException $e) {
            $this->assertStringEndsWith(
                '(failed recently, not retried yet)',
                $e->getMessage(),
            );
        }
        $this->assertSame(1, $client->getRequestsCount());

        $this->clock->sleep(60);

        $this->assertSame('News', $reader->read(self::URL)->channel->title);
    }

    public function testReadHttpError(): void
    {
        $reader = $this->createReader([new MockResponse('', ['http_code' => 404])]);

        $this->expectException(FeedReadException::class);
        $this->expectExceptionMessage("unable to load feed '" . self::URL . "'");

        $reader->read(self::URL);
    }

    public function testReadTransportError(): void
    {
        $reader = $this->createReader([new MockResponse('', ['error' => 'timeout'])]);

        $this->expectException(FeedReadException::class);
        $this->expectExceptionMessage("unable to load feed '" . self::URL . "'");

        $reader->read(self::URL);
    }

    public function testReadInvalidFeed(): void
    {
        $reader = $this->createReader([new MockResponse('<html/>')]);

        $this->expectException(FeedReadException::class);
        $this->expectExceptionMessage(
            "unable to parse feed '" . self::URL . "': unsupported feed format",
        );

        $reader->read(self::URL);
    }

    public function testReadAbortsOversizedFeed(): void
    {
        $chunks = (static function (): \Generator {
            for ($i = 0; $i < 10; $i++) {
                yield str_repeat(' ', 1_000);
            }
        })();
        $reader = $this->createReader([new MockResponse($chunks)]);

        $this->expectException(FeedReadException::class);
        $this->expectExceptionMessage(
            'feed exceeds the maximum size of 5000 bytes',
        );

        $reader->read(self::URL);
    }

    public function testReadAbortsOnAnnouncedOversize(): void
    {
        $reader = $this->createReader([
            new MockResponse($this->feed('News'), [
                'response_headers' => ['content-length' => '10000'],
            ]),
        ]);

        $this->expectException(FeedReadException::class);
        $this->expectExceptionMessage(
            'feed exceeds the maximum size of 5000 bytes',
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

    public function testReadIgnoresForeignCacheEntries(): void
    {
        $cache = new ArrayAdapter(clock: $this->clock);
        $item = $cache->getItem('feed.v1.' . hash('xxh128', self::URL));
        $cache->save($item->set('kein CachedFeed'));
        $reader = new FeedReader(
            new MockHttpClient([new MockResponse($this->feed('News'))]),
            new FeedParser(),
            $cache,
            900,
            5_000,
        );

        $this->assertSame('News', $reader->read(self::URL)->channel->title);
    }

    /**
     * @param MockHttpClient|list<MockResponse> $client
     */
    private function createReader(
        MockHttpClient|array $client,
        int $cacheTtl = 900,
    ): FeedReader {
        $logger = new class ($this->warnings) extends AbstractLogger {
            /** @param list<string> $warnings */
            public function __construct(private array &$warnings) {}

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if ($level === 'warning') {
                    $this->warnings[] = (string) $message;
                }
            }
        };

        return new FeedReader(
            $client instanceof MockHttpClient ? $client : new MockHttpClient($client),
            new FeedParser(),
            // serialises like a persistent pool and expires on the same clock
            new ArrayAdapter(clock: $this->clock),
            $cacheTtl,
            5_000,
            86_400,
            60,
            $this->clock,
            $logger,
        );
    }

    private function feed(string $title): string
    {
        return '<rss version="2.0"><channel><title>' . $title
            . '</title></channel></rss>';
    }
}
