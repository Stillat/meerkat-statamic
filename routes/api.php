<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stillat\Meerkat\Http\Controllers\Api\ThreadController;

// Prefixes and shared middleware are applied when these routes are registered.

Route::middleware('meerkat-api-public')->group(function () {
    Route::get('threads/{threadId}', [ThreadController::class, 'thread'])->name('thread.show');
    Route::get('threads/{threadId}/comments', [ThreadController::class, 'comments'])->name('thread.comments');
    Route::get('threads/{threadId}/roots', [ThreadController::class, 'roots'])->name('thread.roots');
    Route::get('threads/{threadId}/children/{commentId}', [ThreadController::class, 'children'])->where('commentId', '[0-9]{1,18}')->name('thread.children');
    Route::get('threads/{threadId}/stats', [ThreadController::class, 'stats'])->name('thread.stats');

    Route::get('entries/{entryId}/thread', [ThreadController::class, 'entryThread'])->name('entry.thread');
    Route::get('entries/{entryId}/comments', [ThreadController::class, 'entryComments'])->name('entry.comments');
    Route::get('entries/{entryId}/roots', [ThreadController::class, 'entryRoots'])->name('entry.roots');
    Route::get('entries/{entryId}/stats', [ThreadController::class, 'entryStats'])->name('entry.stats');
});

Route::middleware('meerkat-api-privileged')->group(function () {
    Route::get('comments/recent', [ThreadController::class, 'recent'])->name('comments.recent');
    Route::get('comments/{commentId}/history', [ThreadController::class, 'history'])->where('commentId', '[0-9]{1,18}')->name('comments.history');
    Route::get('comments/{commentId}/revisions', [ThreadController::class, 'revisions'])->where('commentId', '[0-9]{1,18}')->name('comments.revisions');
    Route::get('authors/{identifier}/comments', [ThreadController::class, 'authorHistory'])->name('authors.comments');
    Route::get('search', [ThreadController::class, 'search'])->name('search');
});
