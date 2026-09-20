import { registerHooks } from 'node:module';
import { writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { createInstrumenter } from 'istanbul-lib-instrument';
import path from 'node:path';

registerHooks({
    load(url, context, nextLoad) {
        const loaded = nextLoad(url, context);
        if (url.startsWith('file:') && fileURLToPath(url).startsWith(path.resolve('resources/js') + path.sep)) {
            const instrumenter = createInstrumenter({ esModules: true });
            return { ...loaded, source: instrumenter.instrumentSync(String(loaded.source), fileURLToPath(url)) };
        }
        return loaded;
    },
});
process.on('exit', () => {
    if (globalThis.__coverage__) writeFileSync(`test-results/coverage/node/${process.pid}.json`, JSON.stringify(globalThis.__coverage__));
});
