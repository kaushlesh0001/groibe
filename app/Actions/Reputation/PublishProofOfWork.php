<?php

namespace App\Actions\Reputation;

use App\Actions\Reputation\Concerns\MovesProofOfWork;
use App\Enums\ClientDisplay;
use App\Enums\ProofStatus;
use App\Enums\ShowcaseConsent;
use App\Exceptions\ActionBlocked;
use App\Models\ProofOfWork;
use App\Models\User;
use App\Notifications\ProofOfWorkChanged;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * pending_approval -> published, and hidden -> published (spec 9). Publication needs the project's showcase consent
 * to be `anonymous` or `named`. The slug is generated once, on first publication, and never changes (spec 16).
 */
class PublishProofOfWork
{
    use MovesProofOfWork;

    public function __construct(private NotificationDispatcher $dispatcher) {}

    public function __invoke(ProofOfWork $proof, User $actor): ProofOfWork
    {
        Gate::forUser($actor)->authorize('approve', $proof);

        $moved = $this->moveProof(
            $proof,
            ProofStatus::Published,
            $actor,
            attributes: [
                'approved_by' => $actor->getKey(),
                'published_at' => $proof->published_at ?? now(),
                'slug' => $proof->slug ?? $this->uniqueSlug($proof->title),
                'client_display' => $this->displayFor($proof),
            ],
            guard: function (ProofOfWork $locked): void {
                $locked->loadMissing('project');

                if ($locked->project === null || $locked->project->showcase_consent === ShowcaseConsent::None) {
                    throw ActionBlocked::guardFailed('The client has not given showcase consent for this project, so this cannot be published.');
                }
            },
        );

        $this->dispatcher->send($moved->user, new ProofOfWorkChanged($moved, 'published'));

        return $moved;
    }

    private function displayFor(ProofOfWork $proof): ClientDisplay
    {
        $proof->loadMissing('project');

        return $proof->project?->showcase_consent === ShowcaseConsent::Named ? ClientDisplay::Named : ClientDisplay::Anonymous;
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::limit(Str::slug($title) ?: 'proof-of-work', 60, '');

        do {
            $slug = $base.'-'.Str::lower(Str::random(6));
        } while (ProofOfWork::query()->where('slug', $slug)->exists());

        return $slug;
    }
}
