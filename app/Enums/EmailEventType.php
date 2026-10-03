<?php

namespace App\Enums;

enum EmailEventType: string
{
    case Sent = 'sent';
    case Open = 'open';
    case Click = 'click';
    case Bounce = 'bounce';
    case Unsubscribe = 'unsubscribe';
    case Complaint = 'complaint';
    case Reply = 'reply';
}
