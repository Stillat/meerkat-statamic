<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stillat\Meerkat\Http\Controllers\CP\BlueprintController;
use Stillat\Meerkat\Http\Controllers\CP\CommentActionController;
use Stillat\Meerkat\Http\Controllers\CP\CommentController;
use Stillat\Meerkat\Http\Controllers\CP\DashboardController;

Route::prefix('meerkat')->group(function () {
    Route::get('/', [DashboardController::class, 'show'])->name('meerkat.cp.dashboard');
    Route::get('blueprint', [BlueprintController::class, 'edit'])->name('meerkat.blueprint.edit');
    Route::patch('blueprint', [BlueprintController::class, 'update'])->name('meerkat.blueprint.update');

    Route::prefix('comments')->group(function () {
        Route::get('filter', [CommentController::class, 'filter'])->name('meerkat.cp.comments.index');
        Route::get('export', [CommentController::class, 'exportComments'])->name('meerkat.comments.export');
        Route::get('thread/{threadId}', [CommentController::class, 'threadComments'])->name('meerkat.comments.thread')->where('threadId', '.*');

        Route::post('check-outstanding', [CommentController::class, 'checkOutstandingForSpam'])->name('meerkat.comments.check-outstanding-for-spam');
    });

    Route::post('actions', [CommentActionController::class, 'run'])->middleware('can:view comments')->name('meerkat.comments.actions.run');
    Route::post('actions/list', [CommentActionController::class, 'bulkActions'])->middleware('can:view comments')->name('meerkat.comments.actions.bulk');

    Route::prefix('comment')->group(function () {
        Route::get('reply-data/{parent}', [CommentController::class, 'getReplyData'])->where('parent', '[0-9]{1,18}')->name('meerkat.comment.reply-data');
        Route::post('reply/{parent}', [CommentController::class, 'submitReply'])->where('parent', '[0-9]{1,18}')->name('meerkat.comment.reply');

        Route::get('{id}', [CommentController::class, 'getCommentValues'])->where('id', '[0-9]{1,18}')->name('meerkat.comment.get');
        Route::get('{id}/history', [CommentController::class, 'getCommentHistory'])->where('id', '[0-9]{1,18}')->name('meerkat.comment.history');
        Route::get('{id}/revisions', [CommentController::class, 'getCommentRevisions'])->where('id', '[0-9]{1,18}')->name('meerkat.comment.revisions');
        Route::post('{id}/revisions/{revisionNumber}/restore', [CommentController::class, 'restoreCommentRevision'])->where('id', '[0-9]{1,18}')->where('revisionNumber', '[0-9]{1,9}')->name('meerkat.comment.revision.restore');
        Route::put('{id}', [CommentController::class, 'updateComment'])->where('id', '[0-9]{1,18}')->name('meerkat.comment.update');
    });
});
