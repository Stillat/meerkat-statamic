<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Forms;

use Statamic\Forms\Submission;

class CommentSubmission extends Submission
{
    public function save(): void {}

    public function saveQuietly(): void
    {

        $this->withEvents = false;
    }

    public function delete(): bool
    {

        return true;
    }

    public function deleteQuietly(): bool
    {
        $this->withEvents = false;

        return true;
    }
}
