<?php

namespace Tests\Feature\Auth;

use App\Models\Event;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * The whole path a new tester takes: sign up, get the email, click the
 * link, land confirmed, and only then buy tickets.
 */
class VerificationJourneyTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::emailVerification());
        $this->skipUnlessFortifyHas(Features::registration());
    }

    public function test_signing_up_sends_a_branded_link_that_confirms_the_email(): void
    {
        Notification::fake();

        $this->post(route('register.store'), [
            'name' => 'Asha Ramdin',
            'email' => 'asha@example.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $user = User::query()->where('email', 'asha@example.test')->firstOrFail();
        $this->assertFalse($user->hasVerifiedEmail());

        $mail = null;
        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user, &$mail): bool {
            $mail = $notification->toMail($user);

            return true;
        });

        $this->assertInstanceOf(MailMessage::class, $mail);
        $this->assertStringContainsString('Confirm your email', (string) $mail->subject);
        $this->assertSame('Confirm email address', $mail->actionText);

        // Not confirmed yet: the dashboard sends them to the prompt, which names the address.
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('verification.notice'));
        $this->actingAs($user)->get(route('verification.notice'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/VerifyEmail')
                ->where('auth.user.email', 'asha@example.test'));

        // Clicking the emailed link confirms, returns them to the page they
        // first tried (My tickets), and that page says it worked.
        $this->actingAs($user)->get((string) $mail->actionUrl)
            ->assertRedirect(route('dashboard'));

        $this->assertTrue($user->fresh()?->hasVerifiedEmail());

        $this->actingAs($user)->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('emailJustVerified', true));

        // Only once.
        $this->actingAs($user)->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('emailJustVerified', false));
    }

    public function test_unconfirmed_accounts_cannot_buy_tickets_yet(): void
    {
        $event = Event::factory()->forOrganizer($this->organizer())->published()->create();
        $type = TicketType::factory()->create(['event_id' => $event->id]);
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get("/events/{$event->slug}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('auth.user.email_verified_at', null));

        $this->actingAs($user)->post('/checkout', ['event' => $event->public_id, 'items' => [$type->public_id => 1]])
            ->assertRedirect(route('verification.notice'));
    }

    public function test_a_tampered_link_does_not_confirm(): void
    {
        $user = User::factory()->unverified()->create();
        $mail = (new VerifyEmail)->toMail($user);
        $this->assertInstanceOf(MailMessage::class, $mail);

        $this->actingAs($user)->get(str_replace('signature=', 'signature=0', (string) $mail->actionUrl))
            ->assertForbidden();

        $this->assertFalse($user->fresh()?->hasVerifiedEmail());
    }
}
