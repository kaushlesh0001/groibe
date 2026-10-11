<?php

namespace App\Http\Controllers\Public;

use App\Enums\FaqAudience;
use App\Http\Controllers\Controller;
use App\Models\Faq;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

/** /faq (spec 10.2): every active question, in tabs by audience. A flagged or switched-off question never shows. */
class FaqController extends Controller
{
    public function __invoke(): View
    {
        $all = Faq::query()->active()->ordered()->get()->groupBy(fn (Faq $faq): string => $faq->audience->value);

        $groups = collect(FaqAudience::cases())
            ->mapWithKeys(fn (FaqAudience $audience): array => [$audience->value => $all->get($audience->value, collect())])
            ->filter(fn (Collection $group): bool => $group->isNotEmpty())
            ->all();

        return view('public.faq', ['groups' => $groups]);
    }
}
