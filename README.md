# atoolo-rss-bundle

Renders [atoolo](https://github.com/sitepark/atoolo-docs) search results as an
RSS 2.0 feed.

## API

```
GET /api/rss/search?query={query}&location={location}
```

| Parameter | Required | Description |
|---|---|---|
| `query` | yes | A JSON-serialised `Atoolo\Search\Dto\Search\Query\SearchQuery`. |
| `location` | no | The page the feed belongs to. Supplies the channel title, description and image — it is not one of the results. |

Both are query parameters: `location` contains slashes, and the iCal API moved
its own query off a path parameter for the same reason. The feed language comes
from the search query rather than from a `{lang}` path segment.

Without a `location` the channel falls back to the site's own metadata — the
resource channel's name and root url, plus the configured description. A
`location` that *is* given but does not resolve stays a `404`, so a typo does
not quietly turn into a generic feed. A `location` containing a `..` segment is
rejected with a `400`: the resource loader concatenates it onto the resource
directory and `require`s the result, so it must not be able to leave it.

Channel metadata deliberately comes from a resource rather than from
parameters. Values in a url would freeze at the moment the link was copied, and
a feed url lives in a reader for years; they would also let a caller put
arbitrary text in front of a reader under your domain.

```bash
curl "https://www.example.com/api/rss/search?location=/suche.php&query={\"filter\":[{\"type\":\"objectType\",\"values\":[\"news\"]}]}"
```

Errors are returned as `application/problem+json` by
`phpro/api-problem-bundle`: `400` for a missing, unparseable or over-large
query, `404` when the location does not resolve, `500` when the search backend
fails.

> **Note**
> The endpoint accepts a fully expressive `SearchQuery` from the client, exactly
> as `/api/ical/search` does. Several `SearchQuery` fields reach Solr unescaped,
> so this should not be exposed to untrusted callers until both endpoints move
> to a signed or server-registered query.

## Configuration

```yaml
parameters:
  atoolo_rss.channel.generator: 'Information Enterprise Server – Sitepark GmbH'
  atoolo_rss.channel.copyright: 'Copyright 2026, Landeshauptstadt Musterstadt'
  # used when the feed has no location, or the page carries no description
  atoolo_rss.channel.description: 'Aktuelles aus Musterstadt'
  # upper bound for the `limit` of an incoming query (default 100)
  atoolo_rss.max_items: 100
```

A query whose `limit` exceeds `atoolo_rss.max_items` is rejected with a `400`
naming the maximum, rather than silently shortened: the only place it can be
fixed is where the feed url is built, and a truncated feed is hard to notice
once it sits in a reader. A query that omits `limit` keeps `SearchQuery`'s own
default of 10.

Note that lowering this value invalidates feed urls already in use that ask for
more.

## Extension points

**`ItemFactory`** — decides what a feed item says. Alias it to change the
mapping without touching the bundle:

```yaml
Atoolo\Rss\Service\ItemFactory:
  alias: App\Rss\MyItemFactory
```

**`ChannelFactory`** — the same for the feed-level metadata: title,
description, language, image. The default reads them off the resource named by
`location`, falling back to the site's own metadata.

```yaml
Atoolo\Rss\Service\ChannelFactory:
  alias: App\Rss\MyChannelFactory
```

**`MediaFileLocator`** — resolves a media url to a file on disk, used only to
fill the optional `type` and `fileSize` attributes of `media:content`. The
bundle ships a no-op, since where media lives depends on how the channel
publishes. Supply an implementation to get those attributes:

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
| `Service\FeedWriter` | `Feed` → XML; the only class that knows about RSS syntax |

All urls go through `atoolo/rewrite-bundle`'s `UrlRewriter`, fully qualified, so
feed links match the ones the site renders.
