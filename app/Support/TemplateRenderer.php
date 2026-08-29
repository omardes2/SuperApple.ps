<?php

namespace App\Support;

use App\Exceptions\MissingTemplateVariableException;
use Illuminate\Support\Facades\Log;

/**
 * Renders {{ variable }} placeholders in a template body. Rendering is strict:
 * if the body references a variable that was not supplied (or is null), the
 * render is rejected so a broken, half-filled message is never sent.
 */
final class TemplateRenderer
{
    /**
     * @param  array<string,scalar|null>  $variables
     *
     * @throws MissingTemplateVariableException
     */
    public static function render(string $body, array $variables): string
    {
        preg_match_all('/\{\{\s*([\w.]+)\s*\}\}/u', $body, $matches);
        $referenced = array_values(array_unique($matches[1] ?? []));

        $missing = [];
        foreach ($referenced as $key) {
            if (! array_key_exists($key, $variables) || $variables[$key] === null) {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            Log::warning('WhatsApp template render rejected: missing variables', ['missing' => $missing]);
            throw new MissingTemplateVariableException($missing);
        }

        return preg_replace_callback('/\{\{\s*([\w.]+)\s*\}\}/u', function ($m) use ($variables) {
            return (string) $variables[$m[1]];
        }, $body) ?? $body;
    }

    /**
     * The distinct variable names a template body references.
     *
     * @return list<string>
     */
    public static function referencedVariables(string $body): array
    {
        preg_match_all('/\{\{\s*([\w.]+)\s*\}\}/u', $body, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }
}
