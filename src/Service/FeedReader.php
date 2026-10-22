<?php

declare(strict_types=1);

namespace Atoolo\Rss\Service;

use Atoolo\Rss\Dto\Feed;
use Atoolo\Rss\Exception\FeedParseException;
use Atoolo\Rss\Exception\FeedReadException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Loads an RSS or Atom feed from a url. Feeds are cached for `cacheTtl`
 * seconds, failures are not.
 */
class FeedReader
{
    private const ACCEPT = 'application/rss+xml, application/atom+xml, '
        . 'application/xml;q=0.9, text/xml;q=0.9, */*;q=0.1';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly FeedParser $parser,
        private readonly CacheInterface $cache,
        /** seconds a feed is cached, 0 disables caching */
        private readonly int $cacheTtl,
    ) {}

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

        return $this->cache->get(
            'feed.' . hash('xxh128', $url),
            function (ItemInterface $item) use ($url): Feed {
                $item->expiresAfter($this->cacheTtl);
                return $this->load($url);
            },
        );
    }

    /**
     * @throws FeedReadException
     */
    private function load(string $url): Feed
    {
        try {
            $content = $this->httpClient->request('GET', $url, [
                'headers' => ['Accept' => self::ACCEPT],
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
