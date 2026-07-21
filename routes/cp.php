<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stillat\Meerkat\Http\Controllers\CP\CommentActionController;
use Stillat\Meerkat\Http\Controllers\CP\CommentController;
use Stillat\Meerkat\Http\Controllers\CP\DashboardController;

Route::prefix('meerkat')->group(function () {
    Route::get('/', [DashboardController::class, 'show'])->name('meerkat.cp.dashboard');

    Route::prefix('comments')->group(function () {
        Route::get('filter', [CommentController::class, 'filter'])->name('meerkat.cp.comments.index');
        Route::get('export', [CommentController::class, 'exportComments'])->name('meerkat.comments.export');
        Route::get('thread/{threadId}', [CommentController::class, 'threadComments'])->name('meerkat.comments.thread')->where('threadId', '.*');

        Route::post('check-outstanding', [CommentController::class, 'checkOutstandingForSpam'])->name('meerkat.comments.check-outstanding-for-spam');
    });

    Route::post('actions', [CommentActionController::class, 'run'])->middleware('can:view comments')->name('meerkat.comments.actions.run');
    Route::post('actions/list', [CommentActionController::class, 'bulkActions'])->middleware('can:view comments')->name('meerkat.comments.actions.bulk');

    Route::prefix('comment')->group(function () {
        Route::get('reply-data/{parent}', [CommentController::class, 'getReplyData'])->name('meerkat.comment.reply-data');
        Route::post('reply/{parent}', [CommentController::class, 'submitReply'])->name('meerkat.comment.reply');

        Route::get('{id}', [CommentController::class, 'getCommentValues'])->name('meerkat.comment.get');
        Route::get('{id}/history', [CommentController::class, 'getCommentHistory'])->name('meerkat.comment.history');
        Route::get('{id}/revisions', [CommentController::class, 'getCommentRevisions'])->name('meerkat.comment.revisions');
        Route::post('{id}/revisions/{revisionNumber}/restore', [CommentController::class, 'restoreCommentRevision'])->name('meerkat.comment.revision.restore');
        Route::put('{id}', [CommentController::class, 'updateComment'])->name('meerkat.comment.update');
    });
});
