<?php

declare(strict_types=1);

namespace LauLamanApps\DocumentSigner\ValidSign\Tests\Placeholder;

use LauLamanApps\DocumentSigner\Sdk\Placeholder\PlaceholderParser;
use LauLamanApps\DocumentSigner\ValidSign\Placeholder\ValidSignPlaceholderReplacer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ValidSignPlaceholderReplacerTest extends TestCase
{
    #[Test]
    public function it_emits_double_bracket_anchor_tokens(): void
    {
        $html = '<p>{[signature:counterparty:sig]} on {[date:counterparty:signdate]}</p>';
        $parsed = (new PlaceholderParser())->parse($html);

        $prepared = (new ValidSignPlaceholderReplacer())->replace($html, $parsed);

        self::assertCount(2, $prepared->fields);
        self::assertSame('[[VS:signature:counterparty:sig]]', $prepared->fields[0]->anchorString);
        self::assertSame('[[VS:date:counterparty:signdate]]', $prepared->fields[1]->anchorString);

        self::assertStringContainsString('[[VS:signature:counterparty:sig]]', $prepared->html);
        self::assertStringContainsString('[[VS:date:counterparty:signdate]]', $prepared->html);
    }
}
