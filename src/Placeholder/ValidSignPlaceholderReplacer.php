<?php

declare(strict_types=1);

namespace LauLamanApps\DocumentSigner\ValidSign\Placeholder;

use LauLamanApps\DocumentSigner\Sdk\Field\FieldType;
use LauLamanApps\DocumentSigner\Sdk\Placeholder\AbstractAnchorPlaceholderReplacer;
use LauLamanApps\DocumentSigner\Sdk\Placeholder\ParsedPlaceholder;

/**
 * Emits ValidSign's native text-tag format directly into the rendered PDF.
 *
 * Tag reference:
 * https://validsign.zendesk.com/hc/nl/articles/360037747091-Text-tags-gebruiken-binnen-documenten
 *
 * The tag format is `{{esl_LABEL:SignerN:TYPE:size(W,H)}}` — ValidSign discovers
 * these tags server-side when the document is created and places the matching
 * field there automatically, so no API-level `extractAnchor` / `approvals`
 * block is needed.
 *
 * Signer identifiers are positional (`Signer1`, `Signer2`, …). This replacer
 * needs a signer-key → SignerN map to translate the SDK's arbitrary signer
 * keys; provide it via {@see withSignerRoleMap()} before calling
 * {@see replace()}. Providers construct one instance per envelope for this.
 */
final class ValidSignPlaceholderReplacer extends AbstractAnchorPlaceholderReplacer
{
    /** @var array<string, string> */
    private array $signerRoleMap = [];

    /**
     * @param array<string, string> $signerRoleMap Map of SDK signer key → ValidSign role token (`Signer1`, `Signer2`, …).
     */
    public function withSignerRoleMap(array $signerRoleMap): self
    {
        $clone = clone $this;
        $clone->signerRoleMap = $signerRoleMap;
        return $clone;
    }

    protected function formatAnchor(ParsedPlaceholder $placeholder): string
    {
        $role = $this->signerRoleMap[$placeholder->signerKey] ?? 'Signer1';
        $tagType = self::tagType($placeholder->type);
        [$w, $h] = self::defaultSize($placeholder->type);
        $prefix = self::needsRequiredMarker($placeholder->type) ? '*' : '';

        return sprintf(
            '{{%sesl_%s:%s:%s:size(%d,%d)}}',
            $prefix,
            $placeholder->fieldName,
            $role,
            $tagType,
            $w,
            $h,
        );
    }

    /**
     * Wrap the tag in the same near-invisible inline `<span>` the SDK base
     * class uses, but skip HTML-escaping — ValidSign's text-tag detector
     * looks for the literal `{{esl…}}` sequence in the PDF text layer, and
     * we don't want it turned into `&#123;&#123;esl…&#125;&#125;` on the way
     * to the renderer. The tag body only contains alphanumerics, underscores,
     * colons, parentheses, and commas, so raw output is safe.
     */
    protected function wrapAnchor(string $anchor): string
    {
        return '<span data-ds-anchor="1" style="color:#ffffff;font-size:1pt;line-height:0;'
            . 'letter-spacing:0;white-space:nowrap;">' . $anchor . '</span>';
    }

    private static function tagType(FieldType $type): string
    {
        return match ($type) {
            FieldType::Signature => 'Signature',
            FieldType::Initials  => 'initials',
            FieldType::Text      => 'TextField',
            FieldType::Date      => 'SigningDate',
            FieldType::Checkbox  => 'Checkbox',
        };
    }

    /**
     * @return array{0:int, 1:int}
     */
    private static function defaultSize(FieldType $type): array
    {
        return match ($type) {
            FieldType::Signature => [200, 50],
            FieldType::Initials  => [100, 30],
            FieldType::Text      => [200, 20],
            FieldType::Date      => [120, 20],
            FieldType::Checkbox  => [20, 20],
        };
    }

    /**
     * Signature and initials are implicitly required per the ValidSign docs;
     * SigningDate is auto-populated. Everything else needs the `*` prefix
     * to be enforced as required on the signer.
     */
    private static function needsRequiredMarker(FieldType $type): bool
    {
        return $type === FieldType::Text || $type === FieldType::Checkbox;
    }
}
