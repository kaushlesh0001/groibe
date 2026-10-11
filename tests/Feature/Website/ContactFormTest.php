<?php

use App\Enums\AdminRole;
use App\Enums\ContactMessageStatus;
use App\Jobs\SendContactMessageEmail;
use App\Mail\ContactMessageReceived;
use App\Models\ContactMessage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

function contactPayload(array $override = []): array
{
    return ['name' => 'Asha Rao', 'email' => 'asha@example.test', 'subject' => 'A question', 'message' => 'I would like to know more about Groibe.', ...$override];
}

beforeEach(fn () => config(['groibe.contact_email' => 'contact@groibe.test']));

it('shows the form with labels on every field and the honeypot hidden', function () {
    $html = $this->get(route('contact'))->assertOk()->assertSee('Send message')->getContent();

    expect($html)->toContain('for="name"')->toContain('for="email"')->toContain('for="subject"')->toContain('for="message"')
        ->and($html)->toContain('name="company_website"')->toContain('aria-hidden="true"');
});

it('stores the message, emails the team and sends the visitor back with a thank-you', function () {
    Mail::fake();

    $this->from(route('contact'))->post(route('contact.store'), contactPayload())->assertRedirect(route('contact'))->assertSessionHas('status');

    $message = ContactMessage::query()->sole();

    expect($message->name)->toBe('Asha Rao')->and($message->email)->toBe('asha@example.test')->and($message->status)->toBe(ContactMessageStatus::New)
        ->and($message->ip)->toBe('127.0.0.1')->and($message->email_sent_at)->not->toBeNull();

    Mail::assertSent(ContactMessageReceived::class, fn (ContactMessageReceived $mail) => $mail->hasTo('contact@groibe.test') && $mail->hasReplyTo('asha@example.test'));
    $this->get(route('contact'))->assertSee('Thank you');
});

it('queues the email and keeps the message if the email cannot be sent', function () {
    Queue::fake();

    $this->post(route('contact.store'), contactPayload())->assertRedirect();

    Queue::assertPushed(SendContactMessageEmail::class);
    expect(ContactMessage::query()->count())->toBe(1)->and(ContactMessage::query()->sole()->email_sent_at)->toBeNull();
});

it('stores the message and sends nothing when no contact address is configured', function () {
    Mail::fake();
    config(['groibe.contact_email' => null]);

    $this->post(route('contact.store'), contactPayload())->assertRedirect();

    expect(ContactMessage::query()->count())->toBe(1);
    Mail::assertNothingSent();
});

it('sends a message only once even if the job runs twice', function () {
    Mail::fake();
    $message = ContactMessage::factory()->create();

    (new SendContactMessageEmail($message->id))->handle();
    (new SendContactMessageEmail($message->id))->handle();

    Mail::assertSentCount(1);
});

it('gives a bot the same thank-you page and stores nothing', function () {
    Mail::fake();

    $this->post(route('contact.store'), contactPayload(['company_website' => 'https://spam.test']))
        ->assertRedirect(route('contact'))
        ->assertSessionHas('status');

    expect(ContactMessage::query()->count())->toBe(0);
    Mail::assertNothingSent();
});

it('throttles the form per visitor address', function () {
    Mail::fake();

    foreach (range(1, 3) as $i) {
        $this->post(route('contact.store'), contactPayload(['email' => "v{$i}@example.test"]))->assertRedirect();
    }

    $this->post(route('contact.store'), contactPayload(['email' => 'v4@example.test']))->assertTooManyRequests();

    expect(ContactMessage::query()->count())->toBe(3);
});

it('rejects bad input with a message beside the field and keeps what the visitor typed', function (array $override, string $field) {
    $this->from(route('contact'))->post(route('contact.store'), contactPayload($override))->assertRedirect(route('contact'))->assertSessionHasErrors($field);

    expect(ContactMessage::query()->count())->toBe(0);
})->with([
    'no name' => [['name' => ''], 'name'],
    'bad email' => [['email' => 'not-an-email'], 'email'],
    'short message' => [['message' => 'Hi'], 'message'],
    'huge message' => [['message' => str_repeat('a', 4001)], 'message'],
    'line break in the name' => [['name' => "Asha\r\nBcc: victim@example.test"], 'name'],
    'line break in the subject' => [['subject' => "Hello\nBcc: victim@example.test"], 'subject'],
]);

it('renders what the visitor typed as plain text on the form and in Admin', function () {
    $this->from(route('contact'))->post(route('contact.store'), contactPayload(['name' => '<b>Bold</b>', 'email' => 'bad']))->assertSessionHasErrors('email');

    $this->followingRedirects()->get(route('contact'))->assertDontSee('<b>Bold</b>', false);

    $message = ContactMessage::factory()->create(['message' => '<script>alert(1)</script>']);

    $this->actingAs(adminUser(AdminRole::CommunityManager))->get("/admin/contact-messages/{$message->id}")->assertOk()->assertDontSee('<script>alert(1)</script>', false);
});

it('keeps the form open to everyone, signed in or not, and refuses a GET to the store address', function () {
    $this->actingAs(clientUser())->get(route('contact'))->assertOk();
    $this->get('/contact-store')->assertNotFound();
});
