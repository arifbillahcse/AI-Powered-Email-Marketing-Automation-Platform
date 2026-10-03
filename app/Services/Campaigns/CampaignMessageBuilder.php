<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignStep;
use App\Models\EmailAccount;
use App\Models\Lead;
use Illuminate\Support\Str;

/**
 * Builds the exact email a lead gets for a campaign step: personalised
 * subject and body, the mailbox signature, and the compliance footer
 * (unsubscribe link + workspace mailing address). Used for previews now and
 * for real sends in Phase 5.
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

        $subjectTemplate = $replyInThread ? $this->firstStepSubject($campaign) : (string) $step->subject;
        $subject = $this->renderer->render($subjectTemplate, $variables, $replyInThread ? "{$lead->getKey()}:first" : $seed);

        if ($replyInThread && $subject !== '' && ! Str::startsWith(Str::lower($subject), 're:')) {
            $subject = "Re: {$subject}";
        }

        $missing = $this->renderer->missingVariables($subjectTemplate.' '.$step->body, $variables);
        $address = $campaign->workspace->mailingAddress();

        if ($campaign->plain_text) {
            $text = $this->renderer->render($this->htmlToText($step->body), $variables, $seed);

            if (filled($mailbox?->signature)) {
                $text .= "\n\n".$this->htmlToText($mailbox->signature);
            }

            $text .= "\n\n--\n".($address ? "{$address}\n" : '')."Unsubscribe: {$unsubscribeUrl}";

            return ['subject' => $subject, 'html' => null, 'text' => $text, 'missing' => $missing, 'reply_in_thread' => $replyInThread];
        }

        $html = $this->renderer->render($step->body, $variables, $seed, html: true);

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
        return [...Lead::FIELDS, 'full_name', 'sender_name', 'sender_first_name', 'sender_email'];
    }

    public function htmlToText(string $html): string
    {
        $text = preg_replace(['/<br\s*\/?>/i', '/<\/(p|div|li|h[1-6])>/i', '/<li[^>]*>/i'], ["\n", "\n\n", '- '], $html) ?? $html;
        $text = preg_replace('/<a\s[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/is', '$2 ($1)', $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }

    protected function firstStepSubject(Campaign $campaign): string
    {
        return (string) $campaign->steps()->where('position', 1)->value('subject');
    }
}
