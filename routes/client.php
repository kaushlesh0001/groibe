<?php

use App\Http\Controllers\Client\SowDocumentController;
use App\Http\Controllers\Shared\OpenProjectThreadController;
use App\Livewire\Client\Agreements\AcceptMsa;
use App\Livewire\Client\Briefs\BriefForm;
use App\Livewire\Client\Briefs\BriefIndex;
use App\Livewire\Client\Briefs\BriefShow;
use App\Livewire\Client\Dashboard;
use App\Livewire\Client\Deliverables\DeliverableIndex;
use App\Livewire\Client\Projects\ProjectIndex;
use App\Livewire\Client\Projects\ProjectReviews;
use App\Livewire\Client\Projects\ProjectShow;
use App\Livewire\Shared\Messages\Conversation;
use App\Livewire\Shared\Messages\Inbox;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'role:client'])
    ->prefix('app/client')
    ->name('client.')
    ->group(function (): void {
        Route::livewire('/', Dashboard::class)->name('dashboard');

        Route::livewire('agreements/client-msa', AcceptMsa::class)->name('agreements.msa');

        Route::livewire('briefs', BriefIndex::class)->name('briefs.index');
        Route::livewire('briefs/create', BriefForm::class)->name('briefs.create');
        Route::livewire('briefs/{brief}/edit', BriefForm::class)->name('briefs.edit');
        Route::livewire('briefs/{brief}', BriefShow::class)->name('briefs.show');

        Route::livewire('deliverables', DeliverableIndex::class)->name('deliverables');

        Route::livewire('projects', ProjectIndex::class)->name('projects.index');
        Route::livewire('projects/{project}', ProjectShow::class)->name('projects.show');
        Route::livewire('projects/{project}/reviews', ProjectReviews::class)->name('projects.reviews');
        Route::get('projects/{project}/sows/{version}/print', [SowDocumentController::class, 'print'])
            ->whereNumber('version')->name('projects.sow.print');
        Route::get('projects/{project}/sows/{version}/pdf', [SowDocumentController::class, 'pdf'])
            ->whereNumber('version')->name('projects.sow.pdf');
    });

// Messages (spec 12, 14). Gone while `messaging` is off. Membership is decided by the thread policy.
Route::middleware(['auth', 'verified', 'role:client', 'feature:messaging'])
    ->prefix('app/client/messages')
    ->name('client.messages.')
    ->group(function (): void {
        Route::livewire('/', Inbox::class)->name('index');
        Route::get('project/{project}', OpenProjectThreadController::class)->name('project');
        Route::livewire('{thread}', Conversation::class)->name('show');
    });
