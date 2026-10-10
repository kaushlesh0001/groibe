<?php

use App\Http\Controllers\Public\ContributorDirectoryController;
use App\Http\Controllers\Public\SitemapController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::get('sitemap.xml', [SitemapController::class, 'sitemap'])->name('sitemap');
Route::get('robots.txt', [SitemapController::class, 'robots'])->name('robots');

// Public contributor directory (spec 10.2). Off when the `public_directories` flag is off.
Route::middleware('feature:public_directories')->group(function (): void {
    Route::get('contributors', [ContributorDirectoryController::class, 'index'])->name('contributors.index');
    Route::get('contributors/{slug}', [ContributorDirectoryController::class, 'show'])->name('contributors.show');
    Route::get('contributors/{slug}/photo', [ContributorDirectoryController::class, 'photo'])->name('contributors.photo');
});
