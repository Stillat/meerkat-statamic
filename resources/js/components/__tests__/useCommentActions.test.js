import { describe, expect, it } from 'vitest';
import {
    PRIMARY_ACTION_HANDLES,
    SORT_OPTIONS,
    actionIcon,
    moderationBadge,
    partitionActions,
} from '../useCommentActions.js';

describe('partitionActions', () => {
    it('returns empty arrays when given non-array input', () => {
        expect(partitionActions(null)).toEqual({ primary: [], overflow: [] });
        expect(partitionActions(undefined)).toEqual({ primary: [], overflow: [] });
        expect(partitionActions({})).toEqual({ primary: [], overflow: [] });
    });

    it('returns empty arrays when given an empty array', () => {
        expect(partitionActions([])).toEqual({ primary: [], overflow: [] });
    });

    it('puts publish, unpublish, and mark_as_spam into the primary bucket', () => {
        const actions = [
            { handle: 'publish', title: 'Publish' },
            { handle: 'unpublish', title: 'Unpublish' },
            { handle: 'mark_as_spam', title: 'Mark as Spam' },
        ];
        const { primary, overflow } = partitionActions(actions);
        expect(primary.map((a) => a.handle)).toEqual(['publish', 'unpublish', 'mark_as_spam']);
        expect(overflow).toEqual([]);
    });

    it('puts everything else into the overflow bucket', () => {
        const actions = [
            { handle: 'mark_as_ham', title: 'Mark as Ham' },
            { handle: 'check_for_spam', title: 'Check for Spam' },
            { handle: 'reject_comment', title: 'Reject' },
            { handle: 'delete_comment', title: 'Delete' },
        ];
        const { primary, overflow } = partitionActions(actions);
        expect(primary).toEqual([]);
        expect(overflow.map((a) => a.handle)).toEqual([
            'mark_as_ham',
            'check_for_spam',
            'reject_comment',
            'delete_comment',
        ]);
    });

    it('correctly partitions a mixed list while preserving order within each bucket', () => {
        const actions = [
            { handle: 'check_for_spam' },
            { handle: 'publish' },
            { handle: 'delete_comment' },
            { handle: 'mark_as_spam' },
            { handle: 'unpublish' },
        ];
        const { primary, overflow } = partitionActions(actions);
        expect(primary.map((a) => a.handle)).toEqual(['publish', 'mark_as_spam', 'unpublish']);
        expect(overflow.map((a) => a.handle)).toEqual(['check_for_spam', 'delete_comment']);
    });

    it('keeps PRIMARY_ACTION_HANDLES exported for external reference', () => {
        expect(PRIMARY_ACTION_HANDLES).toEqual(['publish', 'unpublish', 'mark_as_spam']);
    });
});

describe('actionIcon', () => {
    it('returns mapped icon names for known handles', () => {
        expect(actionIcon('publish')).toBe('checkmark');
        expect(actionIcon('unpublish')).toBe('eye-slash');
        expect(actionIcon('mark_as_spam')).toBe('warning-diamond');
        expect(actionIcon('mark_as_ham')).toBe('mail-check');
        expect(actionIcon('check_for_spam')).toBe('clipboard-check');
        expect(actionIcon('reject_comment')).toBe('delete');
        expect(actionIcon('delete_comment')).toBe('trash');
    });

    it('returns null for unknown handles', () => {
        expect(actionIcon('totally_made_up')).toBeNull();
        expect(actionIcon('')).toBeNull();
        expect(actionIcon(undefined)).toBeNull();
    });
});

describe('moderationBadge', () => {
    const translate = (key) => `T(${key})`;

    it('returns null for unknown / falsy statuses', () => {
        expect(moderationBadge(null, translate)).toBeNull();
        expect(moderationBadge(undefined, translate)).toBeNull();
        expect(moderationBadge('totally_made_up', translate)).toBeNull();
    });

    it('returns the right color + translated label for each known status', () => {
        expect(moderationBadge('approved', translate)).toEqual({
            color: 'green',
            label: 'T(meerkat::general.approved_status)',
        });
        expect(moderationBadge('pending', translate)).toEqual({
            color: 'amber',
            label: 'T(meerkat::general.pending_moderation)',
        });
        expect(moderationBadge('rejected', translate)).toEqual({
            color: 'red',
            label: 'T(meerkat::general.rejected_status)',
        });
        expect(moderationBadge('spam', translate)).toEqual({
            color: 'rose',
            label: 'T(meerkat::general.spam_status)',
        });
    });

    it('falls back to the translation key when no translator is provided', () => {
        expect(moderationBadge('approved')).toEqual({
            color: 'green',
            label: 'meerkat::general.approved_status',
        });
    });
});

describe('SORT_OPTIONS', () => {
    it('exposes four canonical sort choices in a fixed order', () => {
        expect(SORT_OPTIONS.map((o) => o.handle)).toEqual([
            'newest',
            'oldest',
            'author_asc',
            'author_desc',
        ]);
    });

    it('each option pairs a column with a direction', () => {
        for (const option of SORT_OPTIONS) {
            expect(option).toEqual(expect.objectContaining({
                handle: expect.any(String),
                column: expect.any(String),
                direction: expect.stringMatching(/^(asc|desc)$/),
                labelKey: expect.stringMatching(/^meerkat::general\.sort_/),
            }));
        }
    });
});
