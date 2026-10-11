<?php

namespace App\Http\Controllers\Public;

use App\Actions\Website\StoreContactMessage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\ContactMessageRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * /contact (spec 10.2). The form is throttled by route (`throttle:contact`) and guarded by a honeypot. A bot that fills
 * the hidden field gets the same thank-you page and nothing is stored, so it learns nothing.
 */
class ContactController extends Controller
{
    public function show(): View
    {
        return view('public.contact');
    }

    public function store(ContactMessageRequest $request, StoreContactMessage $store): RedirectResponse
    {
        if (! $request->isBot()) {
            $store($request->safe()->only(['name', 'email', 'subject', 'message']), $request->ip(), $request->userAgent());
        }

        return redirect()->route('contact')->with('status', 'Thank you. Your message has been sent and the team will reply by email.');
    }
}
