<?php

namespace App\Mail;

use App\Models\ContactMessage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The email the Groibe team receives for a contact form message. Replying goes straight to the visitor. */
class ContactMessageReceived extends Mailable
{
    public function __construct(public ContactMessage $contact) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->contact->email, $this->contact->name)],
            subject: 'Contact form: '.($this->contact->subject ?: 'new message'),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.contact-message');
    }
}
