import { spawnSync } from 'node:child_process';
import { mkdirSync, rmSync } from 'node:fs';

for (const directory of ['browser', 'node']) {
    const target = `test-results/coverage/${directory}`;
    rmSync(target, { recursive: true, force: true });
    mkdirSync(target, { recursive: true });
}
const env = { ...process.env, CHAT_COVERAGE: '1', E2E_SCREENSHOTS: 'test-results/screenshots' };
function run(command, args, extra = {}) {
    const result = spawnSync(command, args, { stdio: 'inherit', env: { ...env, ...extra } });
    if (result.error) throw result.error;
    return result.status ?? 1;
}
let status = run('npm', ['run', 'build']);
if (status) process.exit(status);
status = run('node', ['--import', './tests/coverage/node-register.mjs', '--test', 'tests/js/mensagens.test.js']);
if (status) process.exit(status);
status = run('npx', ['playwright', 'test', '-c', process.env.E2E_CONFIG || 'tests/e2e/playwright.config.ts']);
const reportStatus = run('node', ['tests/coverage/relatorio.mjs']);
const buildStatus = run('npm', ['run', 'build'], { CHAT_COVERAGE: '0' });
process.exit(status || reportStatus || buildStatus);
