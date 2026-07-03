<?php

namespace App\Enums;

enum EditRequestStatus: string
{
    case Pending = 'pending';
    case Applied = 'applied';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
}
