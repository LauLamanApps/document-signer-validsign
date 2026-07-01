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
    public function it_emits_native_esl_text_tags_using_the_signer_role_map(): void
    {
        $html = '<p>{[signature:counterparty:sig]} on {[date:counterparty:signdate]}</p>';
        $parsed = (new PlaceholderParser())->parse($html);

        $replacer = (new ValidSignPlaceholderReplacer())
            ->withSignerRoleMap(['counterparty' => 'Signer1']);

        $prepared = $replacer->replace($html, $parsed);

        self::assertCount(2, $prepared->fields);
        self::assertSame(
            '{{esl_sig:Signer1:Signature:size(200,50)}}',
            $prepared->fields[0]->anchorString,
        );
        self::assertSame(
            '{{esl_signdate:Signer1:SigningDate:size(120,20)}}',
            $prepared->fields[1]->anchorString,
        );
        self::assertStringContainsString('{{esl_sig:Signer1:Signature:size(200,50)}}', $prepared->html);
        self::assertStringContainsString('{{esl_signdate:Signer1:SigningDate:size(120,20)}}', $prepared->html);
    }

    #[Test]
    public function it_marks_text_and_checkbox_fields_as_required(): void
    {
        $html = '<p>{[text:s1:name]}{[checkbox:s1:agree]}{[signature:s1:sig]}</p>';
        $parsed = (new PlaceholderParser())->parse($html);

        $prepared = (new ValidSignPlaceholderReplacer())
            ->withSignerRoleMap(['s1' => 'Signer1'])
            ->replace($html, $parsed);

        self::assertSame('{{*esl_name:Signer1:TextField:size(200,20)}}', $prepared->fields[0]->anchorString);
        self::assertSame('{{*esl_agree:Signer1:Checkbox:size(20,20)}}', $prepared->fields[1]->anchorString);
        // Signatures are implicitly required per ValidSign; no `*` prefix.
        self::assertSame('{{esl_sig:Signer1:Signature:size(200,50)}}', $prepared->fields[2]->anchorString);
    }

    #[Test]
    public function positional_signer_roles_map_correctly_for_multiple_signers(): void
    {
        $html = '<p>{[signature:customer:sig]} and {[signature:salesrep:sig]}</p>';
        $parsed = (new PlaceholderParser())->parse($html);

        $prepared = (new ValidSignPlaceholderReplacer())
            ->withSignerRoleMap(['customer' => 'Signer1', 'salesrep' => 'Signer2'])
            ->replace($html, $parsed);

        self::assertStringContainsString('Signer1:Signature', $prepared->fields[0]->anchorString);
        self::assertStringContainsString('Signer2:Signature', $prepared->fields[1]->anchorString);
    }

    #[Test]
    public function html_wrapper_does_not_escape_the_curly_braces(): void
    {
        // The text-tag detector on ValidSign's server looks for literal `{{esl…}}`
        // in the extracted PDF text. If we HTML-escaped `{` and `}` here we'd
        // break server-side detection.
        $html = '<p>{[signature:s1:sig]}</p>';
        $parsed = (new PlaceholderParser())->parse($html);

        $prepared = (new ValidSignPlaceholderReplacer())
            ->withSignerRoleMap(['s1' => 'Signer1'])
            ->replace($html, $parsed);

        self::assertStringContainsString('{{esl_sig:Signer1:Signature:size(200,50)}}', $prepared->html);
        self::assertStringNotContainsString('&#123;', $prepared->html);
        self::assertStringNotContainsString('&#125;', $prepared->html);
    }
}
