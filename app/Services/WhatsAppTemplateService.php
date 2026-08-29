<?php

namespace App\Services;

use App\Models\WhatsAppTemplate;
use App\Support\TemplateRenderer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/** Thin CRUD + preview around WhatsApp templates. */
class WhatsAppTemplateService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string,mixed> $data */
    public function create(array $data): WhatsAppTemplate
    {
        $template = WhatsAppTemplate::create([
            'name' => $data['name'],
            'key' => $data['key'] ?? Str::slug($data['name'], '_'),
            'category' => $data['category'] ?? WhatsAppTemplate::KEY_MANUAL,
            'language' => $data['language'] ?? 'ar',
            'body' => $data['body'],
            'is_active' => $data['is_active'] ?? true,
            'variables_schema' => $data['variables_schema'] ?? TemplateRenderer::referencedVariables($data['body']),
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        $this->audit->log('whatsapp_template_created', $template, 'WhatsApp', description: 'إنشاء قالب واتساب');

        return $template;
    }

    /** @param array<string,mixed> $data */
    public function update(WhatsAppTemplate $template, array $data): WhatsAppTemplate
    {
        $template->update([
            'name' => $data['name'] ?? $template->name,
            'category' => $data['category'] ?? $template->category,
            'language' => $data['language'] ?? $template->language,
            'body' => $data['body'] ?? $template->body,
            'is_active' => $data['is_active'] ?? $template->is_active,
            'variables_schema' => TemplateRenderer::referencedVariables($data['body'] ?? $template->body),
            'updated_by' => Auth::id(),
        ]);

        $this->audit->log('whatsapp_template_updated', $template, 'WhatsApp', description: 'تعديل قالب واتساب');

        return $template;
    }

    /**
     * Render a template with sample values for the preview pane.
     *
     * @param  array<string,scalar|null>  $sample
     */
    public function preview(WhatsAppTemplate $template, array $sample): string
    {
        return TemplateRenderer::render($template->body, $sample);
    }
}
