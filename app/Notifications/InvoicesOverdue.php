<?php

namespace App\Notifications;

use App\Filament\Admin\Resources\InvoiceRefs\InvoiceRefResource;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Daily digest to finance of invoices that have just become overdue (SCR-024). It lists project codes and Zoho invoice
 * numbers only, no amounts.
 */
class InvoicesOverdue extends GroibeNotification
{
    /** @param  list<array{project: string, ref: string, due: string}>  $invoices */
    public function __construct(public array $invoices) {}

    public function category(): string
    {
        return 'payout';
    }

    public function headline(): string
    {
        $count = count($this->invoices);

        return $count === 1 ? '1 invoice is now overdue' : "{$count} invoices are now overdue";
    }

    public function url(): string
    {
        return InvoiceRefResource::getUrl('index');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'category' => $this->category(),
            'title' => $this->headline(),
            'body' => collect($this->invoices)->take(5)->map(fn (array $i): string => "{$i['project']} {$i['ref']}")->implode(', '),
            'url' => $this->url(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->headline())
            ->markdown('emails.invoices-overdue', [
                'name' => $notifiable->name,
                'headline' => $this->headline(),
                'invoices' => $this->invoices,
                'url' => $this->url(),
            ]);
    }
}
