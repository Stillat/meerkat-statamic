
export const PRIMARY_ACTION_HANDLES = ['publish', 'unpublish', 'mark_as_spam'];

const ACTION_ICON_MAP = {
    publish: 'checkmark',
    unpublish: 'eye-slash',
    mark_as_spam: 'warning-diamond',
    mark_as_ham: 'mail-check',
    check_for_spam: 'clipboard-check',
    reject_comment: 'delete',
    delete_comment: 'trash',
};

const MODERATION_BADGE_MAP = {
    approved: { color: 'green', labelKey: 'meerkat::general.approved_status' },
    pending: { color: 'amber', labelKey: 'meerkat::general.pending_moderation' },
    rejected: { color: 'red', labelKey: 'meerkat::general.rejected_status' },
    spam: { color: 'rose', labelKey: 'meerkat::general.spam_status' },
};

export const SORT_OPTIONS = [
    { handle: 'newest', column: 'created_at', direction: 'desc', labelKey: 'meerkat::general.sort_newest_first' },
    { handle: 'oldest', column: 'created_at', direction: 'asc', labelKey: 'meerkat::general.sort_oldest_first' },
    { handle: 'author_asc', column: 'name', direction: 'asc', labelKey: 'meerkat::general.sort_author_a_z' },
    { handle: 'author_desc', column: 'name', direction: 'desc', labelKey: 'meerkat::general.sort_author_z_a' },
];

export function partitionActions(actions) {
    if (! Array.isArray(actions)) return { primary: [], overflow: [] };

    const primary = [];
    const overflow = [];

    for (const action of actions) {
        (PRIMARY_ACTION_HANDLES.includes(action.handle) ? primary : overflow).push(action);
    }

    return { primary, overflow };
}

export function actionIcon(handle) {
    return ACTION_ICON_MAP[handle] ?? null;
}

export function moderationBadge(status, translate) {
    const entry = MODERATION_BADGE_MAP[status];
    if (! entry) return null;

    return {
        color: entry.color,
        label: translate ? translate(entry.labelKey) : entry.labelKey,
    };
}
