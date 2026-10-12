<?php

namespace App\Services\Compliance;

use App\Services\Campaigns\CampaignMessageBuilder;
use App\Services\Campaigns\TemplateRenderer;
use Illuminate\Support\Str;

/**
 * Scores an email's subject and body (0–100, higher is safer) against the
 * things spam filters and readers react to: spam phrases, shouting, too
 * many exclamation marks or links, link shorteners, images, length. Every
 * point lost comes with a sentence saying what to change.
 */
class SpamChecker
{
    public const GOOD = 80;

    public const OK = 60;

    /**
     * @return array{score: int, label: string, color: string, issues: list<string>}
     */
    public function check(?string $subject, ?string $html): array
    {
        $subject = $this->withoutVariables((string) $subject);
        $html = $this->withoutVariables((string) $html);
        $body = app(CampaignMessageBuilder::class)->htmlToText($html);

        $score = 100;
        $issues = [];
        $penalize = function (int $points, string $issue) use (&$score, &$issues): void {
            $score -= $points;
            $issues[] = $issue;
        };

        // Spam phrases.
        $found = $this->spamWords($subject.' '.$body);

        if ($found !== []) {
            $penalize(min(40, count($found) * 8), 'Spam trigger words: "'.implode('", "', array_slice($found, 0, 8)).'". Rephrase them.');
        }

        // Subject.
        if ($subject !== '') {
            if (str_contains($subject, '!')) {
                $penalize(10, 'Exclamation mark in the subject. Cold emails that look like a friendly note do better.');
            }

            if ($this->shoutingWords($subject) >= 1) {
                $penalize(10, 'ALL CAPS in the subject.');
            }

            if (mb_strlen($subject) > 60) {
                $penalize(5, 'Long subject ('.mb_strlen($subject).' characters). Under 60 (ideally 2–5 words) reads better on phones.');
            }

            if (preg_match('/^\s*(re|fwd?):/i', $subject)) {
                $penalize(10, 'The subject starts with "Re:" or "Fwd:" on a first email. Filters and readers see this as deceptive.');
            }
        }

        // Body.
        $words = str_word_count(strip_tags($body));

        if (substr_count($body, '!') > 2) {
            $penalize(10, substr_count($body, '!').' exclamation marks in the email. Use one at most.');
        }

        if (($caps = $this->shoutingWords($body)) >= 3) {
            $penalize(10, "{$caps} words in ALL CAPS. Don't shout.");
        }

        // Distinct URLs, in links or bare text (image sources aside).
        preg_match_all('~https?://[^\s"\'<>]+~i', preg_replace('/<img\b[^>]*>/i', '', $html) ?? $html, $urls);
        $links = count(array_unique(array_map(fn (string $url): string => rtrim(Str::lower($url), '/.,)'), $urls[0])));

        if ($links > 2) {
            $penalize(min(20, ($links - 2) * 7), "{$links} links. One link (or none) in a cold email is safest.");
        }

        if ($shorteners = $this->shorteners($html)) {
            $penalize(20, 'Link shorteners ('.implode(', ', $shorteners).'). Spam filters distrust them: link to your own domain.');
        }

        $images = preg_match_all('/<img\b/i', $html) ?: 0;

        if ($images > 0) {
            $penalize(min(20, $images * 10), $images === 1 ? 'An image in the email. Plain text lands in the inbox more often.' : "{$images} images. Plain text lands in the inbox more often.");
        }

        if ($words > 200) {
            $penalize(10, "{$words} words. Cold emails under 125 words get more replies.");
        }

        if (preg_match('/[$€£]\s?\d/', $subject.' '.$body)) {
            $penalize(5, 'Prices or money amounts. Save them for the reply.');
        }

        $score = max(0, min(100, $score));

        return [
            'score' => $score,
            'label' => match (true) {
                $score >= self::GOOD => 'Looks good',
                $score >= self::OK => 'Could be better',
                default => 'Risky',
            },
            'color' => match (true) {
                $score >= self::GOOD => 'success',
                $score >= self::OK => 'warning',
                default => 'danger',
            },
            'issues' => $issues,
        ];
    }

    /**
     * One line for the editor: "Spam score 85/100 · Looks good".
     */
    public function summary(?string $subject, ?string $html): string
    {
        $result = $this->check($subject, $html);

        return "Spam check: {$result['score']}/100 · {$result['label']}"
            .($result['issues'] === [] ? '' : ' · '.$result['issues'][0]);
    }

    /**
     * @return list<string>
     */
    protected function spamWords(string $text): array
    {
        $text = Str::lower($text);
        $found = [];

        foreach ((array) config('outreach.spam.words', []) as $word) {
            $word = Str::lower((string) $word);
            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($word, '/').'(?![\p{L}\p{N}])/u';

            if ($word !== '' && preg_match($pattern, $text)) {
                $found[] = $word;
            }
        }

        return $found;
    }

    protected function shoutingWords(string $text): int
    {
        preg_match_all('/\b[A-Z]{4,}\b/', $text, $matches);

        // Common acronyms aren't shouting.
        return count(array_diff($matches[0], ['HTTP', 'HTTPS', 'HTML', 'SAAS', 'B2B', 'CEO', 'CTO', 'GDPR', 'ASAP', 'NASA', 'USA']));
    }

    /**
     * @return list<string>
     */
    protected function shorteners(string $html): array
    {
        $found = [];

        foreach ((array) config('outreach.spam.url_shorteners', []) as $host) {
            if (preg_match('~https?://(www\.)?'.preg_quote((string) $host, '~').'/~i', $html)) {
                $found[] = $host;
            }
        }

        return $found;
    }

    /**
     * {{variables}} are filled in per lead; check the text around them.
     */
    protected function withoutVariables(string $text): string
    {
        return preg_replace(TemplateRenderer::VARIABLE_PATTERN, 'Name', $text) ?? $text;
    }
}
