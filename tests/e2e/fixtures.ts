import { test as base, expect } from '@playwright/test';
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { randomUUID } from 'node:crypto';

export const test = base.extend({
    browser: [async ({ browser }, use) => {
        if (process.env.CHAT_COVERAGE !== '1') {
            await use(browser);
            return;
        }
        const directory = resolve('test-results/coverage/browser');
        mkdirSync(directory, { recursive: true });
        const originalNewContext = browser.newContext.bind(browser);
        browser.newContext = async (...args) => {
            const context = await originalNewContext(...args);
            if (args[0]?.javaScriptEnabled === false) return context;
            await context.exposeBinding('__salvarCobertura', (_, data) => {
                if (data) writeFileSync(resolve(directory, `${randomUUID()}.json`), data);
            });
            await context.addInitScript(() => {
                const salvar = () => window.__salvarCobertura(JSON.stringify(window.__coverage__));
                window.addEventListener('beforeunload', salvar);
                window.addEventListener('pagehide', salvar);
            });
            const save = async (page) => {
                if (!page.isClosed()) await page.evaluate(() => window.__salvarCobertura(JSON.stringify(window.__coverage__)));
            };
            context.on('page', (page) => {
                const close = page.close.bind(page);
                page.close = async (...options) => { await save(page); await close(...options); };
            });
            const close = context.close.bind(context);
            context.close = async (...options) => {
                await Promise.all(context.pages().map(save));
                await close(...options);
            };
            return context;
        };
        try {
            await use(browser);
        } finally {
            browser.newContext = originalNewContext;
        }
    }, { scope: 'worker' }],
});

export { expect };
