<?php

use App\Enums\AdminRole;
use App\Enums\BriefStatus;
use App\Enums\OrgRole;
use App\Enums\ProjectStatus;
use App\Enums\SowStatus;
use App\Enums\UserStatus;
use App\Models\Brief;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Sow;
use App\Models\User;

beforeEach(function () {
    $this->org = Organization::factory()->verified()->create();
    $this->other = Organization::factory()->verified()->create();
    $this->owner = clientUser($this->org, OrgRole::Owner);
    $this->manager = clientUser($this->org, OrgRole::Manager);
    $this->viewer = clientUser($this->org, OrgRole::Viewer);
    $this->outsider = clientUser($this->other, OrgRole::Owner);
});

/** @return array<string, User> */
function admins(): array
{
    return collect(AdminRole::cases())->mapWithKeys(fn (AdminRole $role) => [$role->value => adminUser($role)])->all();
}

describe('Brief policy', function () {
    it('limits client access to the own organization and by org role', function () {
        $brief = Brief::factory()->create(['organization_id' => $this->org->id]);

        expect($this->owner->can('view', $brief))->toBeTrue()
            ->and($this->manager->can('view', $brief))->toBeTrue()
            ->and($this->viewer->can('view', $brief))->toBeTrue()
            ->and($this->outsider->can('view', $brief))->toBeFalse()
            ->and($this->owner->can('update', $brief))->toBeTrue()
            ->and($this->manager->can('update', $brief))->toBeTrue()
            ->and($this->viewer->can('update', $brief))->toBeFalse()
            ->and($this->outsider->can('update', $brief))->toBeFalse()
            ->and($this->owner->can('submit', $brief))->toBeTrue()
            ->and($this->viewer->can('submit', $brief))->toBeFalse()
            ->and($this->outsider->can('submit', $brief))->toBeFalse();
    });

    it('lets owners and managers create briefs, not viewers, contributors or guests-by-role', function () {
        expect($this->owner->can('create', Brief::class))->toBeTrue()
            ->and($this->manager->can('create', Brief::class))->toBeTrue()
            ->and($this->viewer->can('create', Brief::class))->toBeFalse()
            ->and(contributorUser()->can('create', Brief::class))->toBeFalse()
            ->and(User::factory()->client()->create()->can('create', Brief::class))->toBeFalse();
    });

    it('gives contributors nothing on briefs', function () {
        $brief = Brief::factory()->create(['organization_id' => $this->org->id]);
        $contributor = contributorUser();

        foreach (['viewAny', 'view', 'update', 'submit', 'resume', 'convert'] as $ability) {
            expect($contributor->can($ability, $ability === 'viewAny' ? Brief::class : $brief))->toBeFalse();
        }
    });

    it('follows the admin permission matrix', function () {
        $brief = Brief::factory()->status(BriefStatus::InReview)->create(['organization_id' => $this->org->id]);
        $a = admins();

        $matrix = [
            // role => [view, update, markInReview, markScoped, decline, convert]
            'super_admin' => [true, true, true, true, true, true],
            'delivery_manager' => [true, true, true, true, true, true],
            'community_manager' => [true, false, false, false, false, false],
            'finance_ops' => [false, false, false, false, false, false],
        ];

        foreach ($matrix as $role => [$view, $update, $inReview, $scoped, $decline, $convert]) {
            expect($a[$role]->can('view', $brief))->toBe($view, "$role view")
                ->and($a[$role]->can('update', $brief))->toBe($update, "$role update")
                ->and($a[$role]->can('markInReview', $brief))->toBe($inReview, "$role markInReview")
                ->and($a[$role]->can('markScoped', $brief))->toBe($scoped, "$role markScoped")
                ->and($a[$role]->can('decline', $brief))->toBe($decline, "$role decline")
                ->and($a[$role]->can('convert', $brief))->toBe($convert, "$role convert");
        }
    });

    it('denies suspended users everything', function () {
        $brief = Brief::factory()->create(['organization_id' => $this->org->id]);
        $this->owner->forceFill(['status' => UserStatus::Suspended])->save();

        expect($this->owner->fresh()->can('view', $brief))->toBeFalse();
    });
});

describe('Project policy', function () {
    it('shows clients only non-draft projects of their own organization', function (ProjectStatus $status, bool $visible) {
        $project = Project::factory()->status($status)->create(['organization_id' => $this->org->id]);

        expect($this->owner->can('view', $project))->toBe($visible)
            ->and($this->viewer->can('view', $project))->toBe($visible)
            ->and($this->outsider->can('view', $project))->toBeFalse();
    })->with([
        [ProjectStatus::Draft, false],
        [ProjectStatus::SowSent, true],
        [ProjectStatus::SowAccepted, true],
        [ProjectStatus::Active, true],
        [ProjectStatus::Closed, true],
    ]);

    it('never lets a client change a project', function () {
        $project = Project::factory()->status(ProjectStatus::SowSent)->create(['organization_id' => $this->org->id]);

        foreach (['update', 'transition', 'delete'] as $ability) {
            expect($this->owner->can($ability, $project))->toBeFalse();
        }
        expect($this->owner->can('create', Project::class))->toBeFalse();
    });

    it('denies contributors until assignments exist (Pass 4)', function () {
        $project = Project::factory()->status(ProjectStatus::Active)->create(['organization_id' => $this->org->id]);

        expect(contributorUser()->can('view', $project))->toBeFalse()
            ->and(contributorUser()->can('viewAny', Project::class))->toBeFalse();
    });

    it('follows the admin permission matrix', function () {
        $project = Project::factory()->create(['organization_id' => $this->org->id]);
        $a = admins();

        $matrix = [
            // role => [view, create, update, transition, delete]
            'super_admin' => [true, true, true, true, true],
            'delivery_manager' => [true, true, true, true, false],
            'community_manager' => [true, false, false, false, false],
            'finance_ops' => [true, false, false, false, false],
        ];

        foreach ($matrix as $role => [$view, $create, $update, $transition, $delete]) {
            expect($a[$role]->can('view', $project))->toBe($view, "$role view")
                ->and($a[$role]->can('create', Project::class))->toBe($create, "$role create")
                ->and($a[$role]->can('update', $project))->toBe($update, "$role update")
                ->and($a[$role]->can('transition', $project))->toBe($transition, "$role transition")
                ->and($a[$role]->can('delete', $project))->toBe($delete, "$role delete");
        }
    });
});

describe('SOW policy', function () {
    it('hides drafts from clients and shows every sent, accepted and superseded version', function (SowStatus $status, bool $visible) {
        $project = Project::factory()->status(ProjectStatus::SowSent)->create(['organization_id' => $this->org->id]);
        $sow = Sow::factory()->status($status)->create(['project_id' => $project->id]);

        expect($this->owner->can('view', $sow))->toBe($visible)
            ->and($this->viewer->can('view', $sow))->toBe($visible)
            ->and($this->outsider->can('view', $sow))->toBeFalse();
    })->with([
        [SowStatus::Draft, false],
        [SowStatus::Sent, true],
        [SowStatus::Accepted, true],
        [SowStatus::Superseded, true],
    ]);

    it('lets only the owner accept, and never another organization', function () {
        $project = Project::factory()->status(ProjectStatus::SowSent)->create(['organization_id' => $this->org->id]);
        $sow = Sow::factory()->status(SowStatus::Sent)->create(['project_id' => $project->id]);

        expect($this->owner->can('accept', $sow))->toBeTrue()
            ->and($this->manager->can('accept', $sow))->toBeFalse()
            ->and($this->viewer->can('accept', $sow))->toBeFalse()
            ->and($this->outsider->can('accept', $sow))->toBeFalse()
            ->and(adminUser()->can('accept', $sow))->toBeFalse()
            ->and(contributorUser()->can('accept', $sow))->toBeFalse();
    });

    it('never lets a client create, edit or send a SOW', function () {
        $project = Project::factory()->status(ProjectStatus::SowSent)->create(['organization_id' => $this->org->id]);
        $sow = Sow::factory()->status(SowStatus::Sent)->create(['project_id' => $project->id]);

        foreach (['update', 'send', 'delete'] as $ability) {
            expect($this->owner->can($ability, $sow))->toBeFalse();
        }
        expect($this->owner->can('create', Sow::class))->toBeFalse();
    });

    it('follows the admin permission matrix', function () {
        $project = Project::factory()->create(['organization_id' => $this->org->id]);
        $sow = Sow::factory()->create(['project_id' => $project->id]);
        $a = admins();

        $matrix = [
            // role => [view, create, update, send]
            'super_admin' => [true, true, true, true],
            'delivery_manager' => [true, true, true, true],
            'community_manager' => [false, false, false, false],
            'finance_ops' => [true, false, false, false],
        ];

        foreach ($matrix as $role => [$view, $create, $update, $send]) {
            expect($a[$role]->can('view', $sow))->toBe($view, "$role view")
                ->and($a[$role]->can('create', Sow::class))->toBe($create, "$role create")
                ->and($a[$role]->can('update', $sow))->toBe($update, "$role update")
                ->and($a[$role]->can('send', $sow))->toBe($send, "$role send");
        }
    });
});
