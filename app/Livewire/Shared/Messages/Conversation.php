<?php

namespace App\Livewire\Shared\Messages;

use App\Actions\Messages\MarkThreadRead;
use App\Actions\Messages\PostMessage;
use App\Exceptions\ActionBlocked;
use App\Models\Thread;
use App\Services\Files\SignedFileUrl;
use App\Services\Messaging\AuthorLabel;
use App\Services\Messaging\ThreadLinks;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * One conversation (spec 14), shared by contributors, clients and the admin panel. Refreshes every 20 seconds (no
 * websockets on shared hosting, spec 17.1). Each render re-checks access, so someone who loses it sees a 404 on the next poll.
 */
#[Title('Conversation')]
class Conversation extends Component
{
    use WithFileUploads;

    #[Locked]
    public Thread $thread;

    public string $body = '';

    /** @var array<int, UploadedFile> */
    public array $uploads = [];

    public bool $internal = false;

    public int $limit = 0;

    public function mount(Thread $thread): void
    {
        // Not yours: the same 404 as a thread that does not exist.
        abort_unless(Gate::forUser(Auth::user())->allows('view', $thread), 404);

        $this->thread = $thread;
        $this->limit = (int) config('groibe.messages.page_size');
    }

    public function showEarlier(): void
    {
        $this->limit += (int) config('groibe.messages.page_size');
    }

    public function send(): void
    {
        $this->resetErrorBag();

        try {
            app(PostMessage::class)($this->thread, Auth::user(), $this->body, array_values($this->uploads), $this->internal);
        } catch (ValidationException $e) {
            $this->addError('uploads', (string) collect($e->errors())->flatten()->first());

            return;
        } catch (ActionBlocked $e) {
            $this->addError('body', $e->getMessage());

            return;
        } catch (AuthorizationException) {
            $this->addError('body', 'You cannot post in this conversation.');

            return;
        }

        $this->reset('body', 'uploads', 'internal');
    }

    public function render()
    {
        $user = Auth::user();
        $gate = Gate::forUser($user);

        abort_unless($gate->allows('view', $this->thread), 404);

        app(MarkThreadRead::class)($this->thread, $user);

        $this->thread->load('project.organization');

        $fetched = $this->thread->messages()
            ->visibleTo($user)
            ->with(['user', 'media'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($this->limit + 1)
            ->get();

        $messages = $fetched->take($this->limit)->reverse()->values();
        $labels = app(AuthorLabel::class);
        $links = app(SignedFileUrl::class);

        return view('livewire.shared.messages.conversation', [
            'messages' => $messages,
            'hasEarlier' => $fetched->count() > $this->limit,
            'labelFor' => fn ($message): string => $labels->for($message, $this->thread, $user),
            'filesFor' => fn ($message): array => $links->describe($message->media),
            'canReply' => $gate->allows('reply', $this->thread),
            'canInternal' => $gate->allows('postInternal', $this->thread),
            'backUrl' => $user->isAdmin() ? null : app(ThreadLinks::class)->inbox($user),
            'pollSeconds' => (int) config('groibe.messages.poll_seconds'),
            'maxFiles' => (int) config('groibe.messages.max_files'),
            'maxMb' => (int) config('groibe.uploads.document_max_kb') / 1024,
            'extensions' => implode(', ', config('groibe.uploads.document_extensions')),
            'maxChars' => (int) config('groibe.messages.max_chars'),
        ]);
    }
}
