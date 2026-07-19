<template>
    <div>
        <Header :title="__('meerkat::general.dashboard_title')" icon="mail-chat-bubble-text">
            <Dropdown placement="left-start">
                <DropdownMenu>
                    <DropdownItem
                        v-if="permissions.can_check_comment_spam"
                        :text="__('meerkat::general.check_pending_for_spam')"
                        icon="clipboard-check"
                        @click="confirmCheckOutstandingForSpam = true"
                    />
                    <DropdownSeparator v-if="permissions.can_check_comment_spam" />
                    <DropdownItem
                        :text="__('meerkat::general.export_comments')"
                        icon="download"
                        :href="exportUrl('csv')"
                        target="_blank"
                    />
                    <DropdownItem
                        :text="__('meerkat::general.export_comments_json')"
                        icon="download"
                        :href="exportUrl('json')"
                        target="_blank"
                    />
                </DropdownMenu>
            </Dropdown>

            <ui-toggle-group v-model="view">
                <ui-toggle-item icon="layout-list" value="table" v-tooltip="__('meerkat::general.view_table')" />
                <ui-toggle-item icon="mail-chat-bubble-text" value="comments" v-tooltip="__('meerkat::general.view_comments')" />
            </ui-toggle-group>
        </Header>

        <ConfirmationModal
            :open="!! confirmCheckOutstandingForSpam"
            :title="__('meerkat::general.check_pending_for_spam')"
            :body-text="__('meerkat::general.check_pending_for_spam_desc')"
            :button-text="__('meerkat::general.check_pending_for_spam')"
            danger
            @confirm="checkOutstandingForSpam"
            @cancel="confirmCheckOutstandingForSpam = false"
        />

        <Listing
            v-if="view === 'table'"
            ref="listing"
            :url="requestUrl"
            :columns="columns"
            :filters="filters"
            :action-url="actionUrl"
            :sort-column="sortColumn"
            :sort-direction="sortDirection"
            :preferences-prefix="preferencesPrefix"
            push-query
        >
            <template #cell-comment_text="{ row: comment }">
                <button
                    v-if="permissions.can_edit_comments"
                    type="button"
                    class="title-index-field text-start"
                    @click="openEdit(comment)"
                    v-text="truncate(comment.comment_text)"
                />
                <span v-else v-text="truncate(comment.comment_text)" />
            </template>

            <template #cell-moderation_status="{ row: comment }">
                <Badge
                    v-if="comment.moderation_status"
                    :color="moderationBadgeColor(comment.moderation_status)"
                    :text="moderationLabel(comment.moderation_status)"
                    size="sm"
                />
            </template>

            <template #prepended-row-actions="{ row: comment }">
                <DropdownItem
                    v-if="permissions.can_submit_comments"
                    :text="__('meerkat::general.reply')"
                    icon="return-square"
                    @click="openReply(comment)"
                />
                <DropdownItem
                    v-if="permissions.can_edit_comments"
                    :text="__('meerkat::general.edit_comment')"
                    icon="edit"
                    @click="openEdit(comment)"
                />
                <DropdownItem
                    v-if="permissions.can_view_comments && permissions.revisions_enabled"
                    :text="__('meerkat::general.view_revisions')"
                    icon="clock"
                    @click="openRevisions(comment)"
                />
            </template>
        </Listing>

        <CommentView
            v-else
            ref="commentView"
            :url="requestUrl"
            :columns="columns"
            :filters="filters"
            :action-url="actionUrl"
            :sort-column="sortColumn"
            :sort-direction="sortDirection"
            :preferences-prefix="preferencesPrefix"
            :permissions="permissions"
            @edit="openEdit"
            @view-revisions="openRevisions"
            @view-thread="openThread"
        />

        <Stack
            v-if="replyingComment"
            open
            :before-close="shouldCloseReply"
            size="half"
            @closed="replyingComment = null"
        >
            <StackHeader :title="__('meerkat::general.reply_title')">
                <template #actions>
                    <Button
                        variant="primary"
                        :text="__('meerkat::general.reply')"
                        :disabled="replyEditorSaving"
                        @click="$refs.replyEditor.save()"
                    />
                </template>
            </StackHeader>
            <StackContent>
                <CommentEditor
                    ref="replyEditor"
                    mode="reply"
                    :blueprint="blueprint"
                    :initial-meta="meta"
                    :comment="replyingComment"
                    :publish-container="replyContainerName"
                    @saving="replyEditorSaving = $event"
                    @saved="handleReplySaved"
                />
            </StackContent>
        </Stack>

        <Stack
            v-if="editingComment"
            open
            :before-close="shouldCloseEdit"
            size="half"
            @closed="editingComment = null"
        >
            <StackHeader :title="__('meerkat::general.edit_comment_title')">
                <template #actions>
                    <Button
                        variant="primary"
                        :text="__('meerkat::general.save')"
                        :disabled="editEditorSaving"
                        @click="$refs.editEditor.save()"
                    />
                </template>
            </StackHeader>
            <StackContent>
                <CommentEditor
                    ref="editEditor"
                    mode="edit"
                    :blueprint="blueprint"
                    :initial-meta="meta"
                    :comment="editingComment"
                    :publish-container="editContainerName"
                    @saving="editEditorSaving = $event"
                    @saved="handleEditSaved"
                />
            </StackContent>
        </Stack>

        <Stack
            v-if="revisionsComment"
            open
            size="half"
            @closed="revisionsComment = null"
        >
            <StackHeader :title="__('meerkat::general.revisions_title')" />
            <StackContent>
                <CommentRevisions
                    :comment-id="revisionsComment.id"
                    :can-restore="permissions.can_edit_comments"
                    @restored="refreshActiveView"
                />
            </StackContent>
        </Stack>

        <Stack
            v-if="threadComment"
            open
            size="half"
            @closed="threadComment = null"
        >
            <StackHeader :title="__('meerkat::general.thread_title')" />
            <StackContent inset>
                <ThreadView
                    :thread-id="threadComment.thread_id || threadComment.thread?.id"
                    :current-id="threadComment.id"
                    :action-url="actionUrl"
                    :permissions="permissions"
                    @changed="refreshActiveView"
                />
            </StackContent>
        </Stack>

        <ConfirmationModal
            :open="!! pendingDiscard"
            :title="__('meerkat::general.unsaved_changes')"
            :body-text="__('meerkat::general.discard_changes_confirmation')"
            :button-text="__('meerkat::general.discard_changes')"
            danger
            @confirm="confirmDiscard"
            @cancel="pendingDiscard = null"
        />
    </div>
</template>

<script>
import {
    Badge,
    Button,
    ConfirmationModal,
    Dropdown,
    DropdownItem,
    DropdownMenu,
    DropdownSeparator,
    Header,
    Listing,
    Stack,
    StackContent,
    StackHeader,
} from '@statamic/cms/ui';
import CommentEditor from './CommentEditor.vue';
import CommentRevisions from './CommentRevisions.vue';
import CommentView from './CommentView.vue';
import ThreadView from './ThreadView.vue';
import HandlesRequestErrors from './HandlesRequestErrors.vue';
import { moderationBadge } from './useCommentActions.js';

export default {
    components: {
        Badge,
        Button,
        CommentEditor,
        CommentRevisions,
        CommentView,
        ConfirmationModal,
        Dropdown,
        DropdownItem,
        DropdownMenu,
        DropdownSeparator,
        Header,
        Listing,
        Stack,
        StackContent,
        StackHeader,
        ThreadView,
    },

    mixins: [HandlesRequestErrors],

    props: {
        site: { type: String, default: null },
        columns: { type: Array, default: () => [] },
        blueprint: { type: Object, default: () => ({}) },
        meta: { type: Object, default: () => ({}) },
        filters: { type: Array, default: () => [] },
        actionUrl: { type: String, default: '' },
        permissions: { type: Object, default: () => ({}) },
        sortColumn: { type: String, default: 'created_at' },
        sortDirection: { type: String, default: 'desc' },
    },

    data() {
        return {
            checkingForSpam: false,
            confirmCheckOutstandingForSpam: false,
            editingComment: null,
            replyingComment: null,
            revisionsComment: null,
            threadComment: null,
            replyEditorSaving: false,
            editEditorSaving: false,
            pendingDiscard: null,
            preferencesPrefix: 'meerkat.comments',
            requestUrl: cp_url('meerkat/comments/filter'),
            view: this.$preferences.get('meerkat.comments.view', 'table'),
        };
    },

    computed: {
        replyContainerName() {
            return this.replyingComment ? `meerkat-comment-reply-${this.replyingComment.id}` : null;
        },
        editContainerName() {
            return this.editingComment ? `meerkat-comment-edit-${this.editingComment.id}` : null;
        },
    },

    watch: {
        checkingForSpam(checking) {
            this.$progress.loading('meerkat-spam-check', checking);
        },
        view(value) {
            this.$preferences.set('meerkat.comments.view', value);
        },
    },

    methods: {
        exportUrl(format) {
            return cp_url(`meerkat/comments/export?format=${format}`);
        },

        truncate(text, length = 100) {
            if (!text) return '';
            return text.length > length ? text.substring(0, length).trimEnd() + '…' : text;
        },

        moderationBadgeColor(status) {
            return moderationBadge(status)?.color || 'default';
        },

        moderationLabel(status) {
            return moderationBadge(status, __)?.label || status;
        },

        openReply(comment) {
            this.editingComment = null;
            this.replyingComment = comment;
        },

        openEdit(comment) {
            this.replyingComment = null;
            this.editingComment = comment;
        },

        openRevisions(comment) {
            this.revisionsComment = comment;
        },

        openThread(comment) {
            this.threadComment = comment;
        },

        shouldCloseReply() {
            return this.shouldClose('reply', this.replyContainerName);
        },

        shouldCloseEdit() {
            return this.shouldClose('edit', this.editContainerName);
        },

        shouldClose(mode, containerName) {
            if (!containerName) return true;

            if (this.$dirty.has(containerName)) {
                this.pendingDiscard = mode;
                return false;
            }

            return true;
        },

        confirmDiscard() {
            const mode = this.pendingDiscard;
            this.pendingDiscard = null;

            if (mode === 'reply') {
                if (this.replyContainerName) this.$dirty.remove(this.replyContainerName);
                this.replyingComment = null;
            } else if (mode === 'edit') {
                if (this.editContainerName) this.$dirty.remove(this.editContainerName);
                this.editingComment = null;
            }
        },

        handleReplySaved() {
            if (this.replyContainerName) this.$dirty.remove(this.replyContainerName);
            this.replyingComment = null;
            this.refreshActiveView();
        },

        handleEditSaved() {
            if (this.editContainerName) this.$dirty.remove(this.editContainerName);
            this.editingComment = null;
            this.refreshActiveView();
        },

        refreshActiveView() {
            (this.$refs.listing || this.$refs.commentView)?.refresh();
        },

        checkOutstandingForSpam() {
            this.checkingForSpam = true;
            this.$axios.post(cp_url('meerkat/comments/check-outstanding')).then(() => {
                this.checkingForSpam = false;
                this.confirmCheckOutstandingForSpam = false;
                this.$toast.success(__('meerkat::general.spam_check_request_submitted'));
                this.refreshActiveView();
            }).catch(err => {
                this.handleAxiosError(err);
                this.checkingForSpam = false;
            });
        },
    },
};
</script>
