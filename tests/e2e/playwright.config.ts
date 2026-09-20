import { defineConfig, devices } from '@playwright/test';

const docker = process.env.CHAT_E2E_DOCKER === '1';
const baseURL = `http://127.0.0.1:${docker ? 18002 : 8002}`;

export default defineConfig({
    testDir: './',
    outputDir: '../../test-results/playwright',
    testIgnore: '**/storage/**',
    timeout: 90_000,
    expect: { timeout: 15_000 },
    fullyParallel: false,
    workers: 1,
    reporter: [['list']],
    use: {
        baseURL,
        channel: 'chrome',
        locale: 'pt-BR',
        screenshot: 'off',
        trace: 'off',
        video: 'off',
    },
    webServer: {
        command: docker ? 'bash servidor-docker.sh' : 'bash iniciar.sh',
        url: `${baseURL}/login`,
        reuseExistingServer: false,
        gracefulShutdown: { signal: 'SIGTERM', timeout: 10_000 },
        timeout: 120_000,
        stdout: 'ignore',
        stderr: 'pipe',
    },
    projects: [
        {
            name: 'chrome',
            use: { ...devices['Desktop Chrome'], channel: 'chrome' },
        },
    ],
});
