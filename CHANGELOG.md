# Changelog

All notable changes to `laulamanapps/document-signer-validsign` are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

## [2.3.0] - 2026-07-08

### Changed

- `downloadSignedDocument()` continues to take the caller's `Document::$id`
  verbatim (ValidSign already keyed documents on it), but a "document not found"
  (HTTP 404) on that endpoint is now surfaced as the new, **retryable**
  `SignedDocumentUnavailableException` (from the SDK) instead of
  `ProviderNotFoundException`. This makes the "not finalized yet" case uniform
  with DocuSign, so callers polling for a freshly-signed document can catch one
  exception type and back off.
- Minimum PHP lowered to **8.3** (was 8.5); CI now tests 8.3–8.5.

### Upgrade notes

- Requires `laulamanapps/document-signer-sdk` ≥ 2.3.0 (for
  `SignedDocumentUnavailableException`).
- If you previously caught `ProviderNotFoundException` around
  `downloadSignedDocument()` to detect a not-yet-ready document, catch
  `SignedDocumentUnavailableException` (or its parent `ProviderException`) instead.
