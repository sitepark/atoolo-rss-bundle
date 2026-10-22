# atoolo-rss-bundle

Renders [atoolo](https://github.com/sitepark/atoolo-docs) search results as an
RSS 2.0 feed, and reads RSS and Atom feeds from other sites.

## API

```
GET /api/rss/search?query={query}&location={location}
```

| Parameter | Required | Description |
|---|---|---|
| `query` | yes | A JSON-serialised `Atoolo\Search\Dto\Search\Query\SearchQuery`. The feed language comes from it. |
| `location` | no | The page the feed belongs to. Supplies the channel title, description and image — it is not one of the results. |

Without a `location` the channel falls back to the site's own metadata: the
resource channel's name and root url, plus the configured description.

```bash
curl "https://www.example.com/api/rss/search?location=/suche.php&query={\"filter\":[{\"type\":\"objectType\",\"values\":[\"news\"]}]}"
```

Errors are returned as `application/problem+json` by
`phpro/api-problem-bundle`:

| Status | Cause |
|---|---|
| `400` | `query` missing, unparseable, or asking for more than `atoolo_rss.max_items` |
| `400` | `location` containing a `..` segment |
| `404` | `location` does not resolve to a resource |
| `500` | the search backend failed |

> **Note**
> The endpoint accepts a fully expressive `SearchQuery` from the caller, and
> several of its fields reach Solr unescaped. Do not expose it to untrusted
> callers.

## Configuration

```yaml
parameters:
  atoolo_rss.channel.generator: 'Information Enterprise Server – Sitepark GmbH'
  atoolo_rss.channel.copyright: 'Copyright 2026, Landeshauptstadt Musterstadt'
  atoolo_rss.channel.description: 'Aktuelles aus Musterstadt'
  atoolo_rss.max_items: 100
```

The three `channel.*` values become the `<generator>`, `<copyright>` and
`<description>` elements; `null` omits them.

A query that omits `limit` keeps `SearchQuery`'s own default of 10. Lowering
`atoolo_rss.max_items` invalidates feed urls already in use that ask for more.

## Reading feeds

```php
$feed = $feedReader->read('https://www.example.com/feed.xml');
```

`Service\FeedReader` loads a feed and returns it as the same `Feed` the writer
takes. It understands RSS 2.0 and Atom 1.0, including Media RSS
(`media:content`, `media:group`, `media:thumbnail`), enclosures,
`content:encoded` and `dc:date`. A feed that cannot be loaded or parsed raises
a `FeedReadException`.

The result looks the same for both formats:

- **Titles are plain text, descriptions and contents are HTML.** Atom text
  is escaped to HTML for that. The HTML comes from a foreign site: sanitise
  it before output, e.g. with `symfony/html-sanitizer`, or strip it.
- **Urls are absolute**, resolved against `xml:base` and the feed's url.
  Urls inside the HTML are left as they are.
- **Gaps are filled in:** a missing `guid` from the link; a missing link
  from the `guid`, if that is an http url; a missing channel link from the
  feed's url. Items with neither link nor guid are skipped.
- **Of an item's media an image is preferred.**
- **Only absolute dates are read**; relative ones like `tomorrow` are
  ignored.

### Caching and failures

A loaded feed is fresh for `cache_ttl`. After that it is reloaded; if that
fails, the last loaded feed is returned for up to `stale_ttl` after it was
loaded, and a warning is logged. After a failure the url is not requested
again for `retry_delay`, so an unavailable source does not slow down every
page that shows it. Without a stale feed the failure is thrown.

```yaml
parameters:
  atoolo_rss.reader.cache_ttl: 900       # seconds, 0 disables caching
  atoolo_rss.reader.stale_ttl: 86400     # seconds
  atoolo_rss.reader.retry_delay: 60      # seconds
  atoolo_rss.reader.max_size: 5242880    # bytes, larger feeds are aborted
  atoolo_rss.reader.max_items: 500       # items read at most
  atoolo_rss.reader.http_client.options:
    timeout: 5
    max_duration: 10
    max_redirects: 3
    headers:
      User-Agent: 'atoolo-rss-bundle'
```

The cache is the pool `atoolo_rss.reader.cache`, derived from `cache.app`;
give it another adapter like any other pool. Its keys carry a format version
(`FeedReader::CACHE_FORMAT`), which has to be increased whenever the cached
DTOs change.

### Security

Feed urls usually come from editors. Only http(s) urls are accepted,
requests into the private network are refused (`NoPrivateNetworkHttpClient`;
the bundle requires the `symfony/http-client` and `symfony/http-foundation`
versions that fix CVE-2024-50342 and CVE-2026-48736), and feeds larger than
`max_size` are aborted while loading. External XML entities are never loaded.

## Extension points

**`ItemFactory`** — decides what a feed item says:

```yaml
Atoolo\Rss\Service\ItemFactory:
  alias: App\Rss\MyItemFactory
```

**`ChannelFactory`** — the same for the feed-level metadata: title,
description, language, image.

```yaml
Atoolo\Rss\Service\ChannelFactory:
  alias: App\Rss\MyChannelFactory
```

**`MediaFileLocator`** — resolves a media url to a file on disk, used only to
fill the optional `type` and `fileSize` attributes of `media:content`. The
bundle ships a no-op, since where media lives depends on how the site
publishes.

```yaml
Atoolo\Rss\Service\MediaFileLocator:
  alias: App\Rss\SiteKitMediaFileLocator
```

## Structure

| | |
|---|---|
| `Controller\RssController` | route, parameters, error mapping |
| `Service\FeedFactory` | search hits → `Feed` |
| `Service\DefaultChannelFactory` | the feed's resource → `Channel` |
| `Service\DefaultItemFactory` | one resource → `Item` |
| `Service\CategoryFactory` | a resource's categories, primary parent as `domain` |
| `Service\FeedWriter` | `Feed` → XML |
| `Service\FeedParser` | RSS / Atom XML → `Feed`; with the writer the only classes that know about feed syntax |
| `Service\FeedReader` | url → `Feed`, cached, stale on error |
| `Service\UrlResolver` | resolves relative urls (RFC 3986) |

All urls go through `atoolo/rewrite-bundle`'s `UrlRewriter`, fully qualified, so
feed links match the ones the site renders.
