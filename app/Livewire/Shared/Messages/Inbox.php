<?php

namespace App\Livewire\Shared\Messages;

use App\Actions\Messages\StartSupportThread;
use App\Exceptions\ActionBlocked;
use App\Models\Thread;
use App\Services\Messaging\ThreadLinks;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/** Messages list for contributors and clients (spec 11, 12, 14): their project conversations and support threads, with unread counts. */
#[Title('Messages')]
class Inbox extends Component
{
    use WithFileUploads, WithPagination;

    public bool $composing = false;

    public string $subject = '';

    public string $body = '';

    /** @var array<int, UploadedFile> */
    public array $uploads = [];

    public function start(): void
    {
        $this->resetErrorBag();

        try {
            $thread = app(StartSupportThread::class)(Auth::user(), $this->subject, $this->body, array_values($this->uploads));
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field === 'subject' ? 'subject' : 'uploads', (string) $messages[0]);
            }

            return;
        } catch (ActionBlocked $e) {
            $this->addError('body', $e->getMessage());

            return;
        } catch (AuthorizationException) {
            $this->addError('body', 'You cannot start a support message.');

            return;
        }

        $this->redirect(app(ThreadLinks::class)->for($thread, Auth::user()), navigate: true);
    }

    public function render()
    {
        $user = Auth::user();

        return view('livewire.shared.messages.inbox', [
            'threads' => Thread::query()
                ->visibleTo($user)
                ->withUnread($user)
                // The time of the latest message this person may see. `threads.last_message_at` also counts internal notes, so
                // using it here would tell a client or contributor that staff just wrote something (SCR-022).
                ->withMax(['messages as last_visible_message_at' => fn (Builder $messages) => $messages->scopes(['visibleTo' => [$user]])], 'created_at')
                ->with('project:id,uuid,code,title')
                ->orderByDesc('last_visible_message_at')
                ->orderByDesc('threads.id')
                ->paginate(15),
            'canStartSupport' => Gate::forUser($user)->allows('startSupport', Thread::class),
            'links' => app(ThreadLinks::class),
            'user' => $user,
            'maxFiles' => (int) config('groibe.messages.max_files'),
            'maxMb' => (int) config('groibe.uploads.document_max_kb') / 1024,
        ]);
    }
}
