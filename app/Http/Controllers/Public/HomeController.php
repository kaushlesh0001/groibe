<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\Website\HomepageBuilder;
use Illuminate\Contracts\View\View;

/** The homepage (spec 10.1): the sections Admin has switched on, in Admin's order. */
class HomeController extends Controller
{
    public function __invoke(HomepageBuilder $homepage): View
    {
        return view('welcome', ['sections' => $homepage->sections()]);
    }
}
