<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stillat\Meerkat\Http\Controllers\AssetController;
use Stillat\Meerkat\Http\Controllers\CommentController;

// Retained for compatibility with existing forms and embeds.

Route::get('!/meerkat/assets/replies.js', [AssetController::class, 'replies'])
    ->name('meerkat.assets.replies');

Route::post('!/meerkat/comments', [CommentController::class, 'createComment'])
    ->middleware('meerkat-form-submit')
    ->name('meerkat.comment-create');
