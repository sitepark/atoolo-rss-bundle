<?php

declare(strict_types=1);

namespace Atoolo\Rss\Controller;

use Atoolo\Resource\Exception\InvalidResourceException;
use Atoolo\Resource\Exception\ResourceNotFoundException;
use Atoolo\Resource\Resource;
use Atoolo\Resource\ResourceLanguage;
use Atoolo\Resource\ResourceLoader;
use Atoolo\Resource\ResourceLocation;
use Atoolo\Rss\Service\FeedFactory;
use Atoolo\Rss\Service\FeedWriter;
use Atoolo\Search\Dto\Search\Query\SearchQuery;
use Atoolo\Search\Exception\UnsupportedIndexLanguageException;
use Atoolo\Search\Search;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Renders search results as an RSS feed.
 */
class RssController extends AbstractController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const DEFAULT_MAX_ITEMS = 100;

    public function __construct(
        private readonly Search $search,
        private readonly SerializerInterface $serializer,
        private readonly ResourceLoader $loader,
        private readonly FeedFactory $feedFactory,
        private readonly FeedWriter $feedWriter,
        private readonly int $maxItems = self::DEFAULT_MAX_ITEMS,
    ) {}

    #[Route(
        '/api/rss/search',
        name: 'atoolo_rss_search',
        methods: ['GET'],
        // Not the response format - it makes errors render as RFC7807.
        format: 'json',
    )]
    public function rssBySearch(Request $request): Response
    {
        $query = $request->query->getString('query');
        if ($query === '') {
            throw new BadRequestHttpException(
                'query parameter \'query\' is empty',
            );
        }
        $searchQuery = $this->deserializeSearchQuery($query);
        $resources = $this->findResources($searchQuery);

        $location = $request->query->getString('location');
        $resource = $location === '' ? null : $this->loadResource(
            $this->toResourceLocation($location, $searchQuery->lang),
        );

        $feed = $this->feedFactory->create(
            $resource,
            $resources,
            $request->getUri(),
        );

        $response = new Response($this->feedWriter->write($feed));
        $response->headers->set(
            'Content-Type',
            'application/rss+xml; charset=utf-8',
        );
        return $response;
    }

    /**
     * @return list<Resource>
     */
    private function findResources(SearchQuery $searchQuery): array
    {
        try {
            return $this->search->search($searchQuery)->results;
        } catch (UnsupportedIndexLanguageException $e) {
            throw new BadRequestHttpException(
                'Language "' . $e->getLang()->code . '" for index "'
                    . $e->getIndex() . '" not supported',
                $e,
            );
        } catch (\Throwable $e) {
            $this->logger?->warning(
                'Something went wrong while executing the search query',
                ['searchQuery' => $searchQuery, 'exception' => $e],
            );
            throw new HttpException(
                500,
                'Something went wrong while processing the search query',
                $e,
            );
        }
    }

    private function toResourceLocation(
        string $location,
        ResourceLanguage $lang,
    ): ResourceLocation {
        // The loader concatenates the location onto the resource directory
        // and `require`s the result, so '..' must not reach it.
        if (in_array('..', explode('/', $location), true)) {
            throw new BadRequestHttpException(
                'query parameter \'location\' must not traverse directories',
            );
        }

        return ResourceLocation::ofPath(
            str_starts_with($location, '/') ? $location : '/' . $location,
            $lang,
        );
    }

    private function loadResource(ResourceLocation $location): Resource
    {
        try {
            return $this->loader->load($location);
        } catch (ResourceNotFoundException $e) {
            throw new NotFoundHttpException(
                'Resource at \'' . $location . '\' not found',
                $e,
            );
        } catch (InvalidResourceException $e) {
            throw new HttpException(
                500,
                'Resource at \'' . $location . '\' is invalid',
                $e,
            );
        }
    }

    private function deserializeSearchQuery(string $query): SearchQuery
    {
        try {
            // A JSON list passes the denormalizer's is_array() guard and
            // would yield a default query, so require an object.
            if (!json_decode($query, false, 512, JSON_THROW_ON_ERROR) instanceof \stdClass) {
                throw new \JsonException('search query is not an object');
            }
            $searchQuery = $this->serializer->deserialize(
                $query,
                SearchQuery::class,
                'json',
            );
        } catch (\Throwable $e) {
            $this->logger?->warning(
                'Something went wrong while trying to deserialize a search query.',
                ['query' => $query, 'exception' => $e],
            );
            // Not echoing the query back: it is caller-controlled.
            throw new BadRequestHttpException('Invalid search query', $e);
        }

        // Every item costs a Solr row and a resource load from disk.
        if ($searchQuery->limit > $this->maxItems) {
            throw new BadRequestHttpException(sprintf(
                'limit must not exceed %d items per feed',
                $this->maxItems,
            ));
        }

        return $searchQuery;
    }
}
