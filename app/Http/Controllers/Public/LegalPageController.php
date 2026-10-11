<?php

namespace App\Http\Controllers\Public;

use App\Enums\AgreementType;
use App\Http\Controllers\Controller;
use App\Models\AgreementTemplate;
use Illuminate\Contracts\View\View;

/**
 * /terms and /privacy (spec 10.2, 8). The text comes from the active agreement template of that type, never from code.
 * Until the founder publishes real wording in Admin, a missing template or one that still holds the seeded placeholder
 * answers 404, so placeholder text can never reach a visitor.
 */
class LegalPageController extends Controller
{
    public function terms(): View
    {
        return $this->show(AgreementType::Terms, 'terms');
    }

    public function privacy(): View
    {
        return $this->show(AgreementType::Privacy, 'privacy');
    }

    /** The template of this type that may be shown publicly, or null. */
    public static function published(AgreementType $type): ?AgreementTemplate
    {
        $template = AgreementTemplate::currentFor($type);

        return $template !== null && ! $template->isPlaceholder() ? $template : null;
    }

    private function show(AgreementType $type, string $route): View
    {
        $template = self::published($type);

        abort_if($template === null, 404);

        return view('public.legal.show', ['template' => $template, 'route' => $route]);
    }
}
