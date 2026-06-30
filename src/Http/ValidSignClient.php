<?php

declare(strict_types=1);

namespace LauLamanApps\DocumentSigner\ValidSign\Http;

use LauLamanApps\DocumentSigner\Sdk\Exception\ProviderException;
use LauLamanApps\DocumentSigner\ValidSign\ValidSignConfig;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Utils;

final class ValidSignClient
{
    private ClientInterface $http;

    public function __construct(
        private readonly ValidSignConfig $config,
        ?ClientInterface $http = null,
    ) {
        $this->http = $http ?? new Client([
            'base_uri' => $this->config->trimmedBaseUrl() . '/',
            'timeout'  => $this->config->timeoutSeconds,
            'headers'  => [
                'Authorization' => 'Basic ' . $this->config->apiKey,
                'Accept'        => 'application/json',
            ],
        ]);
    }

    /**
     * Create a package with one or more PDF documents attached as multipart parts.
     *
     * @param array<string, mixed>           $payload     Package JSON payload (roles, documents, ...).
     * @param array<array{name:string, contents:string}> $files Each entry contributes one `file` multipart part.
     * @return array<string, mixed> Parsed JSON response.
     */
    public function createPackage(array $payload, array $files): array
    {
        $multipart = [
            [
                'name'     => 'payload',
                'contents' => json_encode($payload, JSON_THROW_ON_ERROR),
                'headers'  => ['Content-Type' => 'application/json'],
            ],
        ];

        foreach ($files as $file) {
            $multipart[] = [
                'name'     => 'file',
                'filename' => $file['name'],
                'contents' => Utils::streamFor($file['contents']),
                'headers'  => ['Content-Type' => 'application/pdf'],
            ];
        }

        return $this->request('POST', 'packages', [
            'multipart' => $multipart,
            'timeout'   => $this->config->uploadTimeoutSeconds,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getPackage(string $packageId): array
    {
        return $this->request('GET', 'packages/' . rawurlencode($packageId));
    }

    public function downloadSignedZip(string $packageId): string
    {
        return $this->requestRaw('GET', 'packages/' . rawurlencode($packageId) . '/documents/zip');
    }

    public function deletePackage(string $packageId): void
    {
        $this->request('DELETE', 'packages/' . rawurlencode($packageId));
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $options = []): array
    {
        $body = $this->requestRaw($method, $path, $options);
        if ($body === '') {
            return [];
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ProviderException(
                "ValidSign returned non-JSON response for {$method} {$path}.",
                providerBody: $body,
                previous: $e,
            );
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function requestRaw(string $method, string $path, array $options = []): string
    {
        try {
            $response = $this->http->request($method, $path, $options);
        } catch (RequestException $e) {
            $response = $e->getResponse();
            throw new ProviderException(
                "ValidSign {$method} {$path} failed: " . $e->getMessage(),
                httpStatus: $response?->getStatusCode(),
                providerBody: $response?->getBody()?->getContents(),
                previous: $e,
            );
        } catch (GuzzleException $e) {
            throw new ProviderException(
                "ValidSign {$method} {$path} failed: " . $e->getMessage(),
                previous: $e,
            );
        }

        return (string) $response->getBody();
    }
}
