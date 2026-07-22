<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Forms;

use Statamic\Contracts\Forms\Submission;
use Statamic\Forms\Form;

class StatamicFormBridge
{
    public const FORM_HANDLE = 'meerkat';

    /**
     * @var list<string>
     */
    private const INTERNAL_MARKERS = [
        '_meerkat_context',
        '_meerkat_context_signature',
        '_token',
        '_redirect',
        '_error_redirect',
    ];

    /**
     * @param  array<string, mixed>  $processedValues
     * @param  array<string, mixed>  $rawRequest
     */
    public function makeSubmission(array $processedValues, array $rawRequest): Submission
    {
        $title = __('meerkat::general.form_title');
        $form = new Form;
        $form->handle(self::FORM_HANDLE);
        $form->title(is_string($title) ? $title : 'Meerkat');
        $form->store(false);

        $submission = new CommentSubmission;
        $submission->form($form);

        $payload = collect($rawRequest)
            ->except(self::INTERNAL_MARKERS)
            ->merge($processedValues)
            ->all();

        $submission->data($payload);

        return $submission;
    }
}
