<?php

declare(strict_types=1);

namespace LauLamanApps\DocumentSigner\ValidSign;

use LauLamanApps\DocumentSigner\Sdk\Document\Document;
use LauLamanApps\DocumentSigner\Sdk\Envelope\Envelope;
use LauLamanApps\DocumentSigner\Sdk\Envelope\EnvelopeStatus;
use LauLamanApps\DocumentSigner\Sdk\Exception\ProviderException;
use LauLamanApps\DocumentSigner\Sdk\Field\FieldType;
use LauLamanApps\DocumentSigner\Sdk\Pdf\BrowsershotPdfRenderer;
use LauLamanApps\DocumentSigner\Sdk\Pdf\PdfRenderer;
use LauLamanApps\DocumentSigner\Sdk\Placeholder\PlaceholderParser;
use LauLamanApps\DocumentSigner\Sdk\Placeholder\PreparedField;
use LauLamanApps\DocumentSigner\Sdk\Provider\EnvelopeReceipt;
use LauLamanApps\DocumentSigner\Sdk\Provider\SignatureProvider;
use LauLamanApps\DocumentSigner\Sdk\Signer\Signer;
use LauLamanApps\DocumentSigner\Sdk\Signer\SigningOrder;
use LauLamanApps\DocumentSigner\ValidSign\Http\ValidSignClient;
use LauLamanApps\DocumentSigner\ValidSign\Placeholder\ValidSignPlaceholderReplacer;

final class ValidSignProvider implements SignatureProvider
{
    public const string NAME = 'validsign';

    private readonly ValidSignConfig $config;
    private readonly ValidSignClient $client;
    private readonly PdfRenderer $pdfRenderer;
    private readonly ValidSignPlaceholderReplacer $replacer;
    private readonly PlaceholderParser $parser;

    public function __construct(
        ValidSignConfig $config,
        ?ValidSignClient $client = null,
        ?PdfRenderer $pdfRenderer = null,
        ?ValidSignPlaceholderReplacer $replacer = null,
        ?PlaceholderParser $parser = null,
    ) {
        $this->config      = $config;
        $this->client      = $client      ?? new ValidSignClient($config);
        $this->pdfRenderer = $pdfRenderer ?? new BrowsershotPdfRenderer();
        $this->replacer    = $replacer    ?? new ValidSignPlaceholderReplacer();
        $this->parser      = $parser      ?? new PlaceholderParser();
    }

    public function send(Envelope $envelope): EnvelopeReceipt
    {
        $files = [];
        $apiDocuments = [];
        $docIndex = 0;

        foreach ($envelope->documents as $document) {
            $prepared = $this->replacer->replace($document->html, $this->parser->parse($document->html));
            $this->assertFieldsResolvable($envelope, $document, $prepared->fields);

            $pdf = $this->pdfRenderer->render($prepared->html);
            $files[] = [
                'name'     => $this->fileName($document),
                'contents' => $pdf,
            ];

            $apiDocuments[] = [
                'id'        => $document->id,
                'name'      => $document->name,
                'index'     => $docIndex++,
                'extract'   => true,
                'approvals' => $this->buildApprovals($document->id, $prepared->fields),
            ];
        }

        $payload = [
            'name'         => $envelope->name,
            'type'         => 'PACKAGE',
            'status'       => 'SENT',
            'language'     => $this->config->defaultLanguage,
            'emailMessage' => $envelope->emailMessage ?? '',
            'description'  => $envelope->emailSubject,
            'due'          => $envelope->expiresAt?->format(\DateTimeInterface::ATOM),
            'roles'        => $this->buildRoles($envelope),
            'documents'    => $apiDocuments,
            'data'         => $envelope->metadata ?: new \stdClass(),
        ];

        $response = $this->client->createPackage($payload, $files);

        $packageId = $response['id'] ?? null;
        if (!is_string($packageId) || $packageId === '') {
            throw new ProviderException(
                'ValidSign did not return a package id in the create-package response.',
                providerBody: json_encode($response),
            );
        }

        return new EnvelopeReceipt(
            provider: self::NAME,
            providerEnvelopeId: $packageId,
            status: EnvelopeStatus::Sent,
            signerUrls: [],
            raw: $response,
        );
    }

    public function getStatus(string $providerEnvelopeId): EnvelopeStatus
    {
        $response = $this->client->getPackage($providerEnvelopeId);
        $status = is_string($response['status'] ?? null) ? strtoupper($response['status']) : '';

        return match ($status) {
            'DRAFT'                      => EnvelopeStatus::Draft,
            'SENT'                       => EnvelopeStatus::Sent,
            'COMPLETED', 'ARCHIVED'      => EnvelopeStatus::Completed,
            'DECLINED', 'OPTED_OUT'      => EnvelopeStatus::Declined,
            'EXPIRED'                    => EnvelopeStatus::Expired,
            default                      => EnvelopeStatus::Unknown,
        };
    }

    public function downloadSigned(string $providerEnvelopeId): string
    {
        return $this->client->downloadSignedZip($providerEnvelopeId);
    }

    public function cancel(string $providerEnvelopeId, ?string $reason = null): void
    {
        $this->client->deletePackage($providerEnvelopeId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildRoles(Envelope $envelope): array
    {
        $sequential = $envelope->signingOrder === SigningOrder::Sequential;
        $roles = [];

        foreach ($envelope->signers as $signer) {
            [$first, $last] = $this->splitName($signer->name);

            $roles[] = [
                'id'    => $signer->key,
                'name'  => $signer->name,
                'type'  => 'SIGNER',
                'index' => $sequential ? max(0, $signer->order - 1) : 0,
                'signers' => [[
                    'id'        => $signer->key,
                    'email'     => $signer->email,
                    'firstName' => $first,
                    'lastName'  => $last,
                    'language'  => $signer->language ?? $this->config->defaultLanguage,
                ]],
            ];
        }

        return $roles;
    }

    /**
     * @param PreparedField[] $fields
     * @return list<array<string, mixed>>
     */
    private function buildApprovals(string $documentId, array $fields): array
    {
        $byRole = [];
        foreach ($fields as $field) {
            $byRole[$field->signerKey][] = $field;
        }

        $approvals = [];
        $approvalIndex = 0;
        foreach ($byRole as $signerKey => $signerFields) {
            $approvals[] = [
                'id'     => sprintf('appr_%s_%s_%d', $documentId, $signerKey, $approvalIndex++),
                'role'   => $signerKey,
                'fields' => array_map(
                    fn (PreparedField $f) => $this->mapField($f),
                    $signerFields,
                ),
            ];
        }

        return $approvals;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapField(PreparedField $field): array
    {
        [$validSignType, $subtype, $extra] = match ($field->type) {
            FieldType::Signature => ['SIGNATURE', 'FULLNAME', []],
            FieldType::Initials  => ['SIGNATURE', 'INITIALS', []],
            FieldType::Text      => ['INPUT', 'TEXTFIELD', []],
            FieldType::Date      => ['INPUT', 'LABEL', ['binding' => '{approval.signed}']],
            FieldType::Checkbox  => ['INPUT', 'CHECKBOX', []],
        };

        [$width, $height] = match ($field->type) {
            FieldType::Signature, FieldType::Initials => [150, 50],
            FieldType::Date                            => [120, 20],
            FieldType::Text                            => [180, 20],
            FieldType::Checkbox                        => [20, 20],
        };

        return [
            'name'    => $field->fieldName,
            'type'    => $validSignType,
            'subtype' => $subtype,
            'extract' => true,
            'extractAnchor' => [
                'text'           => $field->anchorString,
                'index'          => 0,
                'anchorPoint'    => 'TOPLEFT',
                'characterIndex' => 0,
                'leftOffset'     => 0,
                'topOffset'      => 0,
                'width'          => $width,
                'height'         => $height,
            ],
        ] + $extra;
    }

    /**
     * @param PreparedField[] $fields
     */
    private function assertFieldsResolvable(Envelope $envelope, Document $document, array $fields): void
    {
        foreach ($fields as $field) {
            if (!$envelope->signerByKey($field->signerKey) instanceof Signer) {
                throw new ProviderException(sprintf(
                    "Document '%s' references unknown signer key '%s' in field '%s'.",
                    $document->id,
                    $field->signerKey,
                    $field->fieldName,
                ));
            }
        }
    }

    /**
     * @return array{0:string, 1:string}
     */
    private function splitName(string $fullName): array
    {
        $trim = trim($fullName);
        $pos = strpos($trim, ' ');
        if ($pos === false) {
            return [$trim, $trim];
        }
        return [substr($trim, 0, $pos), trim(substr($trim, $pos + 1))];
    }

    private function fileName(Document $document): string
    {
        $base = preg_replace('/[^A-Za-z0-9._\-]/', '_', $document->name) ?? $document->id;
        return $base . '.pdf';
    }
}
