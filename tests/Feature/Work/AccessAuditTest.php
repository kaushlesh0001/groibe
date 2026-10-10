<?php

use App\Enums\AdminRole;
use App\Enums\MilestoneStatus;
use App\Enums\OnboardingState;
use App\Enums\OrgRole;
use App\Enums\ProjectStatus;
use App\Models\Assignment;
use App\Models\Deliverable;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->dm = adminUser(AdminRole::DeliveryManager);
    $this->project = Project::factory()->status(ProjectStatus::Active)->create(['delivery_manager_id' => $this->dm->id]);
    $this->owner = clientUser($this->project->organization, OrgRole::Owner);
    $this->viewer = clientUser($this->project->organization, OrgRole::Viewer);
    $this->stranger = clientUser();
    $this->me = contributorIn(OnboardingState::Active);
    $this->seat = Assignment::factory()->active()->create(['project_id' => $this->project->id, 'user_id' => $this->me->id]);
    $this->milestone = Milestone::factory()->create(['project_id' => $this->project->id]);
    $this->task = Task::factory()->create(['milestone_id' => $this->milestone->id, 'assignment_id' => $this->seat->id]);
});

describe('task comments', function () {
    it('shows internal comments to the delivery team only, and public ones to the contributor who holds the task', function () {
        $public = TaskComment::factory()->create(['task_id' => $this->task->id]);
        $internal = TaskComment::factory()->internal()->create(['task_id' => $this->task->id]);
        $otherContributor = contributorIn(OnboardingState::Active);
        $finance = adminUser(AdminRole::FinanceOps);
        $community = adminUser(AdminRole::CommunityManager);

        foreach ([$public, $internal] as $comment) {
            expect(Gate::forUser($this->dm)->allows('view', $comment))->toBeTrue()
                ->and(Gate::forUser(adminUser(AdminRole::SuperAdmin))->allows('view', $comment))->toBeTrue()
                ->and(Gate::forUser($finance)->allows('view', $comment))->toBeFalse()
                ->and(Gate::forUser($community)->allows('view', $comment))->toBeFalse()
                ->and(Gate::forUser($otherContributor)->allows('view', $comment))->toBeFalse()
                ->and(Gate::forUser($this->owner)->allows('view', $comment))->toBeFalse()
                ->and(Gate::forUser($this->viewer)->allows('view', $comment))->toBeFalse()
                ->and(Gate::forUser($this->stranger)->allows('view', $comment))->toBeFalse();
        }

        expect(Gate::forUser($this->me)->allows('view', $public))->toBeTrue()
            ->and(Gate::forUser($this->me)->allows('view', $internal))->toBeFalse();
    });

    it('is never edited or deleted by anyone', function () {
        $comment = TaskComment::factory()->create(['task_id' => $this->task->id, 'user_id' => $this->me->id]);

        foreach ([$this->me, $this->dm, adminUser(AdminRole::SuperAdmin), $this->owner] as $actor) {
            expect(Gate::forUser($actor)->allows('update', $comment))->toBeFalse()
                ->and(Gate::forUser($actor)->allows('delete', $comment))->toBeFalse();
        }
    });

    it('limits who may start a comment', function () {
        expect(Gate::forUser($this->me)->allows('create', TaskComment::class))->toBeTrue()
            ->and(Gate::forUser($this->dm)->allows('create', TaskComment::class))->toBeTrue()
            ->and(Gate::forUser(adminUser(AdminRole::FinanceOps))->allows('create', TaskComment::class))->toBeFalse()
            ->and(Gate::forUser($this->owner)->allows('create', TaskComment::class))->toBeFalse();
    });
});

describe('deliverables', function () {
    it('shows a deliverable to a client only once its milestone is shared, and never to another organization', function () {
        $deliverable = Deliverable::factory()->create(['milestone_id' => $this->milestone->id]);

        foreach ([$this->owner, $this->viewer, $this->stranger, $this->me] as $actor) {
            expect(Gate::forUser($actor)->allows('view', $deliverable))->toBeFalse();
        }

        $this->milestone->forceFill(['status' => MilestoneStatus::Shared])->save();

        expect(Gate::forUser($this->owner)->allows('view', $deliverable->refresh()))->toBeTrue()
            ->and(Gate::forUser($this->viewer)->allows('view', $deliverable))->toBeTrue()
            ->and(Gate::forUser($this->stranger)->allows('view', $deliverable))->toBeFalse()
            ->and(Gate::forUser($this->me)->allows('view', $deliverable))->toBeFalse();
    });

    it('gives admins access by deliverable permission and no client or contributor write access', function () {
        $deliverable = Deliverable::factory()->create(['milestone_id' => $this->milestone->id]);

        expect(Gate::forUser($this->dm)->allows('view', $deliverable))->toBeTrue()
            ->and(Gate::forUser($this->dm)->allows('create', Deliverable::class))->toBeTrue()
            ->and(Gate::forUser($this->dm)->allows('update', $deliverable))->toBeTrue()
            ->and(Gate::forUser($this->dm)->allows('delete', $deliverable))->toBeFalse()
            ->and(Gate::forUser(adminUser(AdminRole::FinanceOps))->allows('view', $deliverable))->toBeFalse()
            ->and(Gate::forUser(adminUser(AdminRole::CommunityManager))->allows('viewAny', Deliverable::class))->toBeFalse();

        foreach ([$this->owner, $this->me] as $actor) {
            expect(Gate::forUser($actor)->allows('create', Deliverable::class))->toBeFalse()
                ->and(Gate::forUser($actor)->allows('update', $deliverable))->toBeFalse();
        }

        expect(Gate::forUser($this->owner)->allows('viewAny', Deliverable::class))->toBeTrue()
            ->and(Gate::forUser($this->me)->allows('viewAny', Deliverable::class))->toBeFalse();
    });
});

describe('route protection', function () {
    it('puts every app route behind sign-in and the right role', function () {
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            $middleware = $route->gatherMiddleware();

            if (str_starts_with($uri, 'app/contributor')) {
                expect(in_array('auth', $middleware, true) && in_array('role:contributor', $middleware, true))->toBeTrue("{$uri} needs auth and role:contributor");
                $checked++;
            }

            if (str_starts_with($uri, 'app/client')) {
                expect(in_array('auth', $middleware, true) && in_array('role:client', $middleware, true))->toBeTrue("{$uri} needs auth and role:client");
                $checked++;
            }
        }

        expect($checked)->toBeGreaterThan(15);
    });

    it('limits every work route to active contributors', function () {
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'app/contributor/work')) {
                expect(in_array('active.contributor', $route->gatherMiddleware(), true))->toBeTrue("{$route->uri()} needs active.contributor");
            }
        }
    });

    it('signs, authenticates and throttles the file route', function () {
        $route = Route::getRoutes()->getByName('files.show');

        expect($route->gatherMiddleware())->toContain('auth')->toContain('signed')->toContain('verified')->toContain('throttle:60,1');
    });
});

describe('admin pages over HTTP', function () {
    it('answers each new admin page by role', function (string $path, array $allowed) {
        $path = str_replace('{project}', (string) $this->project->getRouteKey(), $path);

        $this->get($path)->assertRedirect();

        $roles = [
            'delivery_manager' => $this->dm,
            'super_admin' => adminUser(AdminRole::SuperAdmin),
            'community_manager' => adminUser(AdminRole::CommunityManager),
            'finance_ops' => adminUser(AdminRole::FinanceOps),
            'contributor' => $this->me,
            'client' => $this->owner,
        ];

        foreach ($roles as $name => $user) {
            asUser($user);
            $response = $this->get($path);

            in_array($name, $allowed, true)
                ? $response->assertOk()
                : $response->assertForbidden();
        }
    })->with([
        'QA queue' => ['/admin/qa-queue', ['delivery_manager', 'super_admin']],
        'Deliverables list' => ['/admin/deliverables', ['delivery_manager', 'super_admin']],
        'Project workspace' => ['/admin/projects/{project}/workspace', ['delivery_manager', 'super_admin']],
    ]);
});
