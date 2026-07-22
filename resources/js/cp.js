import Comments from './components/Comments.vue';

Statamic.booting(() => {
    Statamic.$components.register('meerkat-comments', Comments);
});
