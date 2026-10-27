<?php

namespace App\Enums;

enum EventStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Published = 'published';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::PendingReview, self::Cancelled],
            // A reviewer either approves (publish) or sends it back to draft.
            self::PendingReview => [self::Draft, self::Published, self::Cancelled],
            self::Published => [self::Cancelled, self::Completed],
            self::Cancelled, self::Completed => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Visible on public listings and event pages.
     */
    public function isPublic(): bool
    {
        return $this === self::Published || $this === self::Completed;
    }

    /**
     * Organisers may freely edit details only before publication.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
