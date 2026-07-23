import { afterEach, describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { JSDOM } from 'jsdom';

const repliesScript = readFileSync(join(process.cwd(), 'resources/js/replies.js'), 'utf8');

const markup = `<!doctype html>
<html>
<body>
    <form data-meerkat-form="comment-reply-form">
        <textarea name="comment"></textarea>
        <button type="button" data-meerkat-form="cancel-reply">
            <span><strong>Cancel</strong></span>
        </button>
    </form>
    <div>
        <button type="button" data-meerkat-form="reply" data-meerkat-reply-to="42">
            <span><strong>Reply</strong></span>
        </button>
    </div>
</body>
</html>`;

let dom;

async function bootReplies() {
    dom = new JSDOM(markup, {
        runScripts: 'outside-only',
        url: 'http://localhost/',
    });

    dom.window.eval(repliesScript);
    await new Promise((resolve) => dom.window.setTimeout(resolve, 0));

    return dom.window;
}

afterEach(() => {
    dom?.window.close();
    dom = null;
});

describe('frontend reply controls', () => {
    it.each([
        ['the controls', '[data-meerkat-form="reply"]', '[data-meerkat-form="cancel-reply"]'],
        ['nested control content', '[data-meerkat-form="reply"] strong', '[data-meerkat-form="cancel-reply"] strong'],
    ])('opens and cancels a reply when clicking %s', async (_, replySelector, cancelSelector) => {
        const window = await bootReplies();
        const canceled = vi.fn();

        window.MeerkatReply.canceled = canceled;
        window.document.querySelector(replySelector).click();

        const forms = window.document.querySelectorAll('form[data-meerkat-form="comment-reply-form"]');
        const replyForm = forms[forms.length - 1];

        expect(replyForm.querySelector('[name="ids"]').value).toBe('42');

        replyForm.querySelector(cancelSelector).click();

        expect(canceled).toHaveBeenCalledWith('42', replyForm);
        expect(replyForm.isConnected).toBe(false);
    });
});
