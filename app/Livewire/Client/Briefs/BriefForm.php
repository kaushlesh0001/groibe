<?php

namespace App\Livewire\Client\Briefs;

use App\Actions\Briefs\ResumeBrief;
use App\Actions\Briefs\SaveBriefDraft;
use App\Actions\Briefs\SubmitBrief;
use App\Actions\Files\AttachPrivateFile;
use App\Actions\Files\DeletePrivateFile;
use App\Enums\BriefStatus;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Livewire\Forms\BriefDetailsForm;
use App\Models\Brief;
use App\Models\ProjectType;
use App\Models\Skill;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/** Guided multi-step brief form (spec 12). Persists after every step so a client can leave and return. */
#[Title('Brief')]
class BriefForm extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?Brief $brief = null;

    public BriefDetailsForm $form;

    public int $step = 1;

    /** @var array<int, mixed> */
    public array $uploads = [];

    public function mount(?Brief $brief = null): void
    {
        if ($brief === null) {
            Gate::authorize('create', Brief::class);

            return;
        }

        Gate::authorize('update', $brief);

        $this->brief = $brief;
        $this->form->fillFrom($brief->load('skills:id'));
    }

    public function next(): void
    {
        $this->resetErrorBag();
        $this->validateStep();
        $this->persist();

        $this->step = min($this->step + 1, BriefDetailsForm::STEPS);
    }

    public function back(): void
    {
        $this->step = max($this->step - 1, 1);
    }

    public function saveAndExit(): void
    {
        $this->form->validate(['title' => ['required', 'string', 'max:255']]);
        $this->persist();

        $this->redirectRoute('client.briefs.show', $this->brief, navigate: true);
    }

    public function removeFile(int $mediaId): void
    {
        abort_if($this->brief === null, 404);

        $media = Media::query()->findOrFail($mediaId);
        app(DeletePrivateFile::class)($this->brief, $media, Auth::user());
    }

    /** Final step: save, then submit a draft or send answers back on a needs_info brief. */
    public function submit(): void
    {
        $this->form->validate($this->form->allRules());
        $this->validateUploads();

        try {
            $this->persist();

            $brief = $this->brief;
            $actor = Auth::user();

            if ($brief->status === BriefStatus::NeedsInfo) {
                app(ResumeBrief::class)($brief, $actor);
            } else {
                app(SubmitBrief::class)($brief, $actor);
            }
        } catch (ActionBlocked|IllegalTransition $e) {
            $this->addError('submit', $e->getMessage());

            return;
        }

        session()->flash('status', 'Thank you. Your brief has been sent to the Groibe team.');
        $this->redirectRoute('client.briefs.show', $this->brief, navigate: true);
    }

    public function render()
    {
        $files = $this->brief?->getMedia(Brief::FILES)->map(fn (Media $media): array => [
            'id' => $media->id,
            'name' => $media->name.'.'.$media->extension,
            'size' => $media->human_readable_size,
            'url' => URL::temporarySignedRoute('files.show', now()->addMinutes((int) config('groibe.uploads.signed_url_minutes')), ['media' => $media->uuid]),
        ]) ?? collect();

        return view('livewire.client.briefs.brief-form', [
            'projectTypes' => ProjectType::query()->active()->orderBy('name')->get(),
            'skillGroups' => Skill::query()->active()->with('category:id,name')->orderBy('name')->get()->groupBy(fn (Skill $skill): string => $skill->category->name ?? 'Other'),
            'budgetBands' => config('groibe.briefs.budget_bands'),
            'timelineBands' => config('groibe.briefs.timeline_bands'),
            'files' => $files,
            'isResponse' => $this->brief?->status === BriefStatus::NeedsInfo,
            'question' => $this->brief?->status === BriefStatus::NeedsInfo ? $this->brief->needs_info_note : null,
            'msaUrl' => route('client.agreements.msa'),
        ]);
    }

    private function validateStep(): void
    {
        $this->form->validate($this->form->rulesForStep($this->step));

        if ($this->step === 4) {
            $this->validateUploads();
        }
    }

    private function validateUploads(): void
    {
        $extensions = implode(',', config('groibe.uploads.document_extensions'));

        try {
            $this->validate([
                'uploads' => ['array', 'max:10'],
                'uploads.*' => ['file', 'max:'.config('groibe.uploads.document_max_kb'), 'extensions:'.$extensions, 'mimes:'.$extensions],
            ]);
        } catch (ValidationException $e) {
            // Clear the rejected selection so the client can choose again.
            $this->uploads = [];

            throw $e;
        }
    }

    private function persist(): void
    {
        $actor = Auth::user();

        $this->brief = app(SaveBriefDraft::class)($actor, $this->brief, $this->form->payload(), $this->form->skillIds());

        try {
            foreach ($this->uploads as $upload) {
                app(AttachPrivateFile::class)($this->brief, $upload, $actor);
            }
        } catch (ValidationException $e) {
            $this->addError('uploads', collect($e->errors())->flatten()->first());

            return;
        }

        $this->uploads = [];
    }
}
