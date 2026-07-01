# ValidSign implementation of the document signer SDK.

ValidSign (OneSpan Sign) implementation of the
[`SignatureProvider`](https://github.com/LauLamanApps/document-signer-sdk/blob/main/src/Provider/SignatureProvider.php) contract from
[`laulamanapps/document-signer-sdk`](https://github.com/LauLamanApps/document-signer-sdk).

## Install

```bash
composer require laulamanapps/document-signer-validsign
```

## Quick start

```php
use LauLamanApps\DocumentSigner\Sdk\Document\Document;
use LauLamanApps\DocumentSigner\Sdk\Envelope\Envelope;
use LauLamanApps\DocumentSigner\Sdk\Signer\Signer;
use LauLamanApps\DocumentSigner\ValidSign\ValidSignConfig;
use LauLamanApps\DocumentSigner\ValidSign\ValidSignProvider;

$provider = new ValidSignProvider(new ValidSignConfig(
    apiKey:  getenv('VALIDSIGN_API_KEY'),
    baseUrl: 'https://my.validsign.nl/api',
));

$receipt = $provider->send(new Envelope(
    name:         'NDA',
    documents:    [new Document(
        id:   'nda',
        name: 'NDA',
        html: '<p>{[signature:counterparty:sig]} on {[date:counterparty:signdate]}</p>',
    )],
    signers:      [new Signer(key: 'counterparty', name: 'Jane Doe', email: 'jane@example.com')],
    emailSubject: 'Please sign the NDA',
));

echo $receipt->provider;           // "validsign" (ValidSignProvider::NAME)
echo $receipt->providerEnvelopeId; // ValidSign packageId
```

## What it does

For every document in the envelope, this package:

1. Parses `{[type:signer:name]}` placeholders out of the HTML.
2. Substitutes each one with a hidden anchor token (`[[VS:type:signer:name]]`).
3. Renders the HTML to PDF via the SDK's `PdfRenderer` (Browsershot by default).
4. POSTs the PDFs + a OneSpan `package` JSON to `POST /packages` with anchor
   extraction enabled, so ValidSign positions each signature/field at its
   anchor location.
5. Returns an `EnvelopeReceipt` containing the ValidSign package id and a
   normalised `EnvelopeStatus`.

## Downloads

Both `downloadSigned()` and `downloadAudit()` write to a temp file and hand you
an `\SplFileInfo` — check the extension:

```php
$archive = $provider->downloadSigned($packageId);
// $archive->getExtension() === 'zip'
// A ZIP with one signed PDF per document in the package (endpoint: /packages/{id}/documents/zip)

$audit = $provider->downloadAudit($packageId);
// $audit->getExtension() === 'pdf'
// The Evidence Summary Report (endpoint: /packages/{id}/evidence/summary)
```

Callers own the file lifecycle — copy or `@unlink()` when done.

## Field mapping

| SDK `FieldType` | ValidSign `type` / `subtype` |
| --- | --- |
| `Signature` | `SIGNATURE` / `FULLNAME` |
| `Initials`  | `SIGNATURE` / `INITIALS` |
| `Text`      | `INPUT` / `TEXTFIELD` |
| `Date`      | `INPUT` / `LABEL` (`{approval.signed}`) |
| `Checkbox`  | `INPUT` / `CHECKBOX` |

## Requirements

- PHP 8.5
- `laulamanapps/documentsigner-sdk`
- A ValidSign tenant + API key
- Node.js + Puppeteer (for the default Browsershot renderer)

## Documentation

The full provider guide — credentials, endpoint mapping, status mapping,
sequential signing, injecting a custom HTTP client, troubleshooting — lives in
the SDK's docs:

- [ValidSign provider guide](https://github.com/LauLamanApps/document-signer-sdk/blob/main/docs/providers/validsign.md)
- [Placeholder syntax](https://github.com/LauLamanApps/document-signer-sdk/blob/main/docs/placeholders.md)
- [PDF rendering](https://github.com/LauLamanApps/document-signer-sdk/blob/main/docs/pdf-rendering.md)
- [Architecture overview](https://github.com/LauLamanApps/document-signer-sdk/blob/main/docs/architecture.md)
