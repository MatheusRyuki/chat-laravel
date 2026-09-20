import { createInstrumenter } from 'istanbul-lib-instrument';
import path from 'node:path';

export function coberturaJavaScript() {
    return {
        name: 'cobertura-javascript',
        enforce: 'pre',
        transform(code, id) {
            if (!id.startsWith(path.resolve('resources/js') + path.sep) || !id.endsWith('.js')) return null;
            const instrumenter = createInstrumenter({ esModules: true, produceSourceMap: true });
            return { code: instrumenter.instrumentSync(code, id), map: instrumenter.lastSourceMap() };
        },
    };
}
