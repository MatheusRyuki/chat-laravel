import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './',
    timeout: 90_000,
    expect: { timeout: 15_000 },
    fullyParallel: false,
    workers: 1,
    reporter: [['list']],
    use: {
        baseURL: 'http://127.0.0.1:8002',
        channel: 'chrome',
        locale: 'pt-BR',
        screenshot: 'off',
        trace: 'off',
        video: 'off',
    },
    webServer: {
        command: 'bash iniciar.sh',
        url: 'http://127.0.0.1:8002/login',
        reuseExistingServer: false,
        timeout: 120_000,
        stdout: 'pipe',
        stderr: 'pipe',
    },
    projects: [
        {
            name: 'chrome',
            use: { ...devices['Desktop Chrome'], channel: 'chrome' },
        },
    ],
});
