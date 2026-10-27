<?php

namespace Tests\Unit;

use App\Support\Reference;
use App\Support\TicketToken;
use PHPUnit\Framework\TestCase;

class TicketTokenTest extends TestCase
{
    public function test_tokens_carry_256_bits_and_round_trip_through_the_payload(): void
    {
        $token = TicketToken::issue();

        $this->assertSame(43, strlen($token->raw));
        $this->assertStringStartsWith('TK1.', $token->payload());

        $scanned = TicketToken::fromPayload($token->payload());

        $this->assertNotNull($scanned);
        $this->assertSame($token->hash(), $scanned->hash());
    }

    public function test_the_stored_hash_is_sha256_of_the_raw_token(): void
    {
        $token = TicketToken::issue();

        $this->assertSame(hash('sha256', $token->raw), $token->hash());
        $this->assertSame(64, strlen($token->hash()));
        $this->assertStringNotContainsString($token->raw, $token->hash());
    }

    public function test_tokens_are_unique(): void
    {
        $hashes = [];

        for ($i = 0; $i < 500; $i++) {
            $hashes[TicketToken::issue()->hash()] = true;
        }

        $this->assertCount(500, $hashes);
    }

    public function test_foreign_or_malformed_payloads_are_rejected(): void
    {
        $this->assertNull(TicketToken::fromPayload(''));
        $this->assertNull(TicketToken::fromPayload('TK1.'));
        $this->assertNull(TicketToken::fromPayload('TK1.short'));
        $this->assertNull(TicketToken::fromPayload('TK2.'.str_repeat('a', 43)));
        $this->assertNull(TicketToken::fromPayload('https://example.com/ticket/42'));
        $this->assertNull(TicketToken::fromPayload('TK1.'.str_repeat('a', 42).'!'));
    }

    public function test_payload_whitespace_from_scanners_is_ignored(): void
    {
        $token = TicketToken::issue();

        $this->assertSame($token->hash(), TicketToken::fromPayload(' '.$token->payload()."\n")?->hash());
    }

    public function test_references_are_readable_and_unambiguous(): void
    {
        $order = Reference::order();

        $this->assertMatchesRegularExpression('/^TKR-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/', $order);
        $this->assertSame($order.'-03', Reference::ticket($order, 3));
        $this->assertMatchesRegularExpression('/^PO-[0-9A-Z]{4}-[0-9A-Z]{4}$/', Reference::payout());
    }
}
