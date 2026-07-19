<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Configuration;

class SettingsBlueprint
{
    /** @return array<string, mixed> */
    public static function definition(): array
    {
        return [
            'tabs' => [
                'general' => [
                    'display' => __('meerkat::config.group_general'),
                    'sections' => [
                        [
                            'fields' => [
                                [
                                    'handle' => 'publishing',
                                    'field' => [
                                        'type' => 'group',
                                        'full_width_setting' => true,
                                        'border' => false,
                                        'display' => __('meerkat::config.group_publishing'),
                                        'fields' => [
                                            [
                                                'handle' => 'auto_publish',
                                                'field' => [
                                                    'type' => 'toggle',
                                                    'default' => false,
                                                    'display' => __('meerkat::config.auto_publish'),
                                                    'instructions' => __('meerkat::config.auto_publish_instruct'),
                                                    'width' => 50,
                                                ],
                                            ],
                                            [
                                                'handle' => 'auto_publish_authenticated_users',
                                                'field' => [
                                                    'type' => 'toggle',
                                                    'default' => true,
                                                    'display' => __('meerkat::config.publish_user_auto'),
                                                    'instructions' => __('meerkat::config.publish_user_auto_instruct'),
                                                    'width' => 50,
                                                ],
                                            ],
                                            [
                                                'handle' => 'only_accept_comments_from_authenticated_users',
                                                'field' => [
                                                    'type' => 'toggle',
                                                    'default' => false,
                                                    'display' => __('meerkat::config.only_accept_comments_from_authenticated_users'),
                                                    'instructions' => __('meerkat::config.only_accept_comments_from_authenticated_users_instruct'),
                                                    'width' => 100,
                                                ],
                                            ],
                                            [
                                                'handle' => 'automatically_close_comments',
                                                'field' => [
                                                    'type' => 'integer',
                                                    'default' => 0,
                                                    'min' => 0,
                                                    'display' => __('meerkat::config.close_threads'),
                                                    'instructions' => __('meerkat::config.close_threads_instruct'),
                                                    'width' => 50,
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'fields' => [
                                [
                                    'handle' => 'authors',
                                    'field' => [
                                        'type' => 'group',
                                        'full_width_setting' => true,
                                        'border' => false,
                                        'display' => __('meerkat::config.group_authors'),
                                        'fields' => [
                                            [
                                                'handle' => 'anonymous_author',
                                                'field' => [
                                                    'type' => 'text',
                                                    'display' => __('meerkat::config.anonymous_name'),
                                                    'instructions' => __('meerkat::config.anonymous_name_instruct'),
                                                    'width' => 50,
                                                ],
                                            ],
                                            [
                                                'handle' => 'anonymous_email',
                                                'field' => [
                                                    'type' => 'text',
                                                    'input_type' => 'email',
                                                    'display' => __('meerkat::config.anonymous_email'),
                                                    'instructions' => __('meerkat::config.anonymous_email_instruct'),
                                                    'width' => 50,
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'spam' => [
                    'display' => __('meerkat::config.group_spam'),
                    'sections' => [
                        [
                            'fields' => [
                                [
                                    'handle' => 'spam',
                                    'field' => [
                                        'type' => 'group',
                                        'full_width_setting' => true,
                                        'border' => false,
                                        'display' => __('meerkat::config.group_spam'),
                                        'fields' => [
                                            [
                                                'handle' => 'auto_check_spam',
                                                'field' => [
                                                    'type' => 'toggle',
                                                    'default' => true,
                                                    'display' => __('meerkat::config.auto_check_spam'),
                                                    'instructions' => __('meerkat::config.auto_check_spam_instruct'),
                                                    'width' => 50,
                                                ],
                                            ],
                                            [
                                                'handle' => 'auto_delete_spam',
                                                'field' => [
                                                    'type' => 'toggle',
                                                    'default' => false,
                                                    'display' => __('meerkat::config.auto_delete_spam'),
                                                    'instructions' => __('meerkat::config.auto_delete_spam_instruct'),
                                                    'width' => 50,
                                                ],
                                            ],
                                            [
                                                'handle' => 'auto_submit_results',
                                                'field' => [
                                                    'type' => 'toggle',
                                                    'default' => false,
                                                    'display' => __('meerkat::config.submit_moderator_results'),
                                                    'instructions' => __('meerkat::config.submit_moderator_results_instruct'),
                                                    'width' => 50,
                                                ],
                                            ],
                                            [
                                                'handle' => 'auto_unpublish_spam',
                                                'field' => [
                                                    'type' => 'toggle',
                                                    'default' => false,
                                                    'display' => __('meerkat::config.auto_unpublish_spam'),
                                                    'instructions' => __('meerkat::config.auto_unpublish_spam_instruct'),
                                                    'width' => 50,
                                                ],
                                            ],
                                            [
                                                'handle' => 'akismet',
                                                'field' => [
                                                    'type' => 'group',
                                                    'full_width_setting' => true,

                                                    'border' => true,
                                                    'display' => __('meerkat::config.group_akismet'),
                                                    'fields' => [
                                                        [
                                                            'handle' => 'enabled',
                                                            'field' => [
                                                                'type' => 'toggle',
                                                                'default' => true,
                                                                'display' => __('meerkat::config.akismet_enabled'),
                                                                'instructions' => __('meerkat::config.akismet_enabled_instruct'),
                                                                'width' => 50,
                                                            ],
                                                        ],
                                                        [
                                                            'handle' => 'api_key',
                                                            'field' => [
                                                                'type' => 'text',
                                                                'display' => __('meerkat::config.akismet_api_key'),
                                                                'instructions' => __('meerkat::config.akismet_api_key_instruct'),
                                                                'width' => 50,

                                                                'validate' => 'required_if:{this}.enabled,true',
                                                            ],
                                                        ],
                                                        [
                                                            'handle' => 'blog_url',
                                                            'field' => [
                                                                'type' => 'text',
                                                                'default' => '{{ config:app:url }}',
                                                                'display' => __('meerkat::config.akismet_blog_url'),
                                                                'instructions' => __('meerkat::config.akismet_blog_url_instruct'),
                                                                'width' => 50,
                                                            ],
                                                        ],
                                                        [
                                                            'handle' => 'comment_type',
                                                            'field' => [
                                                                'type' => 'text',
                                                                'default' => 'comment',
                                                                'display' => __('meerkat::config.akismet_comment_type'),
                                                                'instructions' => __('meerkat::config.akismet_comment_type_instruct'),
                                                                'width' => 50,
                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'security' => [
                    'display' => __('meerkat::config.group_security'),
                    'sections' => [
                        [
                            'fields' => [
                                [
                                    'handle' => 'rate_limits',
                                    'field' => [
                                        'type' => 'group',
                                        'full_width_setting' => true,
                                        'border' => false,
                                        'display' => __('meerkat::config.group_rate_limits'),
                                        'fields' => [
                                            [
                                                'handle' => 'enabled',
                                                'field' => [
                                                    'type' => 'toggle',
                                                    'default' => true,
                                                    'display' => __('meerkat::config.rate_limits_enabled'),
                                                    'instructions' => __('meerkat::config.rate_limits_enabled_instruct'),
                                                    'width' => 33,
                                                ],
                                            ],
                                            [
                                                'handle' => 'max_attempts',
                                                'field' => [
                                                    'type' => 'integer',
                                                    'default' => 5,
                                                    'display' => __('meerkat::config.rate_limits_attempts'),
                                                    'instructions' => __('meerkat::config.rate_limits_attempts_instruct'),
                                                    'width' => 33,
                                                ],
                                            ],
                                            [
                                                'handle' => 'decay_minutes',
                                                'field' => [
                                                    'type' => 'integer',
                                                    'default' => 15,
                                                    'display' => __('meerkat::config.rate_limits_decay'),
                                                    'instructions' => __('meerkat::config.rate_limits_decay_instruct'),
                                                    'width' => 33,
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'iplist' => [
                    'display' => __('meerkat::config.group_ip_filtering'),
                    'sections' => [
                        [
                            'fields' => [
                                [
                                    'handle' => 'iplist',
                                    'field' => [
                                        'type' => 'group',
                                        'full_width_setting' => true,
                                        'border' => false,
                                        'display' => __('meerkat::config.group_ip_filtering'),
                                        'fields' => [
                                            [

                                                'handle' => 'block',
                                                'field' => [
                                                    'type' => 'list',
                                                    'display' => __('meerkat::config.blocked_ip_addresses'),
                                                    'instructions' => __('meerkat::config.blocked_ip_addresses_instruct'),
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'wordlist' => [
                    'display' => __('meerkat::config.group_word_filtering'),
                    'sections' => [
                        [
                            'fields' => [
                                [
                                    'handle' => 'wordlist',
                                    'field' => [
                                        'type' => 'group',
                                        'full_width_setting' => true,
                                        'border' => false,
                                        'display' => __('meerkat::config.group_word_filtering'),
                                        'fields' => [
                                            [
                                                'handle' => 'banned',
                                                'field' => [
                                                    'type' => 'list',
                                                    'display' => __('meerkat::config.banned_words'),
                                                    'instructions' => __('meerkat::config.banned_words_instruct'),
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
