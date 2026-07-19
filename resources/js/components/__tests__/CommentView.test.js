import { describe, expect, it, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import CommentView from '../CommentView.vue';

function makeComment(overrides = {}) {
    return {
        id: 1,
        comment_text: 'hi',
        comment_html: '<p>hi</p>',
        created_at_display: 'May 12, 2026 5:14 PM',
        moderation_status: 'approved',
        author: {
            id: null,
            name: 'Test Guest',
            email: 'guest@example.com',
            initials: 'TG',
            is_guest: true,
        },
        parent_summary: null,
        thread: { id: 'thread-1', title: 'Thread Title', permalink: 'http://x' },
        actions: [],
        ...overrides,
    };
}

function makeWrapper({ permissions = {}, items = [makeComment()] } = {}) {
    return mount(CommentView, {
        props: {
            url: '/cp/meerkat/comments/filter',
            actionUrl: '/cp/meerkat/actions',
            columns: [],
            filters: [],
            permissions,
        },
        global: {
            stubs: {
                Listing: {
                    name: 'Listing',
                    inheritAttrs: false,
                    props: [
                        'url', 'columns', 'filters', 'actionUrl', 'sortColumn',
                        'sortDirection', 'preferencesPrefix', 'pushQuery', 'items',
                        'allowBulkActions', 'allowCustomizingColumns',
                    ],
                    setup(_, { slots, expose }) {
                        expose({ refresh: vi.fn() });
                        return () => slots.default
                            ? slots.default({
                                items,
                                loading: false,
                                isColumnVisible: () => true,
                            })
                            : null;
                    },
                },
            },
        },
    });
}

beforeEach(() => {
    vi.clearAllMocks();
});

describe('CommentView — permission-gated rendering', () => {
    it('renders the empty state when there are no comments', () => {
        const wrapper = makeWrapper({ items: [] });
        expect(wrapper.find('[data-test="comment-empty-state"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="comment-list"]').exists()).toBe(false);
    });

    it('renders one row per comment', () => {
        const items = [makeComment({ id: 1 }), makeComment({ id: 2 }), makeComment({ id: 3 })];
        const wrapper = makeWrapper({ items });
        expect(wrapper.findAll('[data-comment-id]')).toHaveLength(3);
    });

    it('shows the Reply button when the user can submit comments', () => {
        const wrapper = makeWrapper({ permissions: { can_submit_comments: true } });
        expect(wrapper.find('[data-test="comment-reply-button"]').exists()).toBe(true);
    });

    it('hides the Reply button when the user cannot submit comments', () => {
        const wrapper = makeWrapper({ permissions: { can_submit_comments: false } });
        expect(wrapper.find('[data-test="comment-reply-button"]').exists()).toBe(false);
    });

    it('shows the Edit dropdown item when the user can edit comments', () => {
        const wrapper = makeWrapper({ permissions: { can_edit_comments: true } });
        expect(wrapper.find('[data-test="comment-edit-item"]').exists()).toBe(true);
    });

    it('hides the Edit dropdown item when the user cannot edit comments', () => {
        const wrapper = makeWrapper({ permissions: { can_edit_comments: false } });
        expect(wrapper.find('[data-test="comment-edit-item"]').exists()).toBe(false);
    });

    it('renders the guest indicator for guest authors', () => {
        const wrapper = makeWrapper({
            items: [makeComment({ author: { ...makeComment().author, is_guest: true } })],
        });
        expect(wrapper.find('[data-test="comment-guest-label"]').exists()).toBe(true);
    });

    it('does not render the guest indicator for authenticated authors', () => {
        const wrapper = makeWrapper({
            items: [makeComment({
                author: { id: 'u1', name: 'Real User', email: 'r@x.com', initials: 'RU', is_guest: false },
            })],
        });
        expect(wrapper.find('[data-test="comment-guest-label"]').exists()).toBe(false);
    });
});

describe('CommentView — entry link', () => {
    it('appends #comment-<id> to the entry permalink so template authors can anchor to it', () => {
        const wrapper = makeWrapper({
            items: [makeComment({
                id: 42,
                thread: { id: 'blog/post', title: 'Blog Post', permalink: 'https://example.com/blog/post' },
            })],
        });

        const link = wrapper.find('a[href]');
        expect(link.exists()).toBe(true);
        expect(link.attributes('href')).toBe('https://example.com/blog/post#comment-42');
    });

    it('falls back to thread.url when permalink is absent and still adds the fragment', () => {
        const wrapper = makeWrapper({
            items: [makeComment({
                id: 7,
                thread: { id: 'blog/post', title: 'Blog Post', permalink: null, url: 'https://example.com/blog/post' },
            })],
        });

        const link = wrapper.find('a[href]');
        expect(link.attributes('href')).toBe('https://example.com/blog/post#comment-7');
    });

    it('renders the thread title as plain text (no link) when neither permalink nor url is present', () => {
        const wrapper = makeWrapper({
            items: [makeComment({
                thread: { id: 'blog/post', title: 'Blog Post' },
            })],
        });

        expect(wrapper.find('a[href]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Blog Post');
    });
});

describe('CommentView — primary vs overflow actions', () => {
    it('renders publish/unpublish/mark_as_spam as primary buttons', () => {
        const wrapper = makeWrapper({
            items: [makeComment({
                actions: [
                    { handle: 'publish', title: 'Publish' },
                    { handle: 'mark_as_spam', title: 'Mark as Spam' },
                ],
            })],
        });

        expect(wrapper.find('[data-test="comment-action-publish"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="comment-action-mark_as_spam"]').exists()).toBe(true);
    });

    it('routes non-primary actions into the More dropdown', () => {
        const wrapper = makeWrapper({
            items: [makeComment({
                actions: [
                    { handle: 'delete_comment', title: 'Delete', dangerous: true },
                    { handle: 'check_for_spam', title: 'Check for Spam' },
                ],
            })],
        });

        expect(wrapper.find('[data-test="comment-action-delete_comment"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="comment-overflow-delete_comment"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="comment-overflow-check_for_spam"]').exists()).toBe(true);
    });
});

describe('CommentView — reply form', () => {
    it('opens the reply form when the Reply button is clicked', async () => {
        const wrapper = makeWrapper({ permissions: { can_submit_comments: true } });
        expect(wrapper.find('[data-test="comment-reply-form"]').exists()).toBe(false);

        await wrapper.find('[data-test="comment-reply-button"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-test="comment-reply-form"]').exists()).toBe(true);
    });

    it('marks the global $dirty registry when the reply textarea has content', async () => {
        const wrapper = makeWrapper({ permissions: { can_submit_comments: true } });
        await wrapper.find('[data-test="comment-reply-button"]').trigger('click');
        await flushPromises();

        await wrapper.find('textarea').setValue('drafting a reply');
        await flushPromises();

        expect(Statamic.$dirty.add).toHaveBeenCalledWith('meerkat-inline-reply');
    });

    it('binds an ESC handler while the reply form is open', async () => {
        const wrapper = makeWrapper({ permissions: { can_submit_comments: true } });
        expect(Statamic.$keys.bindGlobal).not.toHaveBeenCalled();

        await wrapper.find('[data-test="comment-reply-button"]').trigger('click');
        await flushPromises();

        expect(Statamic.$keys.bindGlobal).toHaveBeenCalledWith(['esc'], expect.any(Function));
    });

    async function openDirtyReply(permissions = { can_submit_comments: true }) {
        const wrapper = makeWrapper({ permissions });
        await wrapper.find('[data-test="comment-reply-button"]').trigger('click');
        await flushPromises();
        await wrapper.find('textarea').setValue('drafting a reply');
        await flushPromises();
        return wrapper;
    }

    const cancelButton = '[data-test="comment-reply-form"] [data-text="meerkat::general.cancel_reply"]';

    it('opens the discard confirmation when cancelling a reply that has content', async () => {
        const wrapper = await openDirtyReply();
        expect(wrapper.find('[data-stub="ConfirmationModal"]').exists()).toBe(false);

        await wrapper.find(cancelButton).trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-stub="ConfirmationModal"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="comment-reply-form"]').exists()).toBe(true);
    });

    it('closes the reply form when the discard is confirmed', async () => {
        const wrapper = await openDirtyReply();
        await wrapper.find(cancelButton).trigger('click');
        await flushPromises();

        await wrapper.find('[data-test="discard-confirm"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-test="comment-reply-form"]').exists()).toBe(false);
        expect(wrapper.find('[data-stub="ConfirmationModal"]').exists()).toBe(false);
    });

    it('keeps the reply form open when the discard is dismissed', async () => {
        const wrapper = await openDirtyReply();
        await wrapper.find(cancelButton).trigger('click');
        await flushPromises();

        await wrapper.find('[data-test="discard-cancel"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-stub="ConfirmationModal"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="comment-reply-form"]').exists()).toBe(true);
        expect(wrapper.find('textarea').element.value).toBe('drafting a reply');
    });

    it('closes an empty reply form immediately without confirmation', async () => {
        const wrapper = makeWrapper({ permissions: { can_submit_comments: true } });
        await wrapper.find('[data-test="comment-reply-button"]').trigger('click');
        await flushPromises();

        await wrapper.find(cancelButton).trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-stub="ConfirmationModal"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="comment-reply-form"]').exists()).toBe(false);
    });
});

describe('CommentView — sort handling', () => {
    it('updates the URL on sort change and clears the page param', async () => {
        const replaceState = vi.spyOn(window.history, 'replaceState');
        const wrapper = makeWrapper();

        wrapper.vm.applySort = wrapper.vm.applySort || (() => {});

        const sortItems = wrapper.findAll('[data-stub="DropdownItem"]');
        const oldestFirst = sortItems.find((item) => item.attributes('data-text') === 'meerkat::general.sort_oldest_first');
        expect(oldestFirst).toBeDefined();

        await oldestFirst.trigger('click');
        await flushPromises();

        expect(replaceState).toHaveBeenCalled();
        const url = replaceState.mock.calls.at(-1)[2];
        expect(url).toContain('sort=created_at');
        expect(url).toContain('order=asc');
    });
});

describe('CommentView — thread', () => {
    it('emits view-thread with the comment when the in-reply-to link is clicked', async () => {
        const wrapper = makeWrapper({
            items: [makeComment({ id: 9, parent_summary: { id: 1, author_name: 'Parent' } })],
        });

        const link = wrapper.find('[data-test="comment-view-thread"]');
        expect(link.exists()).toBe(true);

        await link.trigger('click');

        expect(wrapper.emitted('view-thread')).toBeTruthy();
        expect(wrapper.emitted('view-thread')[0][0].id).toBe(9);
    });

    it('does not render the in-reply-to link for a root comment', () => {
        const wrapper = makeWrapper({ items: [makeComment({ parent_summary: null })] });

        expect(wrapper.find('[data-test="comment-view-thread"]').exists()).toBe(false);
    });

    it('opens the thread when the entry title is clicked', async () => {
        const wrapper = makeWrapper({
            items: [makeComment({ id: 7, thread: { id: 'blog/post', title: 'Blog Post', permalink: 'https://example.com/blog/post' } })],
        });

        const title = wrapper.find('[data-test="comment-thread-title"]');
        expect(title.exists()).toBe(true);

        await title.trigger('click');

        expect(wrapper.emitted('view-thread')).toBeTruthy();
        expect(wrapper.emitted('view-thread')[0][0].id).toBe(7);
    });
});
