<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useTemplateRef, watch } from 'vue';
import axios from 'axios';
import { Avatar, Badge, Button, Dropdown, DropdownItem, DropdownMenu, Icon, Subheading, Textarea } from '@statamic/cms/ui';
import { ItemActions } from '@statamic/cms';
import { actionIcon, moderationBadge as resolveModerationBadge, partitionActions } from './useCommentActions.js';

const props = defineProps({
    threadId: { type: [Number, String], required: true },
    currentId: { type: [Number, String], default: null },
    actionUrl: { type: String, default: '' },
    permissions: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['changed']);

const comments = ref([]);
const thread = ref({});
const loading = ref(true);
const error = ref(null);
const showAll = ref(false);
const replyingId = ref(null);
const replyText = ref('');
const replySaving = ref(false);
const root = useTemplateRef('root');
const scrolled = ref(false);
let scrollContainer = null;

load();

function findScrollParent(el) {
    let node = el?.parentElement;
    while (node && node !== document.body) {
        const overflowY = getComputedStyle(node).overflowY;
        if (overflowY === 'auto' || overflowY === 'scroll' || overflowY === 'overlay') {
            return node;
        }
        node = node.parentElement;
    }
    return null;
}

function onScroll() {
    const top = scrollContainer ? scrollContainer.scrollTop : 0;

    // Hysteresis prevents oscillation at the sticky boundary.
    if (! scrolled.value && top > 28) scrolled.value = true;
    else if (scrolled.value && top < 8) scrolled.value = false;
}

onMounted(() => {
    scrollContainer = findScrollParent(root.value);

    if (scrollContainer) {
        // Resizing the header must not adjust the container's scroll position.
        scrollContainer.style.overflowAnchor = 'none';
        scrollContainer.addEventListener('scroll', onScroll, { passive: true });
    }
});

onBeforeUnmount(() => scrollContainer?.removeEventListener('scroll', onScroll));

const commentMap = computed(() => {
    const map = {};
    for (const comment of comments.value) map[comment.id] = comment;
    return map;
});

const branchIds = computed(() => {
    if (props.currentId == null) return null;

    const ids = new Set();

    let ancestor = commentMap.value[props.currentId];
    while (ancestor) {
        ids.add(ancestor.id);
        ancestor = ancestor.parent_id != null ? commentMap.value[ancestor.parent_id] : null;
    }

    const stack = [props.currentId];
    while (stack.length) {
        const parentId = stack.pop();
        for (const comment of comments.value) {
            if (comment.parent_id === parentId && ! ids.has(comment.id)) {
                ids.add(comment.id);
                stack.push(comment.id);
            }
        }
    }

    return ids;
});

const canToggle = computed(() => branchIds.value !== null && branchIds.value.size < comments.value.length);

const visibleComments = computed(() => {
    if (showAll.value || branchIds.value === null) return comments.value;
    return comments.value.filter((comment) => branchIds.value.has(comment.id));
});

async function load() {
    try {
        const { data } = await axios.get(cp_url(`meerkat/comments/thread/${encodeURIComponent(props.threadId)}`));
        comments.value = data.comments ?? [];
        thread.value = data.thread ?? {};
        await nextTick();
        scrollToCurrent();
    } catch (err) {
        error.value = err?.response?.data?.message || err?.message || __('meerkat::errors.generic_failure');
    } finally {
        loading.value = false;
    }
}

watch(showAll, () => nextTick(scrollToCurrent));

function scrollToCurrent() {
    if (props.currentId == null) return;
    requestAnimationFrame(() => {
        const el = document.querySelector(`[data-thread-comment="${props.currentId}"]`);
        el?.scrollIntoView?.({ block: 'center', behavior: 'smooth' });
    });
}

function actionStarted() {
    Statamic.$progress.loading('meerkat-thread-action', true);
}

function actionCompleted(successful, response = {}) {
    Statamic.$progress.loading('meerkat-thread-action', false);

    if (successful) {
        if (response?.message !== false) {
            Statamic.$toast.success(response?.message || __('meerkat::general.action_completed'));
        }
        load();
        emit('changed');
    } else {
        Statamic.$toast.error(response?.message || __('meerkat::errors.generic_failure'));
    }
}

function openReply(comment) {
    replyingId.value = comment.id;
    replyText.value = '';
}

function cancelReply() {
    replyingId.value = null;
    replyText.value = '';
}

async function submitReply(comment) {
    const body = replyText.value.trim();
    if (! body || replySaving.value) return;

    replySaving.value = true;
    Statamic.$progress.loading('meerkat-thread-reply', true);

    try {
        await axios.post(cp_url(`meerkat/comment/reply/${comment.id}`), { comment: body });
        Statamic.$toast.success(__('meerkat::general.reply_saved'));
        cancelReply();
        await load();
        emit('changed');
    } catch (err) {
        const message = err?.response?.data?.message
            || (err?.response?.data?.errors ? Object.values(err.response.data.errors).flat().join(' ') : null)
            || __('meerkat::errors.generic_failure');
        Statamic.$toast.error(message);
    } finally {
        replySaving.value = false;
        Statamic.$progress.loading('meerkat-thread-reply', false);
    }
}

function moderationBadge(status) {
    return resolveModerationBadge(status, __);
}

function isCurrent(comment) {
    return props.currentId != null && String(comment.id) === String(props.currentId);
}

function rowStyle(comment) {
    const style = { marginInlineStart: Math.min(comment.depth, 8) * 1.25 + 'rem' };

    if (visibleComments.value.length > 20) {
        style.contentVisibility = 'auto';
        style.containIntrinsicSize = 'auto 5rem';
    }

    return style;
}

function formatDate(iso) {
    if (! iso) return '';
    try {
        const date = new Date(iso);
        return date.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' })
            + ' · ' + date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    } catch {
        return iso;
    }
}
</script>

<template>
    <div ref="root">
        <div v-if="loading" class="flex items-center justify-center py-16 text-gray-500">
            <Icon name="loading" />
        </div>

        <div
            v-else-if="error"
            class="m-6 rounded-lg border border-red-200 bg-red-50 dark:bg-red-900/20 dark:border-red-800 p-4 text-sm text-red-800 dark:text-red-300"
            v-text="error"
        />

        <div
            v-else-if="!comments.length"
            class="m-6 rounded-lg border border-dashed border-gray-300 dark:border-gray-700 p-6 text-center text-sm text-gray-500"
            v-text="__('meerkat::general.thread_empty')"
        />

        <template v-else>
            <div
                class="meerkat-thread-header bg-content-bg dark:bg-dark-content-bg"
                :class="{ 'is-condensed': scrolled }"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <a
                            v-if="thread.url"
                            :href="thread.url"
                            target="_blank"
                            rel="noopener"
                            class="meerkat-thread-header__title text-gray-900 dark:text-gray-100 hover:underline"
                        >{{ thread.title || thread.id }}</a>
                        <div
                            v-else
                            class="meerkat-thread-header__title text-gray-900 dark:text-gray-100"
                        >{{ thread.title || thread.id }}</div>
                        <div class="meerkat-thread-header__count">
                            <Subheading class="text-xs">{{ __n('meerkat::general.thread_comments_count', comments.length) }}</Subheading>
                        </div>
                    </div>

                    <Button
                        v-if="canToggle"
                        size="sm"
                        variant="ghost"
                        class="shrink-0"
                        :text="showAll ? __('meerkat::general.thread_show_conversation') : __('meerkat::general.thread_show_all')"
                        data-test="thread-toggle"
                        @click="showAll = ! showAll"
                    />
                </div>
            </div>

            <ol class="space-y-2 px-6 pb-6 pt-2">
                <li
                    v-for="comment in visibleComments"
                    :key="comment.id"
                    :data-thread-comment="comment.id"
                    :style="rowStyle(comment)"
                    class="meerkat-thread-row group rounded-lg border px-3 py-2.5 text-sm transition-colors"
                    :class="isCurrent(comment)
                        ? 'is-current'
                        : 'border-gray-200 dark:border-gray-800'"
                >
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                        <Avatar :user="comment.author" class="size-6 shrink-0" />
                        <span class="font-medium text-gray-800 dark:text-gray-200">{{ comment.author.name }}</span>
                        <span v-if="comment.author.is_guest" class="inline-flex items-center gap-1">
                            <Icon name="user-avatar" class="size-3" />
                            {{ __('meerkat::general.guest_author_label') }}
                        </span>
                        <span v-if="comment.created_at" v-text="formatDate(comment.created_at)" />
                        <Badge
                            v-if="moderationBadge(comment.moderation_status)"
                            :color="moderationBadge(comment.moderation_status).color"
                            :text="moderationBadge(comment.moderation_status).label"
                            size="sm"
                        />
                        <span v-if="comment.parent_author" class="inline-flex items-center gap-1 italic">
                            <Icon name="return-square" class="size-3" />
                            {{ __('meerkat::general.in_reply_to', { name: comment.parent_author }) }}
                        </span>
                    </div>

                    <div class="meerkat-comment-body mt-1.5 text-gray-900 dark:text-gray-200" v-html="comment.comment_html" />

                    <div
                        v-if="permissions.can_submit_comments || (actionUrl && comment.actions && comment.actions.length)"
                        class="flex flex-wrap items-center gap-1 mt-2 -ms-2 opacity-60 group-hover:opacity-100 group-focus-within:opacity-100 transition-opacity"
                    >
                        <Button
                            v-if="permissions.can_submit_comments"
                            size="sm"
                            variant="ghost"
                            icon="return-square"
                            :text="__('meerkat::general.reply_inline_action')"
                            :data-test="`thread-reply-${comment.id}`"
                            @click="openReply(comment)"
                        />

                        <ItemActions
                            v-if="actionUrl && comment.actions && comment.actions.length"
                            :url="actionUrl"
                            :actions="comment.actions"
                            :item="comment.id"
                            @started="actionStarted"
                            @completed="actionCompleted"
                            v-slot="{ actions: preparedActions }"
                        >
                            <span class="flex flex-wrap items-center gap-1">
                                <Button
                                    v-for="action in partitionActions(preparedActions).primary"
                                    :key="action.handle"
                                    size="sm"
                                    variant="ghost"
                                    :icon="actionIcon(action.handle)"
                                    :text="action.title"
                                    :data-test="`thread-action-${action.handle}`"
                                    @click="action.run"
                                />
                                <Dropdown
                                    v-if="partitionActions(preparedActions).overflow.length"
                                    align="start"
                                    :aria-label="__('meerkat::general.more_actions')"
                                >
                                    <DropdownMenu>
                                        <DropdownItem
                                            v-for="action in partitionActions(preparedActions).overflow"
                                            :key="action.handle"
                                            :icon="actionIcon(action.handle)"
                                            :text="action.title"
                                            :variant="action.dangerous ? 'destructive' : 'default'"
                                            @click="action.run"
                                        />
                                    </DropdownMenu>
                                </Dropdown>
                            </span>
                        </ItemActions>
                    </div>

                    <div
                        v-if="replyingId === comment.id"
                        class="mt-2"
                        :data-test="`thread-reply-form-${comment.id}`"
                    >
                        <Textarea
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
                </li>
            </ol>
        </template>
    </div>
</template>
