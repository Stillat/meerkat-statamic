<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Statamic\Events\FormSubmitted;
use Statamic\Events\SubmissionCreated;
use Statamic\Exceptions\SilentFormFailureException;
use Statamic\Facades\Entry;
use Statamic\Facades\URL;
use Statamic\Facades\User as UserApi;
use Statamic\Hooks\Payload;
use Statamic\Support\Traits\Hookable;
use Stillat\Meerkat\Concerns\CleansCommentData;
use Stillat\Meerkat\Concerns\ExtractsFields;
use Stillat\Meerkat\Concerns\GetsMeerkatConfig;
use Stillat\Meerkat\Concerns\GetsMeerkatPermissions;
use Stillat\Meerkat\Contracts\CommentRepository;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Database\Models\Thread;
use Stillat\Meerkat\Events\CommentSubmitted;
use Stillat\Meerkat\Forms\StatamicFormBridge;
use Stillat\Meerkat\Hooks\CommentSpamCheck;
use Stillat\Meerkat\Rules\ReplyDepthLimit;
use Stillat\Meerkat\Rules\ThreadExistsRule;
use Stillat\Meerkat\Rules\ValidParentRule;
use Stillat\Meerkat\Services\SubmissionRateLimiter;
use Stillat\Meerkat\Services\ThreadResolver;
use Stillat\Meerkat\Support\ContextSigner;
use Stillat\Meerkat\Support\Identifiers;
use Throwable;

class CommentController
{
    use CleansCommentData,
        ExtractsFields,
        GetsMeerkatConfig,
        GetsMeerkatPermissions,
        Hookable;

    public function __construct(
        private CommentRepository $commentRepository,
        private readonly SubmissionRateLimiter $rateLimiter,
        private readonly ThreadResolver $threads,
        private readonly StatamicFormBridge $statamicForm
    ) {}

    protected function honeypot(): string
    {
        $honeypot = config('meerkat.form.honeypot', 'username');

        return is_string($honeypot) ? $honeypot : 'username';
    }

    public function createComment(): RedirectResponse|Response
    {
        $blueprint = $this->getBlueprint();

        $requestValues = $this->stringKeyedArray(request()->all());
        $authUser = UserApi::current();

        if ($authUser !== null) {
            $requestValues['name'] = $authUser->name() ?: $authUser->email() ?: $authUser->id();
            $requestValues['email'] = $authUser->email();
        }

        $fields = $blueprint
            ->fields()
            ->addValues($requestValues);

        $params = $this->stringKeyedArray(
            collect(request()->all())->filter(fn ($value, $key) => Str::startsWith($key, '_'))->all()
        );

        $commentData = [];

        try {
            $honeypot = $this->honeypot();
            if ($honeypot !== '' && $honeypot !== '0') {
                throw_if(Arr::get($requestValues, $honeypot), new SilentFormFailureException);
            }

            $context = request('_meerkat_context');
            $signature = request('_meerkat_context_signature');

            if (! is_string($context)) {
                throw new SilentFormFailureException;
            }

            if (config('meerkat.publishing.require_signed_context', true)
                && ! ContextSigner::verify(
                    $context,
                    is_string($signature) ? $signature : null
                )) {
                throw new SilentFormFailureException;
            }

            $parentComment = null;
            $parentId = request('ids');

            if (is_string($parentId) || is_int($parentId)) {
                $parentComment = Comment::query()->find($parentId);
            }

            request()->validate([
                '_meerkat_context' => [
                    new ThreadExistsRule,
                ],
            ]);

            [$entry, $threadId] = $this->resolveContextEntry($context);

            if (! $entry || $threadId === null) {
                abort(404);
            }

            $this->commentRepository->ensureThreadExists($entry);

            if (request()->has('ids')) {
                validator(
                    ['ids' => request('ids')],
                    ['ids' => [
                        'sometimes',
                        new ValidParentRule($threadId, $parentComment),
                        new ReplyDepthLimit($parentComment),
                    ]]
                )->validate();
            }

            $fields->validate();

            if (! $this->commentRepository->areCommentsEnabledForEntry($entry)) {
                return $this->formFailure($params, [
                    'comment' => [__('meerkat::validation.comments_closed')],
                ]);
            }

            $values = $fields->process()->values()->all();

            $newDepth = 0;

            if ($parentComment != null) {
                $newDepth = $parentComment->depth + 1;
            }

            if ($authUser === null && $this->onlyAcceptCommentsFromAuthenticatedUsers()) {
                return $this->formFailure($params, []);
            }

            if ($authUser !== null) {
                abort_unless($authUser->can('submit comments') === true, 403);

                if ($parentComment != null) {
                    $this->assertCanAccessComment($parentComment);
                }

                $this->ensureAuthorMetaDataExists($authUser);

                $values['name'] = $authUser->name() ?: $authUser->email() ?: $authUser->id();
                $values['email'] = $authUser->email();
            }

            $authorDetails = $authUser !== null
                ? $this->authorDetailsFromUser($authUser)
                : $this->extractAuthor($values);

            if (! $this->rateLimiter->ensureNotLimited(
                $threadId,
                $authorDetails['email'] ?? null,
                request()->ip() ?? 'unknown'
            )) {
                return $this->formFailure($params, [
                    'comment' => [__('meerkat::validation.rate_limited')],
                ]);
            }

            $this->rateLimiter->hit(
                $threadId,
                $authorDetails['email'] ?? null,
                request()->ip() ?? 'unknown'
            );

            $comment = new Comment;
            $comment->thread_id = $threadId;
            $comment->comment_data = $this->cleanCommentData($values);
            $comment->is_published = $this->shouldAutoPublish($authUser !== null);
            $comment->checked_for_spam = false;
            $comment->is_spam = false;
            $comment->is_ham = false;
            $comment->comment_text = $this->extractComment($values)['comment'];
            $comment->parent_id = $parentComment?->id;
            $comment->depth = $newDepth;
            $comment->collection = $this->entryHandle($entry->collection(), 'collection');
            $comment->site = $this->entryHandle($entry->site(), 'site');
            $comment->author_id = $authUser === null ? null : $this->scalarString($authUser->id());

            $comment->user_ip = config('meerkat.privacy.store_user_ip', true)
                ? request()->ip()
                : null;
            $comment->user_agent = config('meerkat.privacy.store_user_agent', true)
                ? $this->truncatedRequestString(request()->userAgent())
                : null;
            $comment->referer = config('meerkat.privacy.store_referrer', true)
                ? $this->truncatedRequestString(request()->header('referer'))
                : null;

            if ($authUser === null) {
                $comment->author_name = $authorDetails['name'] ?? null;
                $comment->author_email = $authorDetails['email'] ?? null;
            }

            $comment->path = $comment->visual_path = null;

            if ($this->autoCheckForSpam()) {
                $shouldIgnore = false;

                try {
                    $spam = app(CommentSpamCheck::class)->resolve($entry, $comment);
                    $entry = $spam['entry'];
                    $comment = $spam['comment'];
                    $isSpam = $spam['is_spam'];

                    $comment->is_spam = $isSpam;
                    $comment->checked_for_spam = true;

                    $shouldIgnore = $this->autoDeleteSpam() && $isSpam;

                    if ($isSpam && $this->autoUnpublishSpamComments()) {
                        $comment->is_published = false;
                    }

                } catch (Throwable $throwable) {
                    Log::warning('Meerkat: Checking for spam failed.', [
                        'exception' => $throwable->getMessage(),
                        'comment_id' => $comment->id,
                        'thread_id' => $comment->thread_id,
                    ]);

                    if ($this->unpublishOnGuardFailure()) {
                        $comment->is_published = false;
                    }
                }

                if ($shouldIgnore) {
                    throw new SilentFormFailureException;
                }
            }

            $hookedComment = $this->runHooks('creatingComment', $comment);

            if ($hookedComment instanceof Comment) {
                $comment = $hookedComment;
            }

            $statamicSubmission = $this->statamicForm->makeSubmission(
                processedValues: $values,
                rawRequest: $this->stringKeyedArray(request()->all()),
            );

            $preDispatchData = $this->stringKeyedArray($statamicSubmission->data()->all());

            throw_if(FormSubmitted::dispatch($statamicSubmission) === false, new SilentFormFailureException);

            $this->applySubmissionMutations(
                $comment,
                $preDispatchData,
                $this->stringKeyedArray($statamicSubmission->data()->all())
            );

            throw_if(CommentSubmitted::dispatch($comment) === false, new SilentFormFailureException);
        } catch (ValidationException $e) {
            return $this->formFailure($params, $this->validationErrors($e->errors()));
        } catch (SilentFormFailureException) {
            return $this->formSuccess($params, $commentData, true);
        }

        $payload = $this->runHooksWith('before-saving-comment', [
            'comment' => $comment,
            'entry' => $entry,
            'parent_comment' => $parentComment,
            'values' => $values,
            'authenticated_user' => $authUser,
        ]);

        $hookedComment = $payload instanceof Payload ? $payload->comment : null;

        if ($hookedComment instanceof Comment) {
            $comment = $hookedComment;
        }

        if (! $comment->save()) {
            return $this->formFailure($params, []);
        }

        $visualId = Identifiers::visualId($comment->id);
        $comment->materializePath($parentComment);

        // Persist the path directly: the id is only known after the insert, and
        // the mirror file location does not depend on these columns.
        Comment::query()->whereKey($comment->id)->update([
            'path' => $comment->path,
            'visual_path' => $comment->visual_path,
        ]);

        $this->runHooksWith('after-saved-comment', [
            'comment' => $comment,
            'entry' => $entry,
            'is_new' => true,
            'visual_id' => $visualId,
        ]);

        SubmissionCreated::dispatch($statamicSubmission);

        return $this->formSuccess($params, $commentData, false, $comment->id);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function applySubmissionMutations(Comment $comment, array $before, array $after): void
    {
        foreach ($after as $key => $value) {
            $touched = ! array_key_exists($key, $before) || $before[$key] !== $value;

            if (! $touched) {
                continue;
            }

            match ($key) {
                'comment' => $comment->comment_text = $this->scalarString($value) ?? '',
                'name' => $comment->author_name = $this->scalarString($value),
                'email' => $comment->author_email = $this->scalarString($value),
                default => $comment->comment_data = array_merge(
                    (array) $comment->comment_data,
                    [$key => $value],
                ),
            };
        }
    }

    /**
     * @return array{0: ?\Statamic\Contracts\Entries\Entry, 1: ?string}
     */
    private function resolveContextEntry(string $context): array
    {
        if ($entry = Entry::find($context)) {
            return [$entry, $this->threads->forEntry($entry)];
        }

        $thread = Thread::query()
            ->where('thread_id', $context)
            ->first();

        if ($thread?->entry_id && ($entry = Entry::find($thread->entry_id))) {
            return [$entry, $context];
        }

        return [null, null];
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, list<string>>  $errors
     */
    private function formFailure(array $params, array $errors): RedirectResponse|Response
    {
        $request = request();

        if ($request->ajax()) {
            return response([
                'errors' => (new MessageBag($errors))->all(),
                'error' => collect($errors)->map(fn (array $fieldErrors): string => $fieldErrors[0])->all(),
            ], 400);
        }

        if ($request->isPrecognitive() || $request->wantsJson()) {
            throw ValidationException::withMessages($errors);
        }

        $redirect = $this->safeRedirectTarget(Arr::get($params, '_error_redirect'));

        $response = $redirect ? redirect($redirect) : back();

        $urlHash = $this->getHashSuffix();
        $currentTargetUrl = $response->getTargetUrl().$urlHash;

        $response->setTargetUrl($currentTargetUrl);

        return $response->withInput()->withErrors($errors, 'meerkat');
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $comment
     */
    private function formSuccess(
        array $params,
        array $comment,
        bool $silentFailure = false,
        ?int $commentId = null,
    ): RedirectResponse|Response {
        $redirect = $this->safeRedirectTarget(Arr::get($params, '_redirect'));

        if (request()->ajax() || request()->wantsJson()) {
            return response([
                'success' => true,
                'comment_created' => ! $silentFailure,
                'comment' => $comment,
                'comment_id' => $commentId,
                'redirect' => $redirect,
            ]);
        }

        $response = $redirect ? redirect($redirect) : back();

        if ($redirect === null || ! URL::isExternal($redirect)) {
            session()->flash('meerkat.success', __('meerkat::general.comment_submitted_successfully'));
            session()->flash('meerkat.submission_created', ! $silentFailure);
            session()->flash('comment', $comment);
        }

        $urlHash = $this->getHashSuffix($commentId);
        $currentTargetUrl = $response->getTargetUrl().$urlHash;

        $response->setTargetUrl($currentTargetUrl);

        return $response;
    }

    private function getHashSuffix(?int $id = null): string
    {
        $suffix = '#comments';

        if (request()->has('meerkat_jump')) {
            $requestedSuffix = request('meerkat_jump');
            $suffix = is_string($requestedSuffix) ? $requestedSuffix : '#comments';

            if (Str::startsWith($suffix, 'to:')) {
                $suffix = mb_substr($suffix, 3);
            } elseif (Str::startsWith($suffix, 'comment:id') && $id != null) {
                $suffix = '#comment-'.$id;
            } elseif (Str::startsWith($suffix, 'comment:id|') && $id == null) {
                $suffix = mb_substr($suffix, 11);
            }
        }

        return $this->safeHashSuffix($suffix) ?? '#comments';
    }

    private function safeRedirectTarget(mixed $target): ?string
    {
        if (! is_string($target)) {
            return null;
        }

        $target = trim($target);

        if ($target === '' || URL::isExternal($target) || Str::startsWith($target, ['//', '\\\\'])) {
            return null;
        }

        return $target;
    }

    private function safeHashSuffix(string $suffix): ?string
    {
        $suffix = trim($suffix);

        if ($suffix === '') {
            return null;
        }

        if (! Str::startsWith($suffix, '#')) {
            $suffix = '#'.$suffix;
        }

        return preg_match('/^#[A-Za-z0-9][A-Za-z0-9_.:-]*$/', $suffix) === 1
            ? $suffix
            : null;
    }

    private function truncatedRequestString(mixed $value): ?string
    {
        $value = $this->scalarString($value);

        return $value === null ? null : (mb_substr($value, 0, 1024) ?: null);
    }

    private function scalarString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        return is_int($value) || is_float($value) || is_bool($value) ? (string) $value : null;
    }

    /** @return array<string, mixed> */
    private function stringKeyedArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    /** @return array<string, list<string>> */
    private function validationErrors(mixed $value): array
    {
        $errors = [];

        foreach ($this->stringKeyedArray($value) as $key => $messages) {
            if (is_array($messages)) {
                $errors[$key] = array_values(array_filter($messages, is_string(...)));
            }
        }

        return $errors;
    }

    private function entryHandle(mixed $value, string $type): string
    {
        if (! is_object($value) || ! method_exists($value, 'handle')) {
            throw new \UnexpectedValueException("The comment entry has no {$type}.");
        }

        $handle = $value->handle();

        if (! is_string($handle) || $handle === '') {
            throw new \UnexpectedValueException("The comment entry {$type} has no valid handle.");
        }

        return $handle;
    }
}
