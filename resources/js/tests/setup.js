import { config } from '@vue/test-utils';
import { vi } from 'vitest';

globalThis.__ = (key, replacements) => {
    if (replacements && typeof replacements === 'object') {
        return Object.entries(replacements).reduce(
            (acc, [k, v]) => acc.replace(`:${k}`, v),
            key,
        );
    }
    return key;
};
globalThis.__n = (key, count, replacements = {}) => {
    const merged = { count, ...replacements };
    const variants = String(key).split('|');
    const chosen = count === 1 ? variants[0] : (variants[variants.length - 1] || variants[0]);
    return Object.entries(merged).reduce((acc, [k, v]) => acc.replaceAll(`:${k}`, v), chosen);
};
globalThis.cp_url = (path) => `/cp/${path}`;
globalThis.cp_route = (name) => `/cp/${name}`;

globalThis.Statamic = {
    $toast: { success: vi.fn(), error: vi.fn() },
    $progress: { loading: vi.fn(), complete: vi.fn() },
    $keys: {
        bindGlobal: vi.fn(() => ({ destroy: vi.fn() })),
    },
    $dirty: {
        add: vi.fn(),
        remove: vi.fn(),
        has: vi.fn(() => false),
    },
    $preferences: {
        get: vi.fn((key, fallback) => fallback),
        set: vi.fn(),
    },
    $events: { $on: vi.fn(), $off: vi.fn(), $emit: vi.fn() },
    $components: { register: vi.fn() },
    $config: { get: vi.fn((key, fallback) => fallback) },
    $callbacks: { call: vi.fn() },
    $commandPalette: { add: vi.fn(), category: { Actions: 'actions' } },
    booting: vi.fn(),
};

config.global.mocks = {
    __: globalThis.__,
    __n: globalThis.__n,
};

config.global.directives = {
    tooltip: () => {},
};

class MockObserver {
    observe() {}
    unobserve() {}
    disconnect() {}
}
globalThis.IntersectionObserver = globalThis.IntersectionObserver || MockObserver;
globalThis.ResizeObserver = globalThis.ResizeObserver || MockObserver;
