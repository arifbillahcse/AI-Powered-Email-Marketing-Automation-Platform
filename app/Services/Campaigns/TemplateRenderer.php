<?php

namespace App\Services\Campaigns;

/**
 * Renders email templates:
 *
 * - Variables: {{first_name}}, with a fallback {{first_name|there}}.
 *   Names are case- and space-insensitive. Unknown/blank → fallback or "".
 * - Spintax: {Hi|Hello|Hey}, nestable: {Hi {there|friend}|Hello}.
 *   Braces without a "|" are left alone (so CSS in HTML is safe).
 *
 * Spintax choices are seeded (per lead + step), so a preview shows exactly
 * what that lead will receive. Variable values are inserted after spintax
 * runs, so lead data can never inject spintax, and are HTML-escaped in
 * HTML mode.
 */
class TemplateRenderer
{
    public const VARIABLE_PATTERN = '/\{\{\s*([A-Za-z0-9_]+)\s*(?:\|([^{}]*))?\}\}/';

    /**
     * @param  array<string, scalar|null>  $variables
     */
    public function render(string $template, array $variables, string $seed, bool $html = false): string
    {
        $variables = array_change_key_case($variables, CASE_LOWER);

        // 1. Protect {{variables}} from spintax.
        $tokens = [];
        $template = preg_replace_callback(self::VARIABLE_PATTERN, function (array $match) use (&$tokens): string {
            $tokens[] = ['name' => strtolower($match[1]), 'fallback' => $match[2] ?? null];

            return "\u{E000}".(count($tokens) - 1)."\u{E001}";
        }, $template) ?? $template;

        // 2. Spintax.
        $template = $this->spin($template, $seed);

        // 3. Variables.
        return preg_replace_callback("/\u{E000}(\d+)\u{E001}/u", function (array $match) use ($tokens, $variables, $html): string {
            $token = $tokens[(int) $match[1]];
            $value = trim((string) ($variables[$token['name']] ?? ''));

            if ($value === '') {
                $value = trim((string) ($token['fallback'] ?? ''));
            }

            return $html ? e($value) : $value;
        }, $template) ?? $template;
    }

    /**
     * Variable names used in a template.
     *
     * @return list<string>
     */
    public function variablesIn(string $template): array
    {
        preg_match_all(self::VARIABLE_PATTERN, $template, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[1])));
    }

    /**
     * Variables used without a fallback that are blank for these values,
     * e.g. ["first_name"] when the lead has no first name.
     *
     * @param  array<string, scalar|null>  $variables
     * @return list<string>
     */
    public function missingVariables(string $template, array $variables): array
    {
        $variables = array_change_key_case($variables, CASE_LOWER);

        preg_match_all(self::VARIABLE_PATTERN, $template, $matches, PREG_SET_ORDER);

        $missing = [];

        foreach ($matches as $match) {
            $name = strtolower($match[1]);
            $hasFallback = trim($match[2] ?? '') !== '';

            if (! $hasFallback && trim((string) ($variables[$name] ?? '')) === '') {
                $missing[] = $name;
            }
        }

        return array_values(array_unique($missing));
    }

    protected function spin(string $text, string $seed): string
    {
        $occurrence = 0;

        // Resolve innermost groups first. Groups without "|" are swapped for
        // placeholder braces so the loop ends, then restored.
        do {
            $text = preg_replace_callback('/\{([^{}]*)\}/u', function (array $match) use ($seed, &$occurrence): string {
                if (! str_contains($match[1], '|')) {
                    return "\u{E002}{$match[1]}\u{E003}";
                }

                $options = explode('|', $match[1]);
                $index = crc32($seed.':'.$occurrence++) % count($options);

                return $options[$index];
            }, $text, -1, $count) ?? $text;
        } while ($count > 0);

        return str_replace(["\u{E002}", "\u{E003}"], ['{', '}'], $text);
    }
}
