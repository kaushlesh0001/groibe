<?php

namespace App\Livewire\Client\Briefs;

use App\Enums\BriefStatus;
use App\Models\Brief;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Title('Brief')]
class BriefShow extends Component
{
    #[Locked]
    public Brief $brief;

    public function mount(Brief $brief): void
    {
        Gate::authorize('view', $brief);

        $this->brief = $brief;
    }

    public function render()
    {
        $user = Auth::user();
        $brief = $this->brief->load(['projectType:id,name', 'skills:id,name']);

        return view('livewire.client.briefs.brief-show', [
            'brief' => $brief,
            'question' => $brief->status === BriefStatus::NeedsInfo ? $brief->needs_info_note : null,
            'canEdit' => $user->can('update', $brief),
            'budgetLabel' => config('groibe.briefs.budget_bands.'.$brief->budget_band),
            'timelineLabel' => config('groibe.briefs.timeline_bands.'.$brief->timeline_band),
            'files' => $brief->getMedia(Brief::FILES)->map(fn (Media $media): array => [
                'name' => $media->name.'.'.$media->extension,
                'size' => $media->human_readable_size,
                'url' => URL::temporarySignedRoute('files.show', now()->addMinutes((int) config('groibe.uploads.signed_url_minutes')), ['media' => $media->uuid]),
            ]),
        ]);
    }
}
