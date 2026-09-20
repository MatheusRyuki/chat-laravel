import { readFile, readdir, mkdir } from 'node:fs/promises';
import path from 'node:path';
import { createInstrumenter } from 'istanbul-lib-instrument';
import libCoverage from 'istanbul-lib-coverage';
import libReport from 'istanbul-lib-report';
import reports from 'istanbul-reports';

const root = process.cwd();
const map = libCoverage.createCoverageMap({});
for (const file of await readdir('test-results/coverage/browser')) {
    map.merge(JSON.parse(await readFile(`test-results/coverage/browser/${file}`, 'utf8')));
}
for (const file of await readdir('test-results/coverage/node')) {
    if (file.endsWith('.json')) map.merge(JSON.parse(await readFile(`test-results/coverage/node/${file}`, 'utf8')));
}
// Inclui arquivos nunca carregados para que a ausência não aumente o percentual.
for (const file of await readdir('resources/js', { recursive: true })) {
    if (!file.endsWith('.js')) continue;
    const name = path.join(root, 'resources/js', file);
    if (map.files().includes(name)) continue;
    const source = await readFile(name, 'utf8');
    const instrumenter = createInstrumenter({ esModules: true });
    instrumenter.instrumentSync(source, name);
    map.addFileCoverage(instrumenter.lastFileCoverage());
}
const dir = 'test-results/coverage/javascript';
await mkdir(dir, { recursive: true });
const context = libReport.createContext({ dir, coverageMap: map });
for (const format of ['text', 'html', 'json', 'json-summary', 'lcovonly']) reports.create(format).execute(context);

const minimos = { statements: 90, branches: 75, functions: 90, lines: 90 };
const resumo = map.getCoverageSummary().data;
const insuficientes = Object.entries(minimos)
    .filter(([metrica, minimo]) => resumo[metrica].pct < minimo)
    .map(([metrica, minimo]) => `${metrica}: ${resumo[metrica].pct}% (mínimo ${minimo}%)`);

if (insuficientes.length > 0) {
    throw new Error(`Cobertura JavaScript abaixo do mínimo:\n${insuficientes.join('\n')}`);
}
