<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Blueprints;

use Statamic\Exceptions\BlueprintNotFoundException;
use Statamic\Facades\Blueprint as BlueprintApi;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;

class CommentBlueprint
{
    /**
     * @throws BlueprintNotFoundException
     */
    public static function getBlueprint(string $name): Blueprint
    {
        $blueprint = BlueprintApi::findOrFail($name);

        $blueprint->ensureField('name', [
            'display' => __('meerkat::fields.name'),
            'type' => 'text',
        ]);
        $blueprint->ensureField('email', [
            'display' => __('meerkat::fields.email'),
            'type' => 'text',
            'input_type' => 'email',
        ]);
        $blueprint->ensureField('created_at', [
            'display' => __('meerkat::fields.created_at'),
            'type' => 'date',
            'mode' => 'single',
            'time_enabled' => true,
        ]);
        $blueprint->ensureField('is_spam', [
            'display' => __('meerkat::fields.is_spam'),
            'type' => 'toggle',
            'visibility' => 'hidden',
            'listable' => true,
        ]);
        $blueprint->ensureField('is_published', [
            'display' => __('meerkat::fields.is_published'),
            'type' => 'toggle',
            'visibility' => 'hidden',
            'listable' => true,
        ]);
        $blueprint->ensureField('thread_id', [
            'display' => __('meerkat::fields.thread_id'),
            'type' => 'entries',
            'max_items' => 1,
            'visibility' => 'hidden',
            'listable' => true,
        ]);
        $blueprint->ensureField('author_id', [
            'display' => __('meerkat::fields.author_id'),
            'type' => 'users',
            'max_items' => 1,
            'visibility' => 'hidden',
            'listable' => true,
        ]);
        $blueprint->ensureField('collection', [
            'display' => __('meerkat::fields.collection'),
            'type' => 'collections',
            'max_items' => 1,
            'visibility' => 'hidden',
            'listable' => true,
        ]);
        $blueprint->ensureField('site', [
            'display' => __('meerkat::fields.site'),
            'type' => 'sites',
            'max_items' => 1,
            'visibility' => 'hidden',
            'listable' => true,
        ]);
        $blueprint->ensureField('moderation_status', [
            'display' => __('meerkat::fields.moderation_status'),
            'type' => 'select',
            'options' => [
                'approved' => __('meerkat::general.approved_status'),
                'pending' => __('meerkat::general.pending_moderation'),
                'rejected' => __('meerkat::general.rejected_status'),
                'spam' => __('meerkat::general.spam_status'),
            ],
            'listable' => true,
        ]);
        $blueprint->ensureField('moderation_reason', [
            'display' => __('meerkat::fields.moderation_reason'),
            'type' => 'text',
            'listable' => true,
        ]);
        $blueprint->ensureField('moderation_notes', [
            'display' => __('meerkat::fields.moderation_notes'),
            'type' => 'textarea',
            'listable' => false,
        ]);

        if (! config('meerkat.publishing.require_guest_email', true)) {
            self::makeGuestEmailOptional($blueprint);
        }

        return $blueprint;
    }

    private static function makeGuestEmailOptional(Blueprint $blueprint): void
    {
        $field = $blueprint->field('email');

        if (! $field instanceof Field) {
            return;
        }

        $blueprint->ensureFieldHasConfig('email', [
            'required' => false,
            'validate' => self::optionalEmailRules($field->config()['validate'] ?? null),
        ]);
    }

    /** @return array<int, mixed>|string */
    private static function optionalEmailRules(mixed $rules): array|string
    {
        $wasString = is_string($rules) || $rules === null;

        $rules = collect(self::explodeRules($rules))
            ->reject(fn (mixed $rule) => self::isRequiredRule($rule))
            ->values()
            ->all();

        if (! self::hasRule($rules, 'email')) {
            $rules[] = 'email';
        }

        if (! self::hasRule($rules, 'nullable') && ! self::hasRule($rules, 'sometimes')) {
            array_unshift($rules, 'nullable');
        }

        return $wasString
            ? implode('|', array_values(array_filter($rules, is_string(...))))
            : $rules;
    }

    /**
     * @return array<int, mixed>
     */
    private static function explodeRules(mixed $rules): array
    {
        if ($rules === null || $rules === '') {
            return [];
        }

        if (is_string($rules)) {
            return explode('|', $rules);
        }

        return is_array($rules) ? array_values($rules) : [$rules];
    }

    /**
     * @param  array<int, mixed>  $rules
     */
    private static function hasRule(array $rules, string $name): bool
    {
        foreach ($rules as $rule) {
            if (! is_string($rule)) {
                continue;
            }

            if (self::ruleName($rule) === $name) {
                return true;
            }
        }

        return false;
    }

    private static function isRequiredRule(mixed $rule): bool
    {
        if (! is_string($rule)) {
            return false;
        }

        $name = self::ruleName($rule);

        return $name === 'required'
            || $name === 'present'
            || str_starts_with($name, 'required_');
    }

    private static function ruleName(string $rule): string
    {
        return strtolower(strtok($rule, ':') ?: $rule);
    }
}
