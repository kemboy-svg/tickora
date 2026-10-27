<?php

namespace App\Enums;

enum VerificationStatus: string
{
    case Submitted = 'submitted';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
