<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The throttled exception email (spec 17.4). Built by ExceptionReporter, which decides what it may say. */
class ExceptionOccurred extends Mailable
{
    /** @param  array{class: string, message: string, where: string, request: string, environment: string, time: string}  $report */
    public function __construct(public array $report) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '['.config('app.name').' '.$this->report['environment'].'] '.class_basename($this->report['class']));
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.exception-occurred');
    }
}
