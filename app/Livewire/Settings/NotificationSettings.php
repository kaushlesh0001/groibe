<?php

namespace App\Livewire\Settings;

use App\Services\Notifications\NotificationPreferences;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Per-category notification choices (spec 14). Security notifications are shown as always on and cannot be saved as off. */
#[Title('Notification preferences')]
class NotificationSettings extends Component
{
    /** @var array<string, array{database: bool, mail: bool}> */
    public array $choices = [];

    public bool $saved = false;

    public function mount(NotificationPreferences $preferences): void
    {
        $this->choices = $preferences->all(Auth::user());
    }

    public function save(NotificationPreferences $preferences): void
    {
        foreach ($preferences->categories() as $category) {
            if ($preferences->isLocked($category)) {
                continue;
            }

            // Only known categories are read, and only booleans are kept, whatever the browser sent.
            $preferences->set(
                Auth::user(),
                $category,
                (bool) ($this->choices[$category]['database'] ?? true),
                (bool) ($this->choices[$category]['mail'] ?? true),
            );
        }

        $this->choices = $preferences->all(Auth::user());
        $this->saved = true;
    }

    public function render(NotificationPreferences $preferences)
    {
        return view('livewire.settings.notification-settings', [
            'labels' => config('groibe.notifications.labels'),
            'locked' => array_flip(config('groibe.notifications.locked_categories')),
        ]);
    }
}
