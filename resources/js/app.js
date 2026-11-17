import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

createInertiaApp({
    title: (title) => (title ? `${title} · Forge Studio` : 'Forge Studio'),
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });
        const page = pages[`./Pages/${name}.vue`];
        // Signed-in pages share the app layout; auth pages bring their own.
        page.default.layout ??= name.startsWith('Auth/') ? undefined : AppLayout;
        return page;
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) }).use(plugin).mount(el);
    },
    progress: { color: '#b45309' },
});
