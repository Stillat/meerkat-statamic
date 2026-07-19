import { describe, expect, it, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import Comments from '../Comments.vue';

function makeWrapper({ permissions = {} } = {}) {
    return mount(Comments, {
        props: {
            actionUrl: '/cp/meerkat/actions',
            columns: [],
            filters: [],
            permissions,
        },
        global: {
            mocks: {
                $preferences: Statamic.$preferences,
                $progress: Statamic.$progress,
                $dirty: Statamic.$dirty,
                $toast: Statamic.$toast,
                $axios: { post: vi.fn(() => Promise.resolve({ data: {} })) },
            },
            stubs: {
                Listing: { name: 'Listing', template: '<div data-stub="Listing" />' },
                CommentView: { name: 'CommentView', template: '<div data-stub="CommentView" />' },
                CommentEditor: { name: 'CommentEditor', template: '<div data-stub="CommentEditor" />' },
                CommentRevisions: { name: 'CommentRevisions', template: '<div data-stub="CommentRevisions" />' },
            },
        },
    });
}

beforeEach(() => {
    vi.clearAllMocks();
});

describe('Comments — check-pending-for-spam confirmation', () => {
    it('does not render the ConfirmationModal initially', () => {
        const wrapper = makeWrapper({ permissions: { can_check_comment_spam: true } });
        expect(wrapper.find('[data-stub="ConfirmationModal"]').exists()).toBe(false);
    });

    it('renders the ConfirmationModal once check-pending-for-spam is requested', async () => {
        const wrapper = makeWrapper({ permissions: { can_check_comment_spam: true } });

        wrapper.vm.confirmCheckOutstandingForSpam = true;
        await flushPromises();

        expect(wrapper.find('[data-stub="ConfirmationModal"]').exists()).toBe(true);
        expect(wrapper.find('[data-stub="ConfirmationModal"]').attributes('data-open')).toBe('true');
    });

    it('renders the ConfirmationModal when the dropdown item is clicked', async () => {
        const wrapper = makeWrapper({ permissions: { can_check_comment_spam: true } });
        expect(wrapper.find('[data-stub="ConfirmationModal"]').exists()).toBe(false);

        const item = wrapper.findAll('[data-stub="DropdownItem"]').find(
            (el) => el.attributes('data-text') === 'meerkat::general.check_pending_for_spam',
        );
        expect(item).toBeDefined();
        await item.trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-stub="ConfirmationModal"]').exists()).toBe(true);
    });

    it('hides the ConfirmationModal again when cancelled', async () => {
        const wrapper = makeWrapper({ permissions: { can_check_comment_spam: true } });
        wrapper.vm.confirmCheckOutstandingForSpam = true;
        await flushPromises();

        await wrapper.find('[data-test="discard-cancel"]').trigger('click');
        await flushPromises();

        expect(wrapper.vm.confirmCheckOutstandingForSpam).toBe(false);
        expect(wrapper.find('[data-stub="ConfirmationModal"]').exists()).toBe(false);
    });
});

describe('Comments — discard-changes confirmation', () => {
    it('does not render the ConfirmationModal initially', () => {
        const wrapper = makeWrapper();
        expect(wrapper.find('[data-stub="ConfirmationModal"]').exists()).toBe(false);
    });

    it('renders the ConfirmationModal when a discard is pending', async () => {
        const wrapper = makeWrapper();

        wrapper.vm.pendingDiscard = 'reply';
        await flushPromises();

        expect(wrapper.find('[data-stub="ConfirmationModal"]').exists()).toBe(true);
        expect(wrapper.find('[data-stub="ConfirmationModal"]').attributes('data-open')).toBe('true');
    });

    it('hides the ConfirmationModal when the discard is dismissed', async () => {
        const wrapper = makeWrapper();
        wrapper.vm.pendingDiscard = 'reply';
        await flushPromises();

        await wrapper.find('[data-test="discard-cancel"]').trigger('click');
        await flushPromises();

        expect(wrapper.vm.pendingDiscard).toBe(null);
        expect(wrapper.find('[data-stub="ConfirmationModal"]').exists()).toBe(false);
    });
});
