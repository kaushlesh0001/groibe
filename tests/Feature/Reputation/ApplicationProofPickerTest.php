<?php

use App\Actions\Applications\SubmitApplication;
use App\Actions\Applications\WithdrawApplication;
use App\Enums\OnboardingState;
use App\Enums\ProofStatus;
use App\Exceptions\ActionBlocked;
use App\Livewire\Contributor\Discover\Show;
use App\Models\Application;
use App\Models\OpenRole;
use App\Models\ProofOfWork;
use Livewire\Livewire;

beforeEach(function () {
    $this->contributor = contributorIn(OnboardingState::Active);
    $this->role = OpenRole::factory()->published()->create();
});

function applyWithProof(OpenRole $role, $user, array $ids): Application
{
    return app(SubmitApplication::class)($role, $user, ['motivation' => 'I can do this well.', 'proof_of_work_ids' => $ids]);
}

it('attaches the applicants own published proof of work', function () {
    $a = ProofOfWork::factory()->published()->create(['user_id' => $this->contributor->id, 'project_id' => null]);
    $b = ProofOfWork::factory()->published()->create(['user_id' => $this->contributor->id, 'project_id' => null]);

    expect(applyWithProof($this->role, $this->contributor, [$a->id, (string) $b->id, $a->id])->proof_of_work_ids)->toBe([$a->id, $b->id]);
});

it('stores nothing when none are picked', function () {
    expect(applyWithProof($this->role, $this->contributor, [])->proof_of_work_ids)->toBeNull();
});

it('refuses another contributors item, a draft, a hidden item and more than five', function () {
    $theirs = ProofOfWork::factory()->published()->create(['user_id' => contributorIn(OnboardingState::Active)->id, 'project_id' => null]);
    $draft = ProofOfWork::factory()->create(['user_id' => $this->contributor->id, 'project_id' => null]);
    $hidden = ProofOfWork::factory()->published()->create(['user_id' => $this->contributor->id, 'project_id' => null, 'status' => ProofStatus::Hidden]);

    foreach ([$theirs, $draft, $hidden] as $item) {
        expect(fn () => applyWithProof($this->role, $this->contributor, [$item->id]))->toThrow(ActionBlocked::class, 'own published');
    }

    expect(fn () => applyWithProof($this->role, $this->contributor, [1, 2, 3, 4, 5, 6]))->toThrow(ActionBlocked::class, 'up to 5')
        ->and(Application::query()->count())->toBe(0);
});

it('replaces the attached items when a withdrawn application is submitted again', function () {
    $a = ProofOfWork::factory()->published()->create(['user_id' => $this->contributor->id, 'project_id' => null]);
    $b = ProofOfWork::factory()->published()->create(['user_id' => $this->contributor->id, 'project_id' => null]);

    $application = applyWithProof($this->role, $this->contributor, [$a->id]);
    app(WithdrawApplication::class)($application, $this->contributor);

    expect(applyWithProof($this->role, $this->contributor, [$b->id])->proof_of_work_ids)->toBe([$b->id]);
});

it('offers only my own published items on the apply form, and sends the picked ones', function () {
    $mine = ProofOfWork::factory()->published()->create(['user_id' => $this->contributor->id, 'project_id' => null, 'title' => 'My published piece']);
    ProofOfWork::factory()->create(['user_id' => $this->contributor->id, 'project_id' => null, 'title' => 'My unfinished draft']);
    ProofOfWork::factory()->published()->create(['user_id' => contributorIn(OnboardingState::Active)->id, 'project_id' => null, 'title' => 'Someone elses piece']);

    Livewire::actingAs($this->contributor)->test(Show::class, ['slug' => $this->role->slug])
        ->assertSee('My published piece')
        ->assertDontSee('My unfinished draft')
        ->assertDontSee('Someone elses piece')
        ->set('form.motivation', 'I have done this kind of work before.')
        ->set('form.proof_of_work_ids', [(string) $mine->id])
        ->call('apply')
        ->assertHasNoErrors();

    expect(Application::query()->firstOrFail()->proof_of_work_ids)->toBe([$mine->id]);
});
