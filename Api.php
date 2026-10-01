<?php

namespace Omnibus\Gls;

use Omnibus\Exception\CarrierException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * GLS's ShipIT web API (basic auth) for shipments and their labels, and GLS's
 * public tracking (gls-group.eu, no credentials) for where a parcel is.
 * Prices come from configuration: ShipIT quotes none.
 */
final class Api
{
    public const LIVE = 'https://shipit.gls-group.eu/backend/rs';
    public const TEST = 'https://shipit-wbm-test01.gls-group.eu:8443/backend/rs';
    public const TRACKING = 'https://gls-group.eu/app/service/open/rest/EU/en/rstt001';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $username,
        private readonly string $password,
        public readonly string $contactId,
        public readonly bool $sandbox = false,
        private readonly int $timeout = 20,
    ) {
    }

    /** @return array<string, mixed> */
    public function call(string $method, string $path, ?array $body = null, array $query = []): array
    {
        try {
            $response = $this->http->request($method, ($this->sandbox ? self::TEST : self::LIVE).$path, [
                'auth_basic' => [$this->username, $this->password],
                'headers' => ['Content-Type' => 'application/glsVersion1+json', 'Accept' => 'application/glsVersion1+json, application/json'],
                'query' => $query,
                'body' => null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR),
                'timeout' => $this->timeout,
            ]);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
            $headers = $response->getHeaders(false);
        } catch (HttpExceptionInterface|\JsonException $e) {
            throw new CarrierException('gls', 'GLS request failed: '.$e->getMessage(), null, $e);
        }
        $data = '' === $content ? [] : json_decode($content, true);
        if ($status >= 400) {
            throw new CarrierException('gls', (string) ($headers['message'][0] ?? $data['message'] ?? $data['errors'][0]['description'] ?? sprintf('HTTP %d', $status)), $headers['error'][0] ?? null);
        }
        if (!\is_array($data)) {
            throw new CarrierException('gls', 'GLS answered with a body that is not JSON.');
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function track(string $number, string $locale): array
    {
        try {
            $data = $this->http->request('GET', str_replace('/EU/en/', '/EU/'.(str_starts_with($locale, 'fr') ? 'fr' : (str_starts_with($locale, 'de') ? 'de' : 'en')).'/', self::TRACKING), ['query' => ['match' => $number], 'timeout' => $this->timeout])->toArray(false);
        } catch (HttpExceptionInterface $e) {
            throw new CarrierException('gls', 'GLS tracking failed: '.$e->getMessage(), null, $e);
        }

        return $data;
    }
}
