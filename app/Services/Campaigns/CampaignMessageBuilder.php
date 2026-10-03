<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignStep;
use App\Models\EmailAccount;
use App\Models\Lead;
use App\Services\Ai\AiGenerationService;
use Illuminate\Support\Str;

/**
 * Builds the exact email a lead gets for a campaign step: personalised
 * subject and body, the mailbox signature, and the compliance footer
 * (unsubscribe link + workspace mailing address). Used for previews and
 * real sends.
 *
 * Approved AI content fills {{ai_first_line}}, {{ai_subject}} and
 * {{ai_email}}. Content that isn't approved is never used.
 */
class CampaignMessageBuilder
{
    public function __construct(
        protected TemplateRenderer $renderer,
    ) {}

    /**
     * @return array{subject: string, html: ?string, text: string, missing: list<string>, reply_in_thread: bool}
     */
    public function build(Campaign $campaign, CampaignStep $step, Lead $lead, ?EmailAccount $mailbox, string $unsubscribeUrl): array
    {
        $variables = $this->variables($lead, $mailbox);
        $seed = "{$lead->getKey()}:{$step->getKey()}";
        $replyInThread = $step->isReplyInThread();
        $firstStep = $campaign->steps()->where('position', 1)->first(['id', 'subject']);

        $approved = $this->approvedAi($campaign, $lead);
        $stepAi = $approved[$step->getKey()] ?? [];
        $subjectAi = $replyInThread ? ($approved[$firstStep?->getKey()] ?? []) : $stepAi;

        $subjectTemplate = $replyInThread ? (string) $firstStep?->subject : (string) $step->subject;
        $subject = $this->renderer->render(
            $subjectTemplate,
            AiGenerationService::variables($subjectAi, html: false) + $variables,
            $replyInThread ? "{$lead->getKey()}:first" : $seed,
        );

        if ($replyInThread && $subject !== '' && ! Str::startsWith(Str::lower($subject), 're:')) {
            $subject = "Re: {$subject}";
        }

        $missing = array_values(array_unique([
            ...$this->renderer->missingVariables($subjectTemplate, AiGenerationService::variables($subjectAi, html: false) + $variables),
            ...$this->renderer->missingVariables((string) $step->body, AiGenerationService::variables($stepAi, html: false) + $variables),
        ]));
        $address = $campaign->workspace->mailingAddress();
        $textVariables = AiGenerationService::variables($stepAi, html: false) + $variables;

        if ($campaign->plain_text) {
            $text = $this->renderer->render($this->htmlToText($step->body), $textVariables, $seed);

            if (filled($mailbox?->signature)) {
                $text .= "\n\n".$this->htmlToText($mailbox->signature);
            }

            $text .= "\n\n--\n".($address ? "{$address}\n" : '')."Unsubscribe: {$unsubscribeUrl}";

            return ['subject' => $subject, 'html' => null, 'text' => $text, 'missing' => $missing, 'reply_in_thread' => $replyInThread];
        }

        $body = (string) $step->body;

        // An AI email is several paragraphs: don't nest them in the editor's <p>.
        if (filled($stepAi['ai_email'] ?? null)) {
            $body = preg_replace('/<p>\s*(\{\{\s*ai_email\s*(?:\|[^{}]*)?\}\})\s*<\/p>/i', '$1', $body) ?? $body;
        }

        $html = $this->renderer->render($body, AiGenerationService::variables($stepAi, html: true) + $variables, $seed, html: true);

        if (filled($mailbox?->signature)) {
            $html .= "\n<div class=\"signature\">{$mailbox->signature}</div>";
        }

        $html .= "\n".'<p style="margin-top:24px;font-size:12px;color:#71717a;">'
            .($address ? e($address).'<br>' : '')
            .'<a href="'.e($unsubscribeUrl).'" style="color:#71717a;">Unsubscribe</a></p>';

        return [
            'subject' => $subject,
            'html' => $html,
            'text' => $this->htmlToText($html),
            'missing' => $missing,
            'reply_in_thread' => $replyInThread,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function variables(Lead $lead, ?EmailAccount $mailbox): array
    {
        $senderName = (string) ($mailbox?->from_name ?? '');

        return $lead->variables() + [
            'sender_name' => $senderName,
            'sender_first_name' => Str::before($senderName, ' '),
            'sender_email' => (string) ($mailbox?->email ?? ''),
        ];
    }

    /**
     * Variables offered in the editor.
     *
     * @return list<string>
     */
    public static function availableVariables(): array
    {
        return [...Lead::FIELDS, 'full_name', 'sender_name', 'sender_first_name', 'sender_email', ...self::aiVariables()];
    }

    /**
     * @return list<string>
     */
    public static function aiVariables(): array
    {
        return ['ai_first_line', 'ai_subject', 'ai_email'];
    }

    /**
     * @return array<int, array<string, string>>
     */
    protected function approvedAi(Campaign $campaign, Lead $lead): array
    {
        if (! $lead->exists || ! $campaign->exists) {
            return [];
        }

        return app(AiGenerationService::class)->approvedForLead($campaign, $lead);
    }

    public function htmlToText(string $html): string
    {
        $text = preg_replace(['/<br\s*\/?>/i', '/<\/(p|div|li|h[1-6])>/i', '/<li[^>]*>/i'], ["\n", "\n\n", '- '], $html) ?? $html;
        $text = preg_replace('/<a\s[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/is', '$2 ($1)', $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }
}
