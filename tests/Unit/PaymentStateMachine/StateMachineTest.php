<?php

namespace Tests\Unit\PaymentStateMachine;

use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\ReservationStatus;
use App\Enums\TicketStatus;
use PHPUnit\Framework\TestCase;

class StateMachineTest extends TestCase
{
    public function test_payment_happy_path(): void
    {
        $this->assertTrue(PaymentStatus::Created->canTransitionTo(PaymentStatus::RequiresAction));
        $this->assertTrue(PaymentStatus::RequiresAction->canTransitionTo(PaymentStatus::Processing));
        $this->assertTrue(PaymentStatus::Processing->canTransitionTo(PaymentStatus::Succeeded));
        $this->assertTrue(PaymentStatus::Succeeded->canTransitionTo(PaymentStatus::PartiallyRefunded));
        $this->assertTrue(PaymentStatus::PartiallyRefunded->canTransitionTo(PaymentStatus::Refunded));
    }

    public function test_out_of_order_webhooks_can_skip_intermediate_payment_states(): void
    {
        $this->assertTrue(PaymentStatus::Created->canTransitionTo(PaymentStatus::Succeeded));
        $this->assertTrue(PaymentStatus::Created->canTransitionTo(PaymentStatus::Failed));
    }

    public function test_payments_never_move_backwards_or_out_of_failure(): void
    {
        $this->assertFalse(PaymentStatus::Succeeded->canTransitionTo(PaymentStatus::Processing));
        $this->assertFalse(PaymentStatus::Succeeded->canTransitionTo(PaymentStatus::Failed));
        $this->assertFalse(PaymentStatus::Failed->canTransitionTo(PaymentStatus::Succeeded));
        $this->assertFalse(PaymentStatus::Refunded->canTransitionTo(PaymentStatus::Succeeded));
        $this->assertTrue(PaymentStatus::Failed->isFinal());
        $this->assertTrue(PaymentStatus::Refunded->isFinal());
    }

    public function test_only_captured_payments_count_as_captured(): void
    {
        $this->assertTrue(PaymentStatus::Succeeded->isCaptured());
        $this->assertTrue(PaymentStatus::PartiallyRefunded->isCaptured());
        $this->assertFalse(PaymentStatus::Processing->isCaptured());
        $this->assertFalse(PaymentStatus::Failed->isCaptured());
    }

    public function test_order_transitions(): void
    {
        $this->assertTrue(OrderStatus::PendingPayment->canTransitionTo(OrderStatus::Paid));
        $this->assertTrue(OrderStatus::PaymentFailed->canTransitionTo(OrderStatus::Paid));
        $this->assertTrue(OrderStatus::Paid->canTransitionTo(OrderStatus::PartiallyRefunded));
        $this->assertTrue(OrderStatus::PartiallyRefunded->canTransitionTo(OrderStatus::Refunded));

        $this->assertFalse(OrderStatus::Paid->canTransitionTo(OrderStatus::PendingPayment));
        $this->assertFalse(OrderStatus::Paid->canTransitionTo(OrderStatus::Cancelled));
        $this->assertFalse(OrderStatus::Refunded->canTransitionTo(OrderStatus::Paid));
        // A late payment can still complete a lapsed order, but nothing else can revive it.
        $this->assertTrue(OrderStatus::Cancelled->canTransitionTo(OrderStatus::Paid));
        $this->assertFalse(OrderStatus::Cancelled->canTransitionTo(OrderStatus::PendingPayment));
    }

    public function test_event_lifecycle_requires_review_before_publication(): void
    {
        $this->assertFalse(EventStatus::Draft->canTransitionTo(EventStatus::Published));
        $this->assertTrue(EventStatus::Draft->canTransitionTo(EventStatus::PendingReview));
        $this->assertTrue(EventStatus::PendingReview->canTransitionTo(EventStatus::Published));
        $this->assertTrue(EventStatus::PendingReview->canTransitionTo(EventStatus::Draft));
        $this->assertTrue(EventStatus::Published->canTransitionTo(EventStatus::Cancelled));
        $this->assertTrue(EventStatus::Cancelled->isTerminal());
        $this->assertFalse(EventStatus::Draft->isPublic());
        $this->assertTrue(EventStatus::Published->isPublic());
    }

    public function test_only_valid_tickets_are_admissible(): void
    {
        $this->assertTrue(TicketStatus::Valid->isAdmissible());

        foreach ([TicketStatus::CheckedIn, TicketStatus::Cancelled, TicketStatus::Refunded, TicketStatus::Void] as $status) {
            $this->assertFalse($status->isAdmissible(), $status->value);
        }

        $this->assertFalse(TicketStatus::Refunded->canTransitionTo(TicketStatus::Valid));
        $this->assertFalse(TicketStatus::Void->canTransitionTo(TicketStatus::CheckedIn));
    }

    public function test_refunds_need_approval_before_processing(): void
    {
        $this->assertFalse(RefundStatus::Requested->canTransitionTo(RefundStatus::Processing));
        $this->assertTrue(RefundStatus::Requested->canTransitionTo(RefundStatus::Approved));
        $this->assertTrue(RefundStatus::Approved->canTransitionTo(RefundStatus::Processing));
        $this->assertFalse(RefundStatus::Succeeded->canTransitionTo(RefundStatus::Processing));
    }

    public function test_reservations_settle_exactly_once(): void
    {
        $this->assertTrue(ReservationStatus::Active->canTransitionTo(ReservationStatus::Converted));
        $this->assertFalse(ReservationStatus::Expired->canTransitionTo(ReservationStatus::Converted));
        $this->assertFalse(ReservationStatus::Converted->canTransitionTo(ReservationStatus::Released));
    }
}
