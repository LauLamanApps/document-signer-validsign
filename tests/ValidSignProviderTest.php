<?php

declare(strict_types=1);

namespace LauLamanApps\DocumentSigner\ValidSign\Tests;

use LauLamanApps\DocumentSigner\Sdk\Document\Document;
use LauLamanApps\DocumentSigner\Sdk\Envelope\Envelope;
use LauLamanApps\DocumentSigner\Sdk\Envelope\EnvelopeStatus;
use LauLamanApps\DocumentSigner\Sdk\Exception\ProviderException;
use LauLamanApps\DocumentSigner\Sdk\Pdf\PdfRenderer;
use LauLamanApps\DocumentSigner\Sdk\Signer\Signer;
use LauLamanApps\DocumentSigner\Sdk\Signer\SigningOrder;
use LauLamanApps\DocumentSigner\ValidSign\Http\ValidSignClient;
use LauLamanApps\DocumentSigner\ValidSign\ValidSignConfig;
use LauLamanApps\DocumentSigner\ValidSign\ValidSignProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class ValidSignProviderTest extends TestCase
{
    #[Test]
    public function send_uploads_pdf_and_returns_receipt_with_provider_name(): void
    {
        $envelope = $this->envelopeWithOneSigner();

        [$provider, $history] = $this->buildProvider([
            new Response(200, [], json_encode(['id' => 'pkg-123'])),
        ]);

        $receipt = $provider->send($envelope);

        self::assertSame(ValidSignProvider::NAME, $receipt->provider);
        self::assertSame('validsign', $receipt->provider);
        self::assertSame('pkg-123', $receipt->providerEnvelopeId);
        self::assertSame(EnvelopeStatus::Sent, $receipt->status);

        self::assertCount(1, $history);
        /** @var RequestInterface $request */
        $request = $history[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertStringContainsString('packages', (string) $request->getUri());

        $body = (string) $request->getBody();
        self::assertStringContainsString('name="payload"', $body);
        self::assertStringContainsString('name="file"; filename="NDA.pdf"', $body);
        self::assertStringContainsString('%PDF-FAKE', $body, 'rendered PDF bytes are uploaded');

        $payload = $this->extractPayload($body);
        self::assertSame('PACKAGE', $payload['type']);
        self::assertSame('SENT', $payload['status']);
        self::assertSame('Mutual NDA', $payload['name']);
        self::assertSame('s1', $payload['roles'][0]['id']);
        self::assertSame('Jane', $payload['roles'][0]['signers'][0]['firstName']);
        self::assertSame('Doe', $payload['roles'][0]['signers'][0]['lastName']);
        self::assertTrue($payload['documents'][0]['extract']);
        self::assertSame(
            '[[VS:signature:s1:sig]]',
            $payload['documents'][0]['approvals'][0]['fields'][0]['extractAnchor']['text'],
        );
    }

    #[Test]
    public function send_throws_when_response_lacks_package_id(): void
    {
        [$provider] = $this->buildProvider([
            new Response(200, [], json_encode(['unexpected' => true])),
        ]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('did not return a package id');

        $provider->send($this->envelopeWithOneSigner());
    }

    #[Test]
    public function send_rejects_placeholder_referencing_unknown_signer(): void
    {
        $envelope = new Envelope(
            name:         'env',
            documents:    [new Document('d', 'D', '<p>{[signature:ghost:sig]}</p>')],
            signers:      [new Signer('real', 'Jane Doe', 'jane@example.com')],
            emailSubject: 'subj',
            signingOrder: SigningOrder::Parallel,
        );

        [$provider] = $this->buildProvider([]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage("unknown signer key 'ghost'");

        $provider->send($envelope);
    }

    #[Test]
    public function get_status_maps_provider_status_strings(): void
    {
        [$provider] = $this->buildProvider([
            new Response(200, [], json_encode(['status' => 'COMPLETED'])),
            new Response(200, [], json_encode(['status' => 'DRAFT'])),
            new Response(200, [], json_encode(['status' => 'EXPIRED'])),
            new Response(200, [], json_encode(['status' => 'whatever'])),
        ]);

        self::assertSame(EnvelopeStatus::Completed, $provider->getStatus('p1'));
        self::assertSame(EnvelopeStatus::Draft,     $provider->getStatus('p2'));
        self::assertSame(EnvelopeStatus::Expired,   $provider->getStatus('p3'));
        self::assertSame(EnvelopeStatus::Unknown,   $provider->getStatus('p4'));
    }

    /**
     * @return array{0: ValidSignProvider, 1: \ArrayObject<int, array<string, mixed>>}
     */
    private function buildProvider(array $responses): array
    {
        $mock = new MockHandler($responses);
        $history = new \ArrayObject();
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $http = new Client(['handler' => $stack]);

        $config = new ValidSignConfig(apiKey: 'k', baseUrl: 'https://my.validsign.nl/api');
        $client = new ValidSignClient($config, $http);

        $provider = new ValidSignProvider(
            $config,
            client: $client,
            pdfRenderer: $this->fakePdfRenderer(),
        );

        return [$provider, $history];
    }

    private function envelopeWithOneSigner(): Envelope
    {
        return new Envelope(
            name:         'Mutual NDA',
            documents:    [new Document(
                id:   'nda',
                name: 'NDA',
                html: '<p>Signed: {[signature:s1:sig]}</p>',
            )],
            signers:      [new Signer('s1', 'Jane Doe', 'jane@example.com')],
            emailSubject: 'Please sign the NDA',
            signingOrder: SigningOrder::Parallel,
        );
    }

    private function fakePdfRenderer(): PdfRenderer
    {
        return new class implements PdfRenderer {
            public function render(string $html): string
            {
                return '%PDF-FAKE' . $html;
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function extractPayload(string $multipartBody): array
    {
        if (!preg_match('/name="payload".*?\r\n\r\n(\{.*?\})\r\n--/s', $multipartBody, $m)) {
            self::fail('Could not extract JSON payload from multipart body.');
        }
        return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
    }
}
