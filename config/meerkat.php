<?php

declare(strict_types=1);

use Stillat\Meerkat\Extractors\AuthorExtractor;
use Stillat\Meerkat\Extractors\CommentExtractor;
use Stillat\Meerkat\Guard\Guards\AkismetGuard;
use Stillat\Meerkat\Guard\Guards\DeceptiveMarkupGuard;
use Stillat\Meerkat\Guard\Guards\GtubeGuard;
use Stillat\Meerkat\Guard\Guards\IpFilterGuard;
use Stillat\Meerkat\Guard\Guards\WordListGuard;

return [
    'fields' => [
        'extractors' => [
            'comment' => CommentExtractor::class,
            'author' => AuthorExtractor::class,
        ],

        'exclude' => [
            'created_at',
            'is_spam',
            'is_published',
            'thread_id',
            'author_id',
            'collection',
            'site',
        ],
    ],

    'mirror' => [
        'enabled' => env('MEERKAT_MIRROR_ENABLED', true),
        'path' => env('MEERKAT_MIRROR_PATH'),
    ],

    'database' => [
        'connection' => env('MEERKAT_DATABASE_CONNECTION'),
    ],

    'security' => [
        'guard_content_variables' => env('MEERKAT_GUARD_CONTENT_VARIABLES', true),
    ],

    'form' => [
        'blueprint' => 'meerkat',
        'honeypot' => 'username',

        'middleware' => ['throttle:30,1'],
    ],

    'publishing' => [
        'auto_publish' => false,
        'auto_publish_authenticated_users' => true,
        'only_accept_comments_from_authenticated_users' => false,
        'automatically_close_comments' => 0,
        'max_reply_depth' => null,
        'entry_disable_field' => 'comments_closed',
        'share_field' => 'meerkat_share_comments',
        'require_signed_context' => true,
        'require_guest_email' => env('MEERKAT_REQUIRE_GUEST_EMAIL_FOR_SUBMISSIONS', true),
    ],

    'privacy' => [
        'store_user_agent' => true,
        'store_user_ip' => true,
        'store_referrer' => true,
    ],

    'authors' => [
        'anonymous_email' => 'no-email@example.org',
        'anonymous_author' => 'Anonymous User',
    ],

    'spam' => [
        'guards' => [
            GtubeGuard::class,
            IpFilterGuard::class,
            WordListGuard::class,
            DeceptiveMarkupGuard::class,
            AkismetGuard::class,
        ],
        'guard_unpublish_on_guard_failure' => false,
        'auto_check_spam' => true,
        'auto_delete_spam' => false,
        'auto_unpublish_spam' => false,
        'auto_submit_results' => false,
    ],

    'iplist' => [
        'block' => [],
    ],

    'wordlist' => [
        'banned' => [],
    ],

    'akismet' => [
        'enabled' => true,
        'api_key' => env('MEERKAT_AKISMET_API_KEY'),
        'blog_url' => env('MEERKAT_AKISMET_BLOG_URL', env('APP_URL')),
        'api_host' => env('MEERKAT_AKISMET_API_HOST', 'rest.akismet.com'),
        'comment_type' => env('MEERKAT_AKISMET_COMMENT_TYPE', 'comment'),
    ],

    'jobs' => [
        'connection' => env('MEERKAT_JOB_CONNECTION', env('QUEUE_CONNECTION', 'sync')),
        'queue' => env('MEERKAT_JOB_QUEUE', 'default'),
    ],

    'rate_limits' => [
        'enabled' => true,
        'max_attempts' => env('MEERKAT_RATE_LIMIT_ATTEMPTS', 5),
        'decay_minutes' => env('MEERKAT_RATE_LIMIT_DECAY', 15),
    ],

    'api' => [
        'enabled' => true,
        'per_page' => 15,
        'max_per_page' => 100,
        'max_full_thread_comments' => 500,
        'middleware' => [
            'public' => ['throttle:60,1'],
            'privileged' => ['throttle:30,1', 'auth', 'can:view comments'],
        ],
    ],

    'cp' => [
        'per_page' => 50,
        'max_per_page' => 100,
    ],

    'revisions' => [
        'enabled' => env('MEERKAT_REVISIONS_ENABLED', false),
    ],

    'graphql' => [
        'enabled' => env('MEERKAT_GRAPHQL_ENABLED', false),
        'per_page' => 15,
        'max_per_page' => 100,

        'max_depth' => 25,
    ],
];
