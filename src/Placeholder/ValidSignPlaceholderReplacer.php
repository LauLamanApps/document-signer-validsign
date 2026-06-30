<?php

declare(strict_types=1);

namespace LauLamanApps\DocumentSigner\ValidSign\Placeholder;

use LauLamanApps\DocumentSigner\Sdk\Placeholder\AbstractAnchorPlaceholderReplacer;
use LauLamanApps\DocumentSigner\Sdk\Placeholder\ParsedPlaceholder;

final class ValidSignPlaceholderReplacer extends AbstractAnchorPlaceholderReplacer
{
    /**
     * Anchor token consumed by ValidSign `extractAnchor.text`. The double-bracket
     * `[[VS:...]]` form is chosen to be unique within typical contract HTML and
     * stable through the Browsershot text layer.
     */
    protected function formatAnchor(ParsedPlaceholder $placeholder): string
    {
        return sprintf(
            '[[VS:%s:%s:%s]]',
            $placeholder->type->value,
            $placeholder->signerKey,
            $placeholder->fieldName,
        );
    }
}
