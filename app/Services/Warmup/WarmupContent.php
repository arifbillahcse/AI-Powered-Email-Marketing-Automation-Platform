<?php

namespace App\Services\Warmup;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Short, ordinary business emails for warmup conversations. Plain text,
 * no links or images, nothing that reads like marketing, and enough
 * variety that no two look alike.
 */
class WarmupContent
{
    protected const SUBJECTS = [
        'Quick question', 'Following up', 'Catching up', 'Thoughts on this week', 'Meeting notes',
        'Next steps', 'Checking in', 'Project update', 'A small favour', 'Tomorrow',
        'Ideas for the plan', 'Schedule for next week', 'Short update', 'About our call', 'Notes from today',
        'Planning ahead', 'Feedback request', 'Draft for review', 'Weekly sync', 'Your input',
    ];

    protected const GREETINGS = ['Hi {name},', 'Hello {name},', 'Hey {name},', 'Good morning {name},', '{name},'];

    protected const OPENERS = [
        'I hope your week is going well.',
        'Hope you had a good weekend.',
        'Thanks again for your time the other day.',
        'I wanted to quickly check in with you.',
        'I was going through my notes from last week.',
        'Just a short message before the day gets busy.',
        'I hope things are calm on your side.',
    ];

    protected const BODIES = [
        'Could you share your thoughts on the timeline when you get a chance? No rush at all.',
        'I put together a few ideas for the next phase and would love to hear what you think.',
        'Are you free for a short call later this week to go over the details?',
        'I made the changes we discussed and everything looks good on my end.',
        'Let me know if Thursday afternoon still works for you.',
        'I think we are on track, but I wanted to double check a couple of points with you.',
        'The team liked the approach, so we can move forward whenever you are ready.',
        'Would it help if I sent over a short summary before we meet?',
        'I am still collecting a few numbers, so I will follow up with the final version soon.',
        'Happy to adjust the plan if anything changed on your side.',
    ];

    protected const CLOSINGS = ['Thanks,', 'Best,', 'Cheers,', 'Kind regards,', 'Talk soon,', 'Best regards,', 'Many thanks,'];

    protected const REPLIES = [
        'Thanks for the note. That works for me.',
        'Sounds good, thank you for the update.',
        'Got it, I will take a look and get back to you.',
        'Thursday works well. Talk then.',
        'Great, appreciate you sending this over.',
        'Thanks! I agree with the plan, let us keep going.',
        'Perfect, that helps a lot.',
        'Thank you, I will share it with the team today.',
        'Makes sense. Let me check a couple of things and reply properly tomorrow.',
        'Sounds like a plan. Thanks for keeping me posted.',
    ];

    /**
     * @return array{subject: string, body: string}
     */
    public function compose(string $senderName, string $recipientName): array
    {
        $body = implode("\n\n", [
            $this->greeting($recipientName),
            Arr::random(self::OPENERS).' '.implode(' ', Arr::random(self::BODIES, 2)),
            Arr::random(self::CLOSINGS)."\n".$this->firstName($senderName),
        ]);

        return ['subject' => Arr::random(self::SUBJECTS), 'body' => $body];
    }

    public function reply(string $senderName, string $recipientName): string
    {
        return implode("\n\n", [
            $this->greeting($recipientName),
            Arr::random(self::REPLIES),
            Arr::random(self::CLOSINGS)."\n".$this->firstName($senderName),
        ]);
    }

    public function replySubject(string $subject): string
    {
        return Str::startsWith(Str::lower($subject), 're:') ? $subject : 'Re: '.$subject;
    }

    protected function greeting(string $name): string
    {
        $first = $this->firstName($name);

        return $first === '' ? 'Hi,' : str_replace('{name}', $first, Arr::random(self::GREETINGS));
    }

    protected function firstName(string $name): string
    {
        return (string) Str::of($name)->squish()->before(' ');
    }
}
