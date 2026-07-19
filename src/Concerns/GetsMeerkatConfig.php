<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Concerns;

use Statamic\Fields\Blueprint;
use Stillat\Meerkat\Blueprints\CommentBlueprint;
use Stillat\Meerkat\Configuration\Settings;

trait GetsMeerkatConfig
{
    protected function getDatabaseConnection(): ?string
    {
        $connection = config('meerkat.database.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    protected function getBlueprintName(): string
    {
        $blueprint = config('meerkat.form.blueprint', 'meerkat');

        return is_string($blueprint) && $blueprint !== '' ? $blueprint : 'meerkat';
    }

    protected function getBlueprint(): Blueprint
    {
        return CommentBlueprint::getBlueprint($this->getBlueprintName());
    }

    protected function unpublishOnGuardFailure(): bool
    {
        return config('meerkat.spam.guard_unpublish_on_guard_failure', false) === true;
    }

    protected function apiEnabled(): bool
    {
        return config('meerkat.api.enabled', true) === true;
    }

    protected function apiPerPage(): int
    {
        return $this->integerConfig('meerkat.api.per_page', 15);
    }

    protected function resolveApiPerPage(?int $requested): int
    {
        $default = $this->apiPerPage();
        $max = $this->integerConfig('meerkat.api.max_per_page', 100);

        if ($requested === null || $requested <= 0) {
            return $default;
        }

        return min($max, $requested);
    }

    protected function commentsCanBeDisabled(): bool
    {
        $window = Settings::get('publishing.automatically_close_comments', 0);

        return (is_int($window) && $window !== 0)
            || (is_string($window) && is_numeric($window) && (int) $window !== 0);
    }

    protected function autoPublishComments(): bool
    {
        return Settings::get('publishing.auto_publish', false) === true;
    }

    protected function onlyAcceptCommentsFromAuthenticatedUsers(): bool
    {
        return Settings::get('publishing.only_accept_comments_from_authenticated_users', false) === true;
    }

    protected function autoPublishCommentsFromAuthenticatedUsers(): bool
    {
        return Settings::get('publishing.auto_publish_authenticated_users', true) === true;
    }

    protected function shouldAutoPublish(bool $isAuthenticated): bool
    {
        if ($isAuthenticated && $this->autoPublishCommentsFromAuthenticatedUsers()) {
            return true;
        }

        return $this->autoPublishComments();
    }

    protected function autoUnpublishSpamComments(): bool
    {
        return Settings::get('spam.auto_unpublish_spam', false) === true;
    }

    protected function autoCheckForSpam(): bool
    {
        return Settings::get('spam.auto_check_spam', true) === true;
    }

    protected function autoDeleteSpam(): bool
    {
        return Settings::get('spam.auto_delete_spam', false) === true;
    }

    protected function submitSpamHamResultsToThirdParties(): bool
    {
        return Settings::get('spam.auto_submit_results', false) === true;
    }

    protected function getDefaultAuthorName(): string
    {
        $name = Settings::get('authors.anonymous_author', 'Anonymous User');

        return is_string($name) ? $name : 'Anonymous User';
    }

    protected function getDefaultAuthorEmail(): string
    {
        $email = Settings::get('authors.anonymous_email', 'no-email@example.org');

        return is_string($email) ? $email : 'no-email@example.org';
    }

    protected function getEmailHash(?string $email): string
    {
        return md5($email ?? $this->getDefaultAuthorEmail());
    }

    private function integerConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value) ? (int) $value : $default;
    }
}
