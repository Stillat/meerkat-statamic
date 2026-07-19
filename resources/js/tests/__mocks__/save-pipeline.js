export class Pipeline {
    provide() { return this; }
    through() { return this; }
    send() { return this; }
    thenReturn() { return Promise.resolve(); }
}
export class Request {}
export class PipelineStopped extends Error {}
export const BeforeSaveHooks = { add: () => {} };
export const AfterSaveHooks = { add: () => {} };
