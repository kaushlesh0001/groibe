<?php

namespace App\Livewire\Contributor\Portfolio;

use App\Actions\Reputation\RequestProofPublication;
use App\Actions\Reputation\UpdateProofOfWork;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Models\ProofOfWork;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Edit one proof-of-work draft and ask for publication (spec 9, 11 Portfolio). */
#[Title('Proof of work')]
class Edit extends Component
{
    private const MAX_METRICS = 5;

    #[Locked]
    public ProofOfWork $proof;

    public string $title = '';

    public string $summary = '';

    public string $contributionText = '';

    /** @var list<array{label: string, value: string}> */
    public array $metrics = [];

    public function mount(ProofOfWork $proof): void
    {
        Gate::authorize('view', $proof);

        $this->proof = $proof;
        $this->title = $proof->title;
        $this->summary = (string) $proof->summary;
        $this->contributionText = (string) $proof->contribution_text;
        $this->metrics = collect($proof->outcome_metrics ?? [])
            ->map(fn (array $row): array => ['label' => (string) ($row['label'] ?? ''), 'value' => (string) ($row['value'] ?? '')])
            ->all();
    }

    public function addMetric(): void
    {
        if (count($this->metrics) < self::MAX_METRICS) {
            $this->metrics[] = ['label' => '', 'value' => ''];
        }
    }

    public function removeMetric(int $index): void
    {
        unset($this->metrics[$index]);
        $this->metrics = array_values($this->metrics);
    }

    public function save(): void
    {
        $this->persist();
        session()->flash('status', 'Saved.');
    }

    public function requestPublication(): void
    {
        if (! $this->persist()) {
            return;
        }

        try {
            app(RequestProofPublication::class)($this->proof->refresh(), Auth::user());
        } catch (ActionBlocked|IllegalTransition $e) {
            $this->addError('publication', $e->getMessage());

            return;
        } catch (AuthorizationException) {
            $this->addError('publication', 'This item can no longer be edited.');

            return;
        }

        session()->flash('status', 'Sent to Groibe for approval.');
        $this->redirectRoute('contributor.portfolio', navigate: true);
    }

    public function render()
    {
        Gate::authorize('view', $this->proof);

        $proof = $this->proof->load('project:id,showcase_consent');

        return view('livewire.contributor.portfolio.edit', [
            'editable' => Gate::allows('edit', $proof),
            'consent' => $proof->project?->showcase_consent,
            'maxMetrics' => self::MAX_METRICS,
        ]);
    }

    private function persist(): bool
    {
        $data = $this->validate([
            'title' => ['required', 'string', 'max:150'],
            'summary' => ['nullable', 'string', 'max:300'],
            'contributionText' => ['nullable', 'string', 'max:3000'],
            'metrics' => ['array', 'max:'.self::MAX_METRICS],
            'metrics.*.label' => ['nullable', 'string', 'max:60'],
            'metrics.*.value' => ['nullable', 'string', 'max:60'],
        ]);

        try {
            app(UpdateProofOfWork::class)($this->proof, Auth::user(), [
                'title' => $data['title'],
                'summary' => $data['summary'] ?? null,
                'contribution_text' => $data['contributionText'] ?? null,
                'outcome_metrics' => collect($data['metrics'])
                    ->map(fn (array $row): array => ['label' => (string) ($row['label'] ?? ''), 'value' => (string) ($row['value'] ?? '')])
                    ->all(),
            ]);
        } catch (AuthorizationException) {
            $this->addError('publication', 'This item can no longer be edited.');

            return false;
        }

        return true;
    }
}
