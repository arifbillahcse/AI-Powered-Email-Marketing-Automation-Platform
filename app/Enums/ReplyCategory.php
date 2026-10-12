<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * What a lead's reply means, as classified by the AI.
 */
enum ReplyCategory: string implements HasColor, HasLabel
{
    case Interested = 'interested';
    case MeetingRequest = 'meeting_request';
    case Question = 'question';
    case NotNow = 'not_now';
    case NotInterested = 'not_interested';
    case WrongPerson = 'wrong_person';
    case Unsubscribe = 'unsubscribe';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Interested => 'Interested',
            self::MeetingRequest => 'Wants a meeting',
            self::Question => 'Question',
            self::NotNow => 'Not now',
            self::NotInterested => 'Not interested',
            self::WrongPerson => 'Wrong person',
            self::Unsubscribe => 'Unsubscribe request',
            self::Other => 'Other',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Interested, self::MeetingRequest => 'success',
            self::Question => 'info',
            self::NotNow, self::WrongPerson => 'warning',
            self::NotInterested, self::Unsubscribe => 'danger',
            self::Other => 'gray',
        };
    }

    /**
     * For the AI prompt: what each category means.
     */
    public function definition(): string
    {
        return match ($this) {
            self::Interested => 'positive, wants to learn more or continue the conversation',
            self::MeetingRequest => 'asks for or agrees to a call, demo or meeting, or proposes a time',
            self::Question => 'asks a question (price, details, how it works) without being clearly positive or negative',
            self::NotNow => 'maybe later: bad timing, busy, contact again in a few months',
            self::NotInterested => 'declines, no need, already has a solution',
            self::WrongPerson => 'not the right contact, or refers to someone else',
            self::Unsubscribe => 'asks to stop emailing, remove them from the list, or unsubscribe',
            self::Other => 'anything else',
        };
    }

    /**
     * The lead label this category sets automatically (when the lead hasn't
     * been labelled by hand).
     */
    public function leadStatus(): ?LeadStatus
    {
        return match ($this) {
            self::Interested, self::MeetingRequest, self::Question => LeadStatus::Interested,
            self::NotInterested => LeadStatus::NotInterested,
            default => null,
        };
    }

    /**
     * Worth a drafted answer.
     */
    public function wantsReply(): bool
    {
        return in_array($this, [self::Interested, self::MeetingRequest, self::Question, self::NotNow, self::WrongPerson], true);
    }
}
