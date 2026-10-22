<?php

declare(strict_types=1);

namespace Atoolo\Rss\Service;

/**
 * Resolves a url reference against a base url (RFC 3986, section 5.2).
 *
 * @internal
 */
class UrlResolver
{
    /** RFC 3986, appendix B */
    private const PATTERN = '~^(?:([^:/?#]+):)?(?://([^/?#]*))?([^?#]*)(?:\?([^#]*))?(?:#(.*))?$~s';

    public function resolve(string $base, string $reference): string
    {
        $ref = $this->split($reference);
        if ($ref['scheme'] !== null) {
            $ref['path'] = $this->removeDotSegments($ref['path']);
            return $this->join($ref);
        }

        $base = $this->split($base);
        if ($base['scheme'] === null) {
            // nothing to resolve against
            return $reference;
        }

        $target = [
            'scheme' => $base['scheme'],
            'authority' => $ref['authority'],
            'path' => $ref['path'],
            'query' => $ref['query'],
            'fragment' => $ref['fragment'],
        ];
        if ($ref['authority'] !== null) {
            $target['path'] = $this->removeDotSegments($ref['path']);
            return $this->join($target);
        }

        $target['authority'] = $base['authority'];
        if ($ref['path'] === '') {
            $target['path'] = $base['path'];
            $target['query'] = $ref['query'] ?? $base['query'];
        } elseif (str_starts_with($ref['path'], '/')) {
            $target['path'] = $this->removeDotSegments($ref['path']);
        } else {
            $target['path'] = $this->removeDotSegments(
                $this->merge($base, $ref['path']),
            );
        }
        return $this->join($target);
    }

    /**
     * @return array{
     *     scheme: ?string,
     *     authority: ?string,
     *     path: string,
     *     query: ?string,
     *     fragment: ?string,
     * }
     */
    private function split(string $url): array
    {
        // The pattern matches every string.
        preg_match(self::PATTERN, $url, $matches, PREG_UNMATCHED_AS_NULL);
        return [
            'scheme' => $matches[1] ?? null,
            'authority' => $matches[2] ?? null,
            'path' => $matches[3] ?? '',
            'query' => $matches[4] ?? null,
            'fragment' => $matches[5] ?? null,
        ];
    }

    /**
     * @param array{authority: ?string, path: string} $base
     */
    private function merge(array $base, string $path): string
    {
        if ($base['authority'] !== null && $base['path'] === '') {
            return '/' . $path;
        }
        $slash = strrpos($base['path'], '/');
        return $slash === false
            ? $path
            : substr($base['path'], 0, $slash + 1) . $path;
    }

    private function removeDotSegments(string $path): string
    {
        $output = '';
        while ($path !== '') {
            if (str_starts_with($path, '../')) {
                $path = substr($path, 3);
            } elseif (str_starts_with($path, './')) {
                $path = substr($path, 2);
            } elseif (str_starts_with($path, '/./')) {
                $path = substr($path, 2);
            } elseif ($path === '/.') {
                $path = '/';
            } elseif (str_starts_with($path, '/../') || $path === '/..') {
                $path = '/' . substr($path, 4);
                $slash = strrpos($output, '/');
                $output = $slash === false ? '' : substr($output, 0, $slash);
            } elseif ($path === '.' || $path === '..') {
                $path = '';
            } else {
                $next = strpos($path, '/', 1);
                $segment = $next === false ? $path : substr($path, 0, $next);
                $output .= $segment;
                $path = substr($path, strlen($segment));
            }
        }
        return $output;
    }

    /**
     * @param array{
     *     scheme: ?string,
     *     authority: ?string,
     *     path: string,
     *     query: ?string,
     *     fragment: ?string,
     * } $parts
     */
    private function join(array $parts): string
    {
        $url = '';
        if ($parts['scheme'] !== null) {
            $url .= $parts['scheme'] . ':';
        }
        if ($parts['authority'] !== null) {
            $url .= '//' . $parts['authority'];
        }
        $url .= $parts['path'];
        if ($parts['query'] !== null) {
            $url .= '?' . $parts['query'];
        }
        if ($parts['fragment'] !== null) {
            $url .= '#' . $parts['fragment'];
        }
        return $url;
    }
}
