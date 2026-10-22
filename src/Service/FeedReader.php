<?php

declare(strict_types=1);

namespace Atoolo\Rss\Service;

use Atoolo\Rss\Dto\Feed;
use Atoolo\Rss\Exception\FeedParseException;
use Atoolo\Rss\Exception\FeedReadException;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Loads an RSS or Atom feed from a url.
 *
 * A loaded feed is fresh for `cacheTtl` seconds. After that it is reloaded;
 * if that fails, the last loaded feed is returned for up to `staleTtl`
 * seconds after it was loaded (stale-on-error). After a failure, the url is
 * not requested again for `retryDelay` seconds, so an unavailable source
 * does not slow down every read.
 */
class FeedReader
{
    private const ACCEPT = 'application/rss+xml, application/atom+xml, '
        . 'application/xml;q=0.9, text/xml;q=0.9, */*;q=0.1';

    /**
     * Part of the cache key. Increase it whenever the cached classes
     * (`CachedFeed` and the DTOs) change, so that persistent pools do not
     * return entries in the old format.
     */
    private const CACHE_FORMAT = 1;

    private readonly ClockInterface $clock;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly FeedParser $parser,
        private readonly CacheItemPoolInterface $cache,
        /** seconds a feed is fresh, 0 disables caching */
        private readonly int $cacheTtl,
        /** bytes a feed may have; larger ones are aborted while loading */
        private readonly int $maxSize,
        /** seconds after loading a feed may be returned if reloading fails */
        private readonly int $staleTtl = 86400,
        /** seconds a url is not requested again after a failure */
        private readonly int $retryDelay = 60,
        ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->clock = $clock ?? new NativeClock();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @throws FeedReadException
     */
    public function read(string $url): Feed
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new FeedReadException(
                "unable to read feed '$url': only http(s) urls are supported",
            );
        }

        if ($this->cacheTtl <= 0) {
            return $this->load($url);
        }

        $item = $this->cache->getItem(
            'feed.v' . self::CACHE_FORMAT . '.' . hash('xxh128', $url),
        );
        $cached = $item->isHit() ? $item->get() : null;
        if (!$cached instanceof CachedFeed) {
            $cached = null;
        }
        $now = $this->clock->now()->getTimestamp();

        if (
            $cached?->feed !== null
            && $cached->loadedAt !== null
            && $now - $cached->loadedAt < $this->cacheTtl
        ) {
            return $cached->feed;
        }

        if (
            $cached?->failedAt !== null
            && $now - $cached->failedAt < $this->retryDelay
        ) {
            return $cached->feed ?? throw new FeedReadException(
                ($cached->error ?? "unable to read feed '$url'")
                    . ' (failed recently, not retried yet)',
            );
        }

        try {
            $feed = $this->load($url);
        } catch (FeedReadException $e) {
            return $this->fail($item, $cached, $e, $now);
        }

        $item->set(new CachedFeed($feed, $now));
        $item->expiresAfter(max($this->staleTtl, $this->cacheTtl));
        $this->cache->save($item);
        return $feed;
    }

    /**
     * Remembers the failure and returns the stale feed, if there is one.
     *
     * @throws FeedReadException if there is no stale feed
     */
    private function fail(
        CacheItemInterface $item,
        ?CachedFeed $cached,
        FeedReadException $exception,
        int $now,
    ): Feed {
        $stale = $cached?->feed;
        $item->set(new CachedFeed(
            $stale,
            $cached?->loadedAt,
            $now,
            $exception->getMessage(),
        ));
        if ($stale !== null && $cached->loadedAt !== null) {
            // the stale feed must not live longer than it would have anyway
            $item->expiresAfter(max(
                $this->retryDelay,
                $cached->loadedAt + $this->staleTtl - $now,
            ));
        } else {
            $item->expiresAfter($this->retryDelay);
        }
        $this->cache->save($item);

        if ($stale === null) {
            throw $exception;
        }
        $this->logger->warning(
            'returning stale feed, reloading failed: '
                . $exception->getMessage(),
            ['exception' => $exception],
        );
        return $stale;
    }

    /**
     * @throws FeedReadException
     */
    private function load(string $url): Feed
    {
        try {
            $content = $this->httpClient->request('GET', $url, [
                'headers' => ['Accept' => self::ACCEPT],
                'on_progress' => function (int $loaded, int $size): void {
                    if ($loaded > $this->maxSize || $size > $this->maxSize) {
                        throw new FeedReadException(
                            "feed exceeds the maximum size of $this->maxSize bytes",
                        );
                    }
                },
            ])->getContent();
        } catch (ExceptionInterface $e) {
            throw new FeedReadException(
                "unable to load feed '$url': " . $e->getMessage(),
                0,
                $e,
            );
        }

        try {
            return $this->parser->parse($content, $url);
        } catch (FeedParseException $e) {
            throw new FeedReadException(
                "unable to parse feed '$url': " . $e->getMessage(),
                0,
                $e,
            );
        }
    }
}
