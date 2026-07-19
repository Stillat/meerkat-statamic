import { defineComponent, h } from 'vue';

export const ItemActions = defineComponent({
    name: 'ItemActions',
    inheritAttrs: false,
    props: ['url', 'actions', 'item', 'context', 'isDirty'],
    emits: ['started', 'completed'],
    setup(props, { slots }) {
        return () => {
            const actions = (props.actions || []).map((action) => ({
                ...action,
                run: () => {},
            }));
            return h('div', { 'data-stub': 'ItemActions' }, slots.default
                ? slots.default({ actions, loadActions: () => Promise.resolve(actions), loading: false })
                : null);
        };
    },
});
