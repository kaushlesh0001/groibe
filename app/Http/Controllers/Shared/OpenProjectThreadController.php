<?php

namespace App\Http\Controllers\Shared;

use App\Actions\Messages\GetProjectThread;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Messaging\ThreadLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Opens a project's conversation, creating it on first use. Not a member: the same 404 as a project that does not exist. */
class OpenProjectThreadController extends Controller
{
    public function __invoke(Request $request, Project $project): RedirectResponse
    {
        $user = $request->user();

        abort_unless(Gate::forUser($user)->allows('message', $project), 404);

        $thread = app(GetProjectThread::class)($project, $user);

        return redirect(app(ThreadLinks::class)->for($thread, $user));
    }
}
