<?php

namespace ErnestDefoe\Roster\Service\Http;

use GuzzleHttp\Client as HttpClient;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One GET to ESPN or Wikipedia, answered as decoded JSON or null.
 *
 * 🚨 Null for every failure — a non-200, a body that is not JSON, and a
 * network error (DNS, timeout, refused connection) alike. A thrown network
 * error used to end the whole hourly sync and leave every other league
 * unrefreshed until the next tick; the caller now loses one answer instead.
 */
class JsonFetcher
{
    /**
     * Wikipedia asks every client to identify itself with a way to reach its
     * author, and throttles anonymous ones; ESPN accepts the same.
     */
    private const USER_AGENT = 'FlarumRoster (+https://github.com/ernestdefoe/roster)';

    public function __construct(
        protected HttpClient $http,
        protected LoggerInterface $log,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>|null
     */
    public function get(string $url, array $query, int $timeout): ?array
    {
        try {
            $response = $this->http->get($url, [
                'query' => $query,
                'timeout' => $timeout,
                'connect_timeout' => 4,
                'http_errors' => false,
                'headers' => ['Accept' => 'application/json', 'User-Agent' => self::USER_AGENT],
            ]);
        } catch (Throwable $e) {
            $this->log->warning('[roster] request failed: '.$url.' '.$e->getMessage());

            return null;
        }

        $status = $response->getStatusCode();

        if ($status !== 200) {
            // A 404 is an ordinary answer (no such athlete, a roster ESPN could
            // not assemble); anything else, a block or an outage, is worth seeing.
            if ($status !== 404) {
                $this->log->warning('[roster] '.$url.' answered '.$status);
            }

            return null;
        }

        $data = json_decode((string) $response->getBody(), true);

        return is_array($data) ? $data : null;
    }

    /**
     * A link or image from a feed, only if it is plain http(s). These land in
     * href and src on the roster pages, so a feed that ever carried a
     * javascript: or data: address must not pass it through.
     */
    public static function web(mixed $url): ?string
    {
        return is_string($url) && preg_match('#^https?://[^\s"\'<>]+$#i', $url) ? $url : null;
    }
}
