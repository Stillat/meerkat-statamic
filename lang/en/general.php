<?php

declare(strict_types=1);

return [
    'dashboard_title' => 'Comments',

    'reply' => 'Reply',
    'reply_title' => 'Reply',
    'reply_saved' => 'Reply Submitted',
    'save' => 'Save',
    'action_completed' => 'Action completed.',
    'unsaved_changes' => 'Unsaved Changes',
    'discard_changes' => 'Discard Changes',
    'discard_changes_confirmation' => 'Are you sure? Unsaved changes will be lost.',
    'form_title' => 'Meerkat Comments',

    'export_comments' => 'Export Comments',
    'edit_blueprint' => 'Edit Comment Blueprint',

    'nav_comments' => 'Comments',

    'edit_comment_title' => 'Edit Comment',
    'edit_comment' => 'Edit Comment',
    'comment_saved' => 'Comment Saved',
    'comment_submitted_successfully' => 'Comment submitted.',

    'check_pending_for_spam' => 'Check Pending for Spam',
    'check_pending_for_spam_desc' => 'Checking all pending comments can take a while. Continue?',

    'spam_check_request_submitted' => 'Request Submitted',

    'bulk_partial_failure' => ':succeeded of :total comments were processed. Please refresh and try again.',

    'check_for_spam' => 'Check for Spam',
    'check_for_spam_confirmation' => 'Check this comment for spam?|Check :count comments for spam?',
    'check_for_spam_button' => 'Check for spam|Check :count for spam',
    'spam_check_result' => 'Checked :total comment, :flagged flagged as spam.|Checked :total comments, :flagged flagged as spam.',

    'mark_as_spam' => 'Mark as Spam',
    'mark_as_spam_confirmation' => 'Mark this comment as spam? It will be unpublished.|Mark :count comments as spam? They will be unpublished.',
    'mark_as_spam_button' => 'Mark as spam|Mark :count as spam',
    'marked_as_spam' => 'The comment was marked as spam.|The comments were marked as spam.',

    'mark_as_ham' => 'Mark as Not Spam',
    'mark_as_ham_confirmation' => 'Mark this comment as not spam?|Mark :count comments as not spam?',
    'mark_as_ham_button' => 'Mark as not spam|Mark :count as not spam',
    'marked_as_ham' => 'The comment was marked as not spam.|The comments were marked as not spam.',

    'publish_comment' => 'Publish Comment',
    'publish_comment_confirmation' => 'Publish this comment?|Publish :count comments?',
    'publish_comment_button' => 'Publish|Publish :count',
    'published_comment' => 'The comment was published.|The comments were published.',

    'reject_comment' => 'Reject Comment',
    'reject_comment_confirmation' => 'Reject this comment?|Reject :count comments?',
    'reject_comment_button' => 'Reject|Reject :count',
    'reject_reason_instructions' => 'Optionally note why this comment is being rejected.',
    'rejected_comment' => 'The comment was rejected.|The comments were rejected.',

    'unpublish_comment' => 'Unpublish Comment',
    'unpublish_comment_confirmation' => 'Unpublish this comment?|Unpublish :count comments?',
    'unpublish_comment_button' => 'Unpublish|Unpublish :count',
    'unpublished_comment' => 'The comment was unpublished.|The comments were unpublished.',

    'delete_comment' => 'Delete Comment',
    'delete_comment_confirmation' => 'Hide this comment from public view? Replies underneath it are not affected.',
    'delete_comment_button' => 'Delete|Delete :count',
    'deleted_comment' => 'The comment was deleted.|The comments were deleted.',

    'restore_comment' => 'Restore Comment',
    'restore_comment_confirmation' => 'Restore this comment?|Restore :count comments?',
    'restore_comment_button' => 'Restore|Restore :count',
    'restored_comment' => 'The comment was restored.|The comments were restored.',

    'remove_subtree' => 'Delete With Replies',
    'remove_subtree_confirmation' => 'Delete this comment and every reply beneath it?',
    'remove_subtree_button' => 'Delete with replies|Delete :count with replies',
    'remove_subtree_warning' => 'Replies are hidden along with it and must be restored one at a time.',
    'removed_subtree' => ':count comment was removed.|:count comments were removed.',

    'export_comments_json' => 'Export JSON',
    'pending_moderation' => 'Pending Moderation',
    'approved_status' => 'Approved',
    'rejected_status' => 'Rejected',
    'spam_status' => 'Spam',

    'reply_inline_action' => 'Reply',

    'view_table' => 'Table View',
    'view_comments' => 'Comment View',
    'in_reply_to' => 'In reply to :name',
    'view_thread' => 'View thread',
    'view_on_site' => 'View on site',
    'thread_title' => 'Comment Thread',
    'thread_empty' => 'No comments in this thread.',
    'thread_comments_count' => ':count comment|:count comments',
    'thread_show_all' => 'Show all comments',
    'thread_show_conversation' => 'Show this conversation only',
    'guest_author_label' => 'Guest',
    'reply_placeholder' => 'Write a reply…',
    'submit_reply' => 'Reply',
    'cancel_reply' => 'Cancel',
    'more_actions' => 'More',
    'no_comments' => 'No comments found.',
    'sort_label' => 'Sort',
    'sort_newest_first' => 'Newest first',
    'sort_oldest_first' => 'Oldest first',
    'sort_author_a_z' => 'Author A → Z',
    'sort_author_z_a' => 'Author Z → A',

    'view_revisions' => 'View Revisions',
    'revisions_title' => 'Comment Revisions',
    'revision_label' => 'Revision :number',
    'revision_current_badge' => 'Current',
    'revision_by' => 'by :user',
    'revision_unknown_user' => 'Edited by an unknown user',
    'revision_today' => 'Today',
    'no_revisions' => 'No revision history yet.',
    'restore_revision' => 'Restore',
    'restore_revision_confirmation' => 'Replace the current comment with this revision? The current version is kept in the history.',
    'revision_restored' => 'Comment restored.',
    'revision_restored_reason' => 'Restored from revision :number',
];
