<?php

declare(strict_types=1);

return [
    'group_general' => 'General',
    'group_publishing' => 'Publishing',
    'group_spam' => 'Spam',
    'group_authors' => 'Authors',
    'group_ip_filtering' => 'IP Filtering',
    'group_word_filtering' => 'Word Filtering',
    'group_akismet' => 'Akismet',
    'group_security' => 'Security',
    'group_rate_limits' => 'Rate Limits',

    'blocked_ip_addresses' => 'Blocked IP Addresses',
    'blocked_ip_addresses_instruct' => 'If a submission is sent from a network with any of the following IP addresses, it will be marked as spam.',

    'banned_words' => 'Banned Words',
    'banned_words_instruct' => 'If a submission contains any of the words in the list below, it will be marked as spam.',

    'auto_publish' => 'Publish Comments Automatically',
    'auto_publish_instruct' => 'All comments from anonymous users will be published automatically when enabled. Disable this to review comments before they are listed on your site.',

    'auto_unpublish_spam' => 'Unpublish Spam Comments',
    'auto_unpublish_spam_instruct' => 'Comments marked as spam, either manually or automatically, will be automatically unpublished from the site.',

    'publish_user_auto' => 'Publish User Comments Automatically',
    'publish_user_auto_instruct' => 'Any comment left by an authenticated Statamic user will be published automatically when enabled.',

    'only_accept_comments_from_authenticated_users' => 'Only Accept Authenticated Comments',
    'only_accept_comments_from_authenticated_users_instruct' => 'Only accept comments from authenticated user sessions; anonymous, or guest, comments will be rejected.',

    'close_threads' => 'When to Close Comment Threads',
    'close_threads_instruct' => 'Enter the number of days after which comments will no longer be accepted; entering a value of "0" will disable this feature.',

    'anonymous_name' => 'Anonymous Author Name',
    'anonymous_name_instruct' => 'The name that should appear, if any, for anonymous guest submissions.',

    'anonymous_email' => 'Anonymous Author Email',
    'anonymous_email_instruct' => 'The email address that should appear, if any, for anonymous guest submissions.',

    'auto_check_spam' => 'Automatically Check for Spam',
    'auto_check_spam_instruct' => 'Controls whether all submissions are automatically checked for spam.',
    'akismet_enabled' => 'Enable Akismet',
    'akismet_enabled_instruct' => 'Use Akismet as part of the spam-guard chain when an API key is configured.',
    'akismet_api_key' => 'Akismet API Key',
    'akismet_api_key_instruct' => 'Required when Akismet is enabled. Store secrets in env values when possible.',
    'akismet_blog_url' => 'Akismet Blog URL',
    'akismet_blog_url_instruct' => 'The public site URL Akismet should associate with comment checks.',
    'akismet_comment_type' => 'Akismet Comment Type',
    'akismet_comment_type_instruct' => 'The Akismet content type sent with each comment check.',

    'auto_delete_spam' => 'Automatically Delete All Spam',
    'auto_delete_spam_instruct' => 'Controls whether submissions identified as spam are automatically deleted.',

    'submit_moderator_results' => 'Submit Moderator Results',
    'submit_moderator_results_instruct' => 'Controls whether false positive/negatives results are sent to third-party providers that support it.',

    'rate_limits_enabled' => 'Enable Submission Rate Limits',
    'rate_limits_enabled_instruct' => 'Throttle repeated public submissions by context, email, and IP address.',
    'rate_limits_attempts' => 'Max Attempts',
    'rate_limits_attempts_instruct' => 'The number of submissions allowed before the limiter blocks new attempts.',
    'rate_limits_decay' => 'Decay Minutes',
    'rate_limits_decay_instruct' => 'The number of minutes before a limited submission bucket resets.',

];
