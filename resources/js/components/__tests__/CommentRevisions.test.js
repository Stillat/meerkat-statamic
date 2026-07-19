import { describe, expect, it, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import axios from 'axios';
import CommentRevisions from '../CommentRevisions.vue';

vi.mock('axios');

const REVISIONS = [
    { id: 2, revision_number: 2, comment_text: 'v2', current: true, edited_at: '2026-06-28T14:44:00Z', user: { name: 'John', email: 'j@e.com' } },
    { id: 1, revision_number: 1, comment_text: 'v1', current: false, edited_at: '2026-06-28T11:32:00Z', user: { name: 'John', email: 'j@e.com' } },
];

beforeEach(() => {
    vi.clearAllMocks();
    axios.get = vi.fn(() => Promise.resolve({ data: { revisions: REVISIONS } }));
    axios.post = vi.fn(() => Promise.resolve({ data: { restored: true } }));
});

function makeWrapper(props = {}) {
    return mount(CommentRevisions, { props: { commentId: 5, canRestore: true, ...props } });
}

describe('CommentRevisions — restore', () => {
    it('shows a restore button only on non-current revisions when allowed', async () => {
        const wrapper = makeWrapper();
        await flushPromises();

        const buttons = wrapper.findAll('[data-test="revision-restore"]');
        expect(buttons).toHaveLength(1);
    });

    it('hides restore buttons when the user cannot edit', async () => {
        const wrapper = makeWrapper({ canRestore: false });
        await flushPromises();

        expect(wrapper.find('[data-test="revision-restore"]').exists()).toBe(false);
    });

    it('confirms, posts the restore, and emits restored', async () => {
        const wrapper = makeWrapper();
        await flushPromises();

        expect(wrapper.find('[data-stub="ConfirmationModal"]').exists()).toBe(false);

        await wrapper.find('[data-test="revision-restore"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-stub="ConfirmationModal"]').exists()).toBe(true);

        await wrapper.find('[data-test="discard-confirm"]').trigger('click');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith(expect.stringContaining('/comment/5/revisions/1/restore'));
        expect(wrapper.emitted('restored')).toBeTruthy();
    });
});
