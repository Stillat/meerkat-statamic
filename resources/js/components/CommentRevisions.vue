<script setup>
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import { Avatar, Badge, Button, ConfirmationModal, Icon, Subheading } from '@statamic/cms/ui';

const props = defineProps({
    commentId: { type: [Number, String], required: true },
    canRestore: { type: Boolean, default: false },
});

const emit = defineEmits(['restored']);

const revisions = ref([]);
const loading = ref(true);
const error = ref(null);
const pendingRestore = ref(null);
const restoring = ref(false);

const fetchUrl = computed(() => cp_url(`meerkat/comment/${props.commentId}/revisions`));

const groups = computed(() => {
    const result = [];
    let current = null;

    for (const revision of revisions.value) {
        const day = revision.edited_at ? new Date(revision.edited_at).toDateString() : '';

        if (! current || current.day !== day) {
            current = { day, label: dayHeading(revision.edited_at), revisions: [] };
            result.push(current);
        }

        current.revisions.push(revision);
    }

    return result;
});

async function loadRevisions() {
    try {
        const { data } = await axios.get(fetchUrl.value);
        revisions.value = data.revisions ?? [];
    } catch (err) {
        error.value = err?.response?.data?.message || err?.message || __('meerkat::errors.generic_failure');
    } finally {
        loading.value = false;
    }
}

onMounted(loadRevisions);

async function restore() {
    if (! pendingRestore.value || restoring.value) return;

    const revision = pendingRestore.value;
    restoring.value = true;

    try {
        await axios.post(cp_url(`meerkat/comment/${props.commentId}/revisions/${revision.revision_number}/restore`));
        await loadRevisions();
        emit('restored');
        Statamic.$toast.success(__('meerkat::general.revision_restored'));
    } catch (err) {
        Statamic.$toast.error(err?.response?.data?.message || __('meerkat::errors.generic_failure'));
    } finally {
        restoring.value = false;
        pendingRestore.value = null;
    }
}

function dayHeading(iso) {
    if (! iso) return '';
    try {
        const date = new Date(iso);
        const startOfToday = new Date();
        startOfToday.setHours(0, 0, 0, 0);

        return date >= startOfToday
            ? __('meerkat::general.revision_today')
            : date.toLocaleDateString([], { year: 'numeric', month: 'long', day: 'numeric' });
    } catch {
        return iso;
    }
}

function formatTime(iso) {
    if (! iso) return '';
    try {
        return new Date(iso).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    } catch {
        return iso;
    }
}
</script>

<template>
    <div class="p-6">
        <div v-if="loading" class="flex items-center justify-center py-16 text-gray-500">
            <Icon name="loading" />
        </div>

        <div
            v-else-if="error"
            class="rounded-lg border border-red-200 bg-red-50 dark:bg-red-900/20 dark:border-red-800 p-4 text-sm text-red-800 dark:text-red-300"
            v-text="error"
        />

        <div
            v-else-if="!revisions.length"
            class="rounded-lg border border-dashed border-gray-300 dark:border-gray-700 p-6 text-center text-sm text-gray-500"
            v-text="__('meerkat::general.no_revisions')"
        />

        <template v-else>
            <div v-for="group in groups" :key="group.day">
                <Subheading class="px-3 py-2 text-gray-600! dark:text-gray-300!" v-text="group.label" />

                <ol class="relative grid gap-3">
                    <div class="absolute inset-y-0 left-6 top-3 border-l border-gray-400 dark:border-gray-600 border-dashed" />

                    <li
                        v-for="revision in group.revisions"
                        :key="revision.id"
                        class="relative block px-3 py-2.5 text-sm"
                        :class="{
                            'border border-ui-accent-bg dark:border-ui-accent-bg/90 rounded-lg bg-[hsl(from_var(--theme-color-ui-accent-bg)_h_s_97)] dark:bg-[hsl(from_var(--theme-color-ui-accent-bg)_h_40_20)]': revision.current,
                        }"
                    >
                        <div class="flex gap-3">
                            <Avatar
                                v-if="revision.user"
                                :user="revision.user"
                                class="size-6 shrink-0 mt-1"
                            />
                            <div
                                v-else
                                class="size-6 shrink-0 mt-1 rounded-full bg-gray-200 dark:bg-gray-700 flex items-center justify-center text-xs text-gray-500"
                                :title="__('meerkat::general.revision_unknown_user')"
                            >?</div>

                            <div class="grid gap-1 min-w-0 flex-1">
                                <div
                                    v-if="revision.edit_reason"
                                    class="font-medium"
                                    v-text="revision.edit_reason"
                                />
                                <Subheading
                                    class="text-xs text-gray-500! dark:text-gray-400!"
                                    :class="{ 'text-gray-800! dark:text-white!': revision.current }"
                                >
                                    {{ formatTime(revision.edited_at) }}
                                    <template v-if="revision.user">
                                        · {{ __('meerkat::general.revision_by', { user: revision.user.name || revision.user.email }) }}
                                    </template>
                                </Subheading>
                            </div>

                            <div class="flex items-center gap-2 ml-auto">
                                <Button
                                    v-if="canRestore && ! revision.current"
                                    size="sm"
                                    variant="ghost"
                                    icon="undo"
                                    :text="__('meerkat::general.restore_revision')"
                                    data-test="revision-restore"
                                    @click="pendingRestore = revision"
                                />
                                <Badge
                                    size="sm"
                                    :color="revision.current ? 'green' : 'gray'"
                                    :text="revision.current
                                        ? __('meerkat::general.revision_current_badge')
                                        : __('meerkat::general.revision_label', { number: revision.revision_number })"
                                />
                            </div>
                        </div>

                        <pre
                            v-if="revision.comment_text"
                            class="mt-2 ml-9 whitespace-pre-wrap break-words text-sm text-gray-700 dark:text-gray-300 font-mono"
                            v-text="revision.comment_text"
                        />
                    </li>
                </ol>
            </div>
        </template>

        <ConfirmationModal
            :open="!! pendingRestore"
            :title="__('meerkat::general.restore_revision')"
            :body-text="__('meerkat::general.restore_revision_confirmation')"
            :button-text="__('meerkat::general.restore_revision')"
            :busy="restoring"
            @confirm="restore"
            @cancel="pendingRestore = null"
        />
    </div>
</template>
