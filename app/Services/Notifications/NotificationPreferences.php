<?php

namespace App\Services\Notifications;

use App\Models\NotificationPreference;
use App\Models\User;

/**
 * Per-category channel choices (spec 14). No row means database and mail are both on. Locked categories (security)
 * always return both channels, whatever is stored, and cannot be saved as off.
 */
class NotificationPreferences
{
    public const CHANNELS = ['database', 'mail'];

    /** @var array<int, array<string, array{database: bool, mail: bool}>> */
    private array $cache = [];

    /** @return list<string> */
    public function channelsFor(User $user, string $category): array
    {
        if ($this->isLocked($category)) {
            return self::CHANNELS;
        }

        $choice = $this->forUser($user)[$category] ?? ['database' => true, 'mail' => true];

        return array_values(array_filter(self::CHANNELS, fn (string $channel): bool => $choice[$channel]));
    }

    /** @return array<string, array{database: bool, mail: bool}> every known category with its current choice */
    public function all(User $user): array
    {
        $stored = $this->forUser($user);
        $out = [];

        foreach ($this->categories() as $category) {
            $out[$category] = $this->isLocked($category)
                ? ['database' => true, 'mail' => true]
                : ($stored[$category] ?? ['database' => true, 'mail' => true]);
        }

        return $out;
    }

    /** @throws \InvalidArgumentException for an unknown or locked category */
    public function set(User $user, string $category, bool $database, bool $mail): void
    {
        if (! in_array($category, $this->categories(), true)) {
            throw new \InvalidArgumentException("Unknown notification category [{$category}].");
        }

        if ($this->isLocked($category)) {
            throw new \InvalidArgumentException("The [{$category}] notifications cannot be turned off.");
        }

        NotificationPreference::query()->updateOrCreate(
            ['user_id' => $user->getKey(), 'category' => $category],
            ['database' => $database, 'mail' => $mail],
        );

        unset($this->cache[$user->getKey()]);
    }

    public function isLocked(string $category): bool
    {
        return in_array($category, config('groibe.notifications.locked_categories'), true);
    }

    /** @return list<string> */
    public function categories(): array
    {
        return array_values(config('groibe.notifications.categories'));
    }

    /** @return array<string, array{database: bool, mail: bool}> */
    private function forUser(User $user): array
    {
        return $this->cache[$user->getKey()] ??= NotificationPreference::query()
            ->where('user_id', $user->getKey())
            ->get()
            ->mapWithKeys(fn (NotificationPreference $row): array => [$row->category => ['database' => $row->database, 'mail' => $row->mail]])
            ->all();
    }
}
