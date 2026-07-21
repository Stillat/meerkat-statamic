<script setup>
import { computed, onBeforeUnmount, onMounted, ref, useTemplateRef, watch } from 'vue';
import axios from 'axios';
import { Icon, PublishContainer } from '@statamic/cms/ui';
import {
    AfterSaveHooks,
    BeforeSaveHooks,
    Pipeline,
    PipelineStopped,
    Request,
} from '@statamic/cms/save-pipeline';

const props = defineProps({
    blueprint: { type: Object, required: true },
    initialMeta: { type: Object, required: true },
    comment: { type: Object, required: true },
    mode: { type: String, required: true },
    publishContainer: { type: String, required: true },
});

const emit = defineEmits(['saved', 'saving']);

const container = useTemplateRef('container');
const loading = ref(true);
const loadFailed = ref(false);
const meta = ref(clone(props.initialMeta));
const values = ref({});
const errors = ref({});
const saving = ref(false);

const isReplyMode = computed(() => props.mode === 'reply');

const fetchUrl = computed(() => isReplyMode.value
    ? cp_url(`meerkat/comment/reply-data/${props.comment.id}`)
    : cp_url(`meerkat/comment/${props.comment.id}`));

const saveUrl = computed(() => isReplyMode.value
    ? cp_url(`meerkat/comment/reply/${props.comment.id}`)
    : cp_url(`meerkat/comment/${props.comment.id}`));

const saveMethod = computed(() => isReplyMode.value ? 'post' : 'put');

const successMessage = computed(() => isReplyMode.value
    ? __('meerkat::general.reply_saved')
    : __('meerkat::general.comment_saved'));

watch(saving, (busy) => {
    emit('saving', busy);
    Statamic.$progress.loading('meerkat-comment-editor', busy);
});

function fetch() {
    loading.value = true;
    loadFailed.value = false;

    axios
        .get(fetchUrl.value)
        .then(({ data }) => {
            meta.value = data.meta;
            values.value = data.values;
            loading.value = false;
        })
        .catch((err) => {
            Statamic.$toast.error(err.response?.data?.message || err?.message || __('meerkat::errors.generic_failure'));
            loadFailed.value = true;
            loading.value = false;
        });
}

function save() {
    // Never save against an empty form that failed to load; doing so would
    // blank the comment.
    if (saving.value || loading.value || loadFailed.value) return;

    const hookPayload = {
        comment: props.comment,
        mode: props.mode,
    };

    new Pipeline()
        .provide({ container, errors, saving })
        .through([
            new BeforeSaveHooks('meerkat.comment', { ...hookPayload, values: values.value }),
            new Request(saveUrl.value, saveMethod.value, {}),
            new AfterSaveHooks('meerkat.comment', hookPayload),
        ])
        .then((response) => {
            Statamic.$toast.success(successMessage.value);
            emit('saved', response);
        })
        .catch((e) => {
            if (!(e instanceof PipelineStopped)) {
                Statamic.$toast.error(__('meerkat::errors.generic_failure'));
                console.error(e);
            }
        });
}

let saveKeyBinding = null;

onMounted(() => {
    fetch();

    saveKeyBinding = Statamic.$keys.bindGlobal(['mod+s'], (e) => {
        e.preventDefault();
        save();
    });
});

onBeforeUnmount(() => {
    saveKeyBinding?.destroy();
});

defineExpose({ save });
</script>

<template>
    <div>
        <div v-if="loading" class="flex items-center justify-center py-16 text-gray-500">
            <Icon name="loading" />
        </div>

        <div v-else-if="loadFailed" class="flex flex-col items-center justify-center py-16 text-gray-500">
            <p class="mb-4">{{ __('meerkat::errors.editor_load_failed') }}</p>
            <button type="button" class="btn" @click="fetch">{{ __('Retry') }}</button>
        </div>

        <PublishContainer
            v-else
            ref="container"
            :name="publishContainer"
            :blueprint="blueprint"
            :meta="meta"
            :errors="errors"
            v-model="values"
        />
    </div>
</template>
