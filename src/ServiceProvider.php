<?php

declare(strict_types=1);

namespace Stillat\Meerkat;

use Illuminate\Support\Facades\Route;
use Statamic\Events\EntryDeleted;
use Statamic\Events\EntrySaved;
use Statamic\Events\UserDeleted;
use Statamic\Events\UserSaved;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\GraphQL;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;
use Stillat\Meerkat\Configuration\SettingsBlueprint;
use Stillat\Meerkat\Database\CommentQueryBuilder;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Events\CommentSaved;
use Stillat\Meerkat\GraphQL\Queries\CommentQuery;
use Stillat\Meerkat\GraphQL\Queries\CommentsQuery;
use Stillat\Meerkat\GraphQL\Queries\ThreadQuery;
use Stillat\Meerkat\GraphQL\Types\CommentType;
use Stillat\Meerkat\GraphQL\Types\ThreadType;
use Stillat\Meerkat\Query\Scopes\Filters\Fields;
use Stillat\Meerkat\Support\Features;
use Stillat\Meerkat\Tags\Meerkat;

class ServiceProvider extends AddonServiceProvider
{
    protected $tags = [
        Meerkat::class,
    ];

    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
        'web' => __DIR__.'/../routes/web.php',
    ];

    protected $scopes = [
        Fields::class,
    ];

    protected $listen = [
        CommentSaved::class => [Listeners\CommentSavedListener::class],
        EntrySaved::class => [Listeners\EntrySavedListener::class],
        EntryDeleted::class => [Listeners\EntryDeletedListener::class],
        UserSaved::class => [Listeners\UserSavedListener::class],
        UserDeleted::class => [Listeners\UserDeletedListener::class],
    ];

    protected $commands = [
        Commands\ExportIdentityCommand::class,
        Commands\ForgetIdentityCommand::class,
        Commands\HealthCheck::class,
        Commands\Install::class,
        Commands\PurgeCommand::class,
        Commands\Sync::class,
        Commands\SyncTitles::class,
        Commands\SyncMetrics::class,
        Commands\SyncUsersMeta::class,
    ];

    /**
     * @var array{input: list<string>, publicDirectory: string}
     */
    protected $vite = [
        'input' => [
            'resources/js/cp.js',
            'resources/css/cp.css',
        ],
        'publicDirectory' => 'resources/dist',
    ];

    public function bootAddon(): void
    {
        $this->registerPublishables()
            ->bindData()
            ->registerSettings()
            ->registerApiMiddlewareGroups()
            ->registerApiRoutes()
            ->registerModelHooks()
            ->registerGraphQl()
            ->loadTranslations()
            ->loadViews()
            ->bootPermissions()
            ->guardContentVariables()
            ->createNav();
    }

    protected function guardContentVariables(): static
    {
        if (config('meerkat.security.guard_content_variables', true) !== true) {
            return $this;
        }

        $patterns = [];

        foreach (Comments\PublicCommentData::GUARDED_KEYS as $key) {
            $patterns[] = $key;
            $patterns[] = '*.'.$key;
            $patterns[] = '*:'.$key;
        }

        $existing = config('statamic.antlers.guardedContentVariables', []);
        $existing = is_array($existing) ? array_values(array_filter($existing, is_string(...))) : [];

        config(['statamic.antlers.guardedContentVariables' => array_values(array_unique(array_merge(
            $existing,
            $patterns,
        )))]);

        return $this;
    }

    protected function registerGraphQl(): static
    {
        if (! Features::graphql() || ! class_exists(GraphQL::class)) {
            return $this;
        }

        GraphQL::addTypes([
            new CommentType,
            new ThreadType,
        ]);

        GraphQL::addQuery(CommentQuery::class);
        GraphQL::addQuery(CommentsQuery::class);
        GraphQL::addQuery(ThreadQuery::class);

        Listeners\InvalidatesGraphQlCache::register();

        return $this;
    }

    protected function registerApiRoutes(): static
    {
        $middleware = config('statamic.routes.middleware', 'web');
        $middleware = is_string($middleware) || is_array($middleware) ? $middleware : 'web';

        Route::middleware($middleware)
            ->prefix($this->apiRoutePrefix())
            ->name('meerkat.api.')
            ->group(__DIR__.'/../routes/api.php');

        return $this;
    }

    protected function apiRoutePrefix(): string
    {
        $configuredPrefix = config('statamic.api.route', 'api');
        $prefix = trim(is_string($configuredPrefix) ? $configuredPrefix : 'api', '/');

        return ($prefix === '' ? 'api' : $prefix).'/meerkat';
    }

    protected function registerSettings(): static
    {
        $this->registerSettingsBlueprint(SettingsBlueprint::definition());

        return $this;
    }

    protected function registerModelHooks(): static
    {
        if (Features::revisions()) {
            Listeners\CapturesCommentRevisions::register();
        }

        return $this;
    }

    protected function registerApiMiddlewareGroups(): static
    {
        $router = $this->app->make('router');

        foreach (['public', 'privileged'] as $tier) {
            $router->middlewareGroup(
                'meerkat-api-'.$tier,
                (array) config('meerkat.api.middleware.'.$tier, []),
            );
        }

        $router->middlewareGroup(
            'meerkat-form-submit',
            (array) config('meerkat.form.middleware', []),
        );

        return $this;
    }

    protected function registerPublishables(): static
    {
        $this->publishes([
            __DIR__.'/../resources/blueprints/meerkat.yaml' => resource_path('blueprints/meerkat.yaml'),
        ], 'meerkat-blueprints');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/meerkat'),
        ], 'meerkat-views');

        $this->publishes([
            __DIR__.'/../migrations' => database_path('migrations'),
        ], 'meerkat-migrations');

        return $this;
    }

    protected function loadTranslations(): static
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'meerkat');

        return $this;
    }

    protected function bindData(): static
    {
        $this->app->bind(
            Contracts\CommentRepository::class,
            Data\CommentRepository::class,
        );

        $this->app->bind(fn ($app): CommentQueryBuilder => new CommentQueryBuilder(Comment::query()));

        return $this;
    }

    protected function loadViews(): static
    {
        $this->loadViewsFrom(
            __DIR__.'/../resources/views',
            'meerkat'
        );

        return $this;
    }

    protected function createNav(): static
    {
        Nav::extend(function (\Statamic\CP\Navigation\Nav $nav) {
            $item = $nav->create(__('meerkat::general.nav_comments'));
            $item->section('Content');
            $item->icon(<<<'ICON'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" id="Messages-Bubble-Text-1--Streamline-Streamline-3.0" height="24" width="24"><desc>Messages Bubble Text 1 Streamline Icon: https://streamlinehq.com</desc><defs></defs><title>messages-bubble-text_1</title><path d="M12 1C5.649 1 0.5 5.253 0.5 10.5a8.738 8.738 0 0 0 3.4 6.741L1.5 23l6.372 -3.641A13.608 13.608 0 0 0 12 20c6.351 0 11.5 -4.253 11.5 -9.5S18.351 1 12 1Z" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1"></path><path d="m6.5 7 8 0" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1"></path><path d="m6.5 10 11 0" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1"></path><path d="m6.5 13 11 0" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1"></path></svg>
ICON
            );
            $item->route('meerkat.cp.dashboard');
            $item->can('view comments');

        });

        return $this;
    }

    protected function bootPermissions(): static
    {
        Permission::group('meerkat', __('meerkat::permissions.group'), function () {
            Permission::register('view comments', function (\Statamic\Auth\Permission $permission) {
                $permission->children([
                    Permission::make('edit comments')->label(__('meerkat::permissions.edit_comments')),
                    Permission::make('delete comments')->label(__('meerkat::permissions.delete_comments')),
                    Permission::make('check comment spam')->label(__('meerkat::permissions.check_comment_spam')),
                    Permission::make('report comment spam')->label(__('meerkat::permissions.report_comment_spam')),
                ]);
            })->label(__('meerkat::permissions.view_comments'));

            Permission::register('submit comments')->label(__('meerkat::permissions.submit_comments'));

        });

        return $this;
    }
}
