<script setup>
import { computed, nextTick, onBeforeUnmount, ref, useTemplateRef, watch } from 'vue';
import axios from 'axios';
import {
    Avatar,
    Badge,
    Button,
    ConfirmationModal,
    Dropdown,
    DropdownItem,
    DropdownLabel,
    DropdownMenu,
    DropdownSeparator,
    Icon,
    Listing,
    ListingFilters,
    ListingPagination,
    ListingSearch,
    Panel,
    PanelFooter,
    Textarea,
} from '@statamic/cms/ui';
import { ItemActions } from '@statamic/cms';
import {
    SORT_OPTIONS,
    actionIcon,
    moderationBadge as resolveModerationBadge,
    partitionActions,
} from './useCommentActions.js';

const props = defineProps({
    url: { type: String, required: true },
    columns: { type: Array, default: () => [] },
    filters: { type: Array, default: () => [] },
    actionUrl: { type: String, default: '' },
    sortColumn: { type: String, default: 'created_at' },
    sortDirection: { type: String, default: 'desc' },
    preferencesPrefix: { type: String, default: 'meerkat.comments' },
    permissions: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['edit', 'request-completed', 'view-revisions', 'view-thread']);

const REPLY_DIRTY_KEY = 'meerkat-inline-reply';

const listing = useTemplateRef('listing');
const replyTextarea = useTemplateRef('replyTextarea');
const replyingId = ref(null);
const replyText = ref('');
const replySaving = ref(false);
const pendingDiscard = ref(null);
const currentSortColumn = ref(props.sortColumn);
const currentSortDirection = ref(props.sortDirection);
let escBinding = null;

const isReplyDirty = computed(() => replyText.value.trim() !== '');

const canReply = computed(() => !! props.permissions.can_submit_comments);

const currentSortLabel = computed(() => {
    const match = SORT_OPTIONS.find(
        (option) => option.column === currentSortColumn.value && option.direction === currentSortDirection.value,
    );
    return match ? __(match.labelKey) : __('meerkat::general.sort_label');
});

const listingKey = computed(() => `${currentSortColumn.value}:${currentSortDirection.value}`);

function moderationBadge(status) {
    return resolveModerationBadge(status, __);
}

function entryHref(comment) {
    const base = comment.thread?.permalink || comment.thread?.url || null;
    if (! base) return null;

    return `${base}#comment-${comment.id}`;
}

function entryTitle(thread) {
    return thread?.title || thread?.cached_title || null;
}

function openReply(comment) {
    if (replyingId.value === comment.id) return;

    attemptCloseReply(() => activateReply(comment.id));
}

function activateReply(commentId) {
    replyingId.value = commentId;
    replyText.value = '';

    nextTick(() => {
        requestAnimationFrame(() => {
            const ref = Array.isArray(replyTextarea.value) ? replyTextarea.value[0] : replyTextarea.value;
            const el = ref?.$el?.querySelector?.('textarea') ?? ref?.$el ?? ref;
            el?.focus?.();
        });
    });
}

function cancelReply() {
    attemptCloseReply();
}

function attemptCloseReply(nextAction = null) {
    if (replyingId.value === null) {
        if (nextAction) nextAction();
        return;
    }

    if (! isReplyDirty.value) {
        finalizeReplyClose();
        if (nextAction) nextAction();
        return;
    }

    pendingDiscard.value = { nextAction };
}

function finalizeReplyClose() {
    replyingId.value = null;
    replyText.value = '';
    Statamic.$dirty.remove(REPLY_DIRTY_KEY);
}

function confirmDiscard() {
    const next = pendingDiscard.value?.nextAction || null;
    pendingDiscard.value = null;
    finalizeReplyClose();
    if (next) next();
}

function cancelDiscard() {
    pendingDiscard.value = null;
}

async function submitReply(comment) {
    const body = replyText.value.trim();
    if (! body || replySaving.value) return;

    replySaving.value = true;
    Statamic.$progress.loading('meerkat-inline-reply', true);

    try {
        await axios.post(cp_url(`meerkat/comment/reply/${comment.id}`), {
            comment: body,
        });

        Statamic.$toast.success(__('meerkat::general.reply_saved'));
        finalizeReplyClose();
        listing.value?.refresh();
    } catch (error) {
        const message = error?.response?.data?.message
            || (error?.response?.data?.errors ? Object.values(error.response.data.errors).flat().join(' ') : null)
            || __('meerkat::errors.generic_failure');
        Statamic.$toast.error(message);
    } finally {
        replySaving.value = false;
        Statamic.$progress.loading('meerkat-inline-reply', false);
    }
}

function actionStarted() {
    Statamic.$progress.loading('meerkat-comment-action', true);
}

function actionCompleted(successful, response = {}) {
    Statamic.$progress.loading('meerkat-comment-action', false);

    if (successful) {
        if (response?.message !== false) {
            Statamic.$toast.success(response?.message || __('meerkat::general.action_completed'));
        }
        listing.value?.refresh();
    } else {
        Statamic.$toast.error(response?.message || __('meerkat::errors.generic_failure'));
    }
}

function applySort(option) {
    if (currentSortColumn.value === option.column && currentSortDirection.value === option.direction) return;

    currentSortColumn.value = option.column;
    currentSortDirection.value = option.direction;

    const params = new URLSearchParams(window.location.search);
    params.set('sort', option.column);
    params.set('order', option.direction);
    params.delete('page');
    window.history.replaceState({}, '', `${window.location.pathname}?${params.toString()}`);
}

watch(isReplyDirty, (dirty) => {
    if (replyingId.value !== null && dirty) {
        Statamic.$dirty.add(REPLY_DIRTY_KEY);
    } else {
        Statamic.$dirty.remove(REPLY_DIRTY_KEY);
    }
});

watch(replyingId, (id) => {
    if (id !== null && ! escBinding) {
        escBinding = Statamic.$keys.bindGlobal(['esc'], (event) => {
            event?.preventDefault?.();
            attemptCloseReply();
        });
    } else if (id === null && escBinding) {
        escBinding.destroy();
        escBinding = null;
    }
});

onBeforeUnmount(() => {
    escBinding?.destroy();
    escBinding = null;
    Statamic.$dirty.remove(REPLY_DIRTY_KEY);
});

defineExpose({
    refresh: () => listing.value?.refresh(),
});
</script>

<template>
    <Listing
        ref="listing"
        :key="listingKey"
        :url="url"
        :columns="columns"
        :filters="filters"
        :action-url="actionUrl"
        :sort-column="currentSortColumn"
        :sort-direction="currentSortDirection"
        :preferences-prefix="preferencesPrefix"
        :allow-bulk-actions="false"
        :allow-customizing-columns="false"
        push-query
        @request-completed="emit('request-completed', $event)"
        @update:sort-column="currentSortColumn = $event"
        @update:sort-direction="currentSortDirection = $event"
    >
        <template #default="{ items, loading }">
            <div class="relative overflow-clip flex items-center gap-2 sm:gap-3 min-h-16">
                <div class="flex flex-1 items-center gap-2 sm:gap-3 w-full">
                    <ListingSearch />
                    <ListingFilters v-if="filters.length" />
                </div>

                <Dropdown align="end">
                    <template #trigger>
                        <Button
                            variant="ghost"
                            size="sm"
                            :icon="currentSortDirection === 'asc' ? 'sort-asc' : 'sort-desc'"
                            :text="currentSortLabel"
                        />
                    </template>
                    <DropdownMenu>
                        <DropdownLabel :text="__('meerkat::general.sort_label')" />
                        <DropdownItem
                            v-for="option in SORT_OPTIONS"
                            :key="option.handle"
                            :text="__(option.labelKey)"
                            :icon="currentSortColumn === option.column && currentSortDirection === option.direction ? 'checkmark' : null"
                            @click="applySort(option)"
                        />
                    </DropdownMenu>
                </Dropdown>
            </div>

            <Panel>
                <div
                    v-if="!items.length && !loading"
                    class="text-center text-gray-500 text-sm py-4"
                    data-test="comment-empty-state"
                    v-text="__('meerkat::general.no_comments')"
                />

                <div
                    v-else
                    class="text-sm shadow-sm-b rounded-xl overflow-hidden"
                    :class="{ 'opacity-50': loading }"
                    data-test="comment-list"
                >
                    <article
                        v-for="(comment, index) in items"
                        :key="comment.id"
                        class="meerkat-comment-row group bg-white dark:bg-gray-850 hover:bg-gray-50 dark:hover:bg-gray-900 transition-colors border-s border-e border-gray-200 dark:border-gray-800"
                        :class="[
                            index === 0
                                ? 'border-t border-gray-200 dark:border-gray-800 rounded-t-xl'
                                : 'border-t border-gray-200 dark:border-white/10',
                            index === items.length - 1
                                ? 'border-b border-gray-200 dark:border-gray-800 rounded-b-xl'
                                : '',
                        ]"
                        :data-comment-id="comment.id"
                    >
                        <header class="meerkat-comment-row__author">
                            <Avatar :user="comment.author" class="size-10!" />
                            <div class="meerkat-comment-row__author-text">
                                <div
                                    class="meerkat-comment-row__author-name text-sm font-medium text-gray-900 dark:text-gray-200"
                                    :title="comment.author.name"
                                    v-text="comment.author.name"
                                />
                                <div
                                    v-if="comment.author.email"
                                    class="meerkat-comment-row__author-email text-xs text-gray-500 dark:text-gray-400"
                                    :title="comment.author.email"
                                    v-text="comment.author.email"
                                />
                                <div
                                    v-if="comment.author.is_guest"
                                    class="inline-flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400 mt-0.5"
                                    data-test="comment-guest-label"
                                >
                                    <Icon name="user-avatar" class="size-3" />
                                    <span>{{ __('meerkat::general.guest_author_label') }}</span>
                                </div>
                            </div>
                        </header>

                        <div class="meerkat-comment-row__main text-gray-900 dark:text-gray-200">
                            <div class="meerkat-comment-row__meta flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                                <span v-if="comment.thread" class="meerkat-comment-row__thread inline-flex items-center gap-1">
                                    <button
                                        type="button"
                                        class="meerkat-comment-row__thread-title hover:text-gray-900 dark:hover:text-gray-200 hover:underline text-start"
                                        :title="entryTitle(comment.thread) || comment.thread.id"
                                        data-test="comment-thread-title"
                                        @click="emit('view-thread', comment)"
                                    >
                                        {{ entryTitle(comment.thread) || comment.thread.id }}
                                    </button>
                                    <a
                                        v-if="entryHref(comment)"
                                        :href="entryHref(comment)"
                                        target="_blank"
                                        rel="noopener"
                                        class="shrink-0 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                                        :aria-label="__('meerkat::general.view_on_site')"
                                    >
                                        <Icon name="external-link" class="size-3" />
                                    </a>
                                </span>

                                <span v-if="comment.created_at_display" v-text="comment.created_at_display" />

                                <Badge
                                    v-if="moderationBadge(comment.moderation_status)"
                                    :color="moderationBadge(comment.moderation_status).color"
                                    :text="moderationBadge(comment.moderation_status).label"
                                    size="sm"
                                />

                                <button
                                    v-if="comment.parent_summary"
                                    type="button"
                                    class="inline-flex items-center gap-1 italic hover:text-gray-900 dark:hover:text-gray-200 hover:underline"
                                    :title="__('meerkat::general.view_thread')"
                                    data-test="comment-view-thread"
                                    @click="emit('view-thread', comment)"
                                >
                                    <Icon name="return-square" class="size-3" />
                                    {{ __('meerkat::general.in_reply_to', { name: comment.parent_summary.author_name }) }}
                                </button>
                            </div>

                            <div class="meerkat-comment-body" v-html="comment.comment_html" />

                            <ItemActions
                                v-if="actionUrl"
                                :url="actionUrl"
                                :actions="comment.actions"
                                :item="comment.id"
                                @started="actionStarted"
                                @completed="actionCompleted"
                                v-slot="{ actions: preparedActions }"
                            >
                                <div class="flex flex-wrap items-center gap-x-1 gap-y-0.5 -ms-2 opacity-60 group-hover:opacity-100 group-focus-within:opacity-100 transition-opacity">
                                    <Button
                                        v-if="canReply"
                                        size="sm"
                                        variant="ghost"
                                        icon="return-square"
                                        :text="__('meerkat::general.reply_inline_action')"
                                        data-test="comment-reply-button"
                                        @click="openReply(comment)"
                                    />

                                    <template v-for="action in partitionActions(preparedActions).primary" :key="action.handle">
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            :icon="actionIcon(action.handle)"
                                            :text="action.title"
                                            :data-test="`comment-action-${action.handle}`"
                                            @click="action.run"
                                        />
                                    </template>

                                    <Dropdown
                                        v-if="partitionActions(preparedActions).overflow.length || permissions.can_edit_comments"
                                        align="start"
                                        :aria-label="__('meerkat::general.more_actions')"
                                    >
                                        <DropdownMenu>
                                            <DropdownItem
                                                v-if="permissions.can_edit_comments"
                                                icon="edit"
                                                :text="__('meerkat::general.edit_comment')"
                                                data-test="comment-edit-item"
                                                @click="emit('edit', comment)"
                                            />
                                            <DropdownItem
                                                v-if="permissions.can_view_comments && permissions.revisions_enabled"
                                                icon="clock"
                                                :text="__('meerkat::general.view_revisions')"
                                                data-test="comment-revisions-item"
                                                @click="emit('view-revisions', comment)"
                                            />
                                            <DropdownSeparator
                                                v-if="(permissions.can_edit_comments || permissions.can_view_comments) && partitionActions(preparedActions).overflow.length"
                                            />
                                            <DropdownItem
                                                v-for="action in partitionActions(preparedActions).overflow"
                                                :key="action.handle"
                                                :icon="actionIcon(action.handle)"
                                                :text="action.title"
                                                :variant="action.dangerous ? 'destructive' : 'default'"
                                                :data-test="`comment-overflow-${action.handle}`"
                                                @click="action.run"
                                            />
                                        </DropdownMenu>
                                    </Dropdown>
                                </div>
                            </ItemActions>

                            <Transition name="meerkat-reply">
                                <div v-if="replyingId === comment.id" class="mt-2" data-test="comment-reply-form">
                                    <Textarea
                                        ref="replyTextarea"
                                        v-model="replyText"
                                        :placeholder="__('meerkat::general.reply_placeholder')"
                                        :rows="3"
                                        :disabled="replySaving"
                                        elastic
                                    />
                                    <div class="flex gap-2 justify-end mt-2">
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            :text="__('meerkat::general.cancel_reply')"
                                            :disabled="replySaving"
                                            @click="cancelReply"
                                        />
                                        <Button
                                            size="sm"
                                            variant="primary"
                                            :text="__('meerkat::general.submit_reply')"
                                            :disabled="replySaving || !replyText.trim()"
                                            @click="submitReply(comment)"
                                        />
                                    </div>
                                </div>
                            </Transition>
                        </div>
                    </article>
                </div>

                <PanelFooter v-if="items.length">
                    <ListingPagination />
                </PanelFooter>
            </Panel>

            <ConfirmationModal
                :open="!! pendingDiscard"
                :title="__('meerkat::general.unsaved_changes')"
                :body-text="__('meerkat::general.discard_changes_confirmation')"
                :button-text="__('meerkat::general.discard_changes')"
                danger
                @confirm="confirmDiscard"
                @cancel="cancelDiscard"
            />
        </template>
    </Listing>
</template>
