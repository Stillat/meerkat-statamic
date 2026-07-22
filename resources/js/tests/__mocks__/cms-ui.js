import { defineComponent, h } from 'vue';

function stub(name, options = {}) {
    return defineComponent({
        name,
        inheritAttrs: false,
        props: options.props || [
            'text', 'icon', 'iconAppend', 'variant', 'size', 'color', 'pill',
            'disabled', 'align', 'side', 'placement', 'href', 'target',
            'name', 'user', 'rows', 'title', 'bodyText', 'buttonText', 'danger',
        ],
        setup(props, { slots, attrs }) {
            return () => h(
                options.tag || 'div',
                {
                    'data-stub': name,
                    'data-text': props.text,
                    'data-icon': props.icon,
                    'data-variant': props.variant,
                    'data-color': props.color,
                    ...attrs,
                },
                slots.default ? slots.default(options.slotProps || {}) : (props.text ?? ''),
            );
        },
    });
}

export const Avatar = stub('Avatar');
export const Badge = stub('Badge');
export const Button = stub('Button', { tag: 'button' });
export const Header = stub('Header');
export const Subheading = stub('Subheading');
export const Stack = stub('Stack');
export const StackContent = stub('StackContent');
export const StackHeader = stub('StackHeader');
export const ConfirmationModal = defineComponent({
    name: 'ConfirmationModal',
    inheritAttrs: false,
    props: ['title', 'bodyText', 'buttonText', 'danger', 'open'],
    emits: ['confirm', 'cancel'],
    setup(props, { emit }) {
        return () => props.open
            ? h('div', { 'data-stub': 'ConfirmationModal', 'data-open': 'true' }, [
                h('button', { 'data-test': 'discard-confirm', onClick: () => emit('confirm') }, props.buttonText ?? ''),
                h('button', { 'data-test': 'discard-cancel', onClick: () => emit('cancel') }, 'cancel'),
            ])
            : null;
    },
});
export const Dropdown = defineComponent({
    name: 'Dropdown',
    inheritAttrs: false,
    setup(_, { slots }) {
        return () => h('div', { 'data-stub': 'Dropdown' }, [
            slots.trigger ? slots.trigger() : null,
            slots.default ? slots.default() : null,
        ]);
    },
});
export const DropdownItem = stub('DropdownItem', { tag: 'button' });
export const DropdownLabel = stub('DropdownLabel');
export const DropdownMenu = stub('DropdownMenu');
export const DropdownSeparator = stub('DropdownSeparator');
export const Icon = stub('Icon');
export const ListingFilters = stub('ListingFilters');
export const ListingPagination = stub('ListingPagination');
export const ListingSearch = stub('ListingSearch');
export const Panel = stub('Panel');
export const PanelFooter = stub('PanelFooter');
export const PublishContainer = stub('PublishContainer');

export const Textarea = defineComponent({
    name: 'Textarea',
    inheritAttrs: false,
    props: ['modelValue', 'placeholder', 'rows', 'disabled', 'elastic'],
    emits: ['update:modelValue'],
    setup(props, { emit, attrs }) {
        return () => h('textarea', {
            'data-stub': 'Textarea',
            value: props.modelValue,
            placeholder: props.placeholder,
            rows: props.rows,
            disabled: props.disabled,
            onInput: (e) => emit('update:modelValue', e.target.value),
            ...attrs,
        });
    },
});

export const Listing = defineComponent({
    name: 'Listing',
    inheritAttrs: false,
    props: [
        'url', 'columns', 'filters', 'actionUrl', 'sortColumn', 'sortDirection',
        'preferencesPrefix', 'pushQuery', 'items', 'allowBulkActions',
        'allowCustomizingColumns',
    ],
    setup(props, { slots, expose }) {
        expose({ refresh: () => {} });
        return () => h('div', { 'data-stub': 'Listing' }, slots.default
            ? slots.default({ items: props.items || [], loading: false, isColumnVisible: () => true })
            : null);
    },
});
