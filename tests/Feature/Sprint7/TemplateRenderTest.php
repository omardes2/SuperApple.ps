<?php

namespace Tests\Feature\Sprint7;

use App\Exceptions\MissingTemplateVariableException;
use App\Support\TemplateRenderer;
use Tests\TestCase;

class TemplateRenderTest extends TestCase
{
    public function test_renders_variables(): void
    {
        $out = TemplateRenderer::render('مرحباً {{customer_name}}، فاتورتك {{invoice_number}}', [
            'customer_name' => 'أحمد', 'invoice_number' => 'INV-1',
        ]);
        $this->assertSame('مرحباً أحمد، فاتورتك INV-1', $out);
    }

    public function test_missing_variable_is_rejected(): void
    {
        $this->expectException(MissingTemplateVariableException::class);
        TemplateRenderer::render('مرحباً {{customer_name}} — {{unknown_var}}', ['customer_name' => 'أحمد']);
    }

    public function test_null_variable_is_treated_as_missing(): void
    {
        $this->expectException(MissingTemplateVariableException::class);
        TemplateRenderer::render('{{due_date}}', ['due_date' => null]);
    }

    public function test_referenced_variables_are_extracted(): void
    {
        $vars = TemplateRenderer::referencedVariables('{{a}} and {{ b }} and {{a}}');
        $this->assertEqualsCanonicalizing(['a', 'b'], $vars);
    }

    public function test_zero_value_is_valid_not_missing(): void
    {
        $out = TemplateRenderer::render('الرصيد {{balance_usd}}', ['balance_usd' => '0.00']);
        $this->assertSame('الرصيد 0.00', $out);
    }
}
