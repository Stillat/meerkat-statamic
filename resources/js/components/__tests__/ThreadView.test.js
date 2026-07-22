import { describe, expect, it, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import axios from 'axios';
import ThreadView from '../ThreadView.vue';

vi.mock('axios');

function comment(overrides = {}) {
    return {
        id: 1, parent_id: null, depth: 0, moderation_status: 'approved', is_removed: false,
        author: { name: 'Author', initials: 'A', is_guest: false },
        comment_html: '<p>body</p>', created_at: '2026-06-20T21:09:00Z', parent_author: null, actions: [],
        ...overrides,
    };
}

const THREAD = {
    thread: { id: 'entry-1', title: 'Welcome Post', url: 'https://x.test/welcome' },
    comments: [
        comment({ id: 1, depth: 0, parent_id: null, comment_html: '<p>root</p>' }),
        comment({ id: 2, depth: 1, parent_id: 1, comment_html: '<p>current</p>', parent_author: 'Author', actions: [{ handle: 'publish', title: 'Publish' }] }),
        comment({ id: 3, depth: 2, parent_id: 2, comment_html: '<p>subreply</p>', parent_author: 'Cur' }),
        comment({ id: 4, depth: 1, parent_id: 1, comment_html: '<p>sibling branch</p>', parent_author: 'Author' }),
    ],
};

beforeEach(() => {
    vi.clearAllMocks();
    axios.get = vi.fn(() => Promise.resolve({ data: THREAD }));
    axios.post = vi.fn(() => Promise.resolve({ data: {} }));
});

function makeWrapper(props = {}) {
    return mount(ThreadView, {
        props: { threadId: 'entry-1', currentId: 2, actionUrl: '/cp/meerkat/actions', permissions: { can_edit_comments: true }, ...props },
    });
}

describe('ThreadView', () => {
    it('fetches the thread by id and shows the entry title', async () => {
        const wrapper = makeWrapper();
        await flushPromises();

        expect(axios.get).toHaveBeenCalledWith(expect.stringContaining('/comments/thread/entry-1'));
        expect(wrapper.text()).toContain('Welcome Post');
    });

    it('shows only the current conversation branch by default, with a toggle to show all', async () => {
        const wrapper = makeWrapper({ currentId: 2 });
        await flushPromises();

        expect(wrapper.findAll('[data-thread-comment]')).toHaveLength(3);
        expect(wrapper.text()).not.toContain('sibling branch');

        await wrapper.find('[data-test="thread-toggle"]').trigger('click');
        await flushPromises();

        expect(wrapper.findAll('[data-thread-comment]')).toHaveLength(4);
        expect(wrapper.text()).toContain('sibling branch');
    });

    it('renders inline moderation actions for comments that expose them', async () => {
        const wrapper = makeWrapper();
        await flushPromises();

        expect(wrapper.find('[data-test="thread-action-publish"]').exists()).toBe(true);
    });

    it('opens an inline reply form and posts the reply', async () => {
        const wrapper = makeWrapper({ permissions: { can_submit_comments: true } });
        await flushPromises();

        await wrapper.find('[data-test="thread-reply-2"]').trigger('click');
        await flushPromises();

        const form = wrapper.find('[data-test="thread-reply-form-2"]');
        expect(form.exists()).toBe(true);

        await wrapper.find('textarea').setValue('a thread reply');
        await form.find('[data-text="meerkat::general.submit_reply"]').trigger('click');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith(
            expect.stringContaining('/comment/reply/2'),
            { comment: 'a thread reply' },
        );
    });

    it('hides the reply button without submit permission', async () => {
        const wrapper = makeWrapper({ permissions: { can_edit_comments: true } });
        await flushPromises();

        expect(wrapper.find('[data-test="thread-reply-2"]').exists()).toBe(false);
    });

    it('renders an empty state when the thread has no comments', async () => {
        axios.get = vi.fn(() => Promise.resolve({ data: { thread: {}, comments: [] } }));

        const wrapper = makeWrapper();
        await flushPromises();

        expect(wrapper.findAll('[data-thread-comment]')).toHaveLength(0);
        expect(wrapper.text()).toContain('meerkat::general.thread_empty');
    });
});
