// Boots WordPress + WooCommerce + Let Agents in Playground, loads fixtures, runs the smoke tests,
// and always shuts the server down, including its child processes.
//   node tests/playground/run.mjs            (from plugins/woocommerce)
//   LET_AGENTS_PHP=8.4 node tests/playground/run.mjs
import { spawn, spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const pluginDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const outDir = fs.mkdtempSync(path.join(os.tmpdir(), 'let-agents-playground-'));
const fixtures = path.join(outDir, 'fixtures.json');
const port = Number(process.env.LET_AGENTS_PORT ?? 9500 + Math.floor(Math.random() * 400));
const php = process.env.LET_AGENTS_PHP ?? '8.1';
const cliVersion = '3.1.54';
const bootTimeoutMs = 10 * 60 * 1000;

const serverArgs = [
  '-y', `@wp-playground/cli@${cliVersion}`, 'server',
  `--port=${port}`, `--php=${php}`, '--wp=latest',
  '--mount-dir', pluginDir, '/wordpress/wp-content/plugins/let-agents',
  '--mount-dir', outDir, '/let-agents-out',
  '--blueprint=tests/playground/blueprint.json',
];

console.log(`Starting Playground on port ${port} with PHP ${php}`);
const server = spawn(process.platform === 'win32' ? 'npx.cmd' : 'npx', serverArgs, {
  cwd: pluginDir,
  shell: process.platform === 'win32',
  detached: process.platform !== 'win32',
  stdio: ['ignore', 'pipe', 'pipe'],
});

let log = '';
server.stdout.on('data', (d) => (log += d));
server.stderr.on('data', (d) => (log += d));

const stop = () => {
  if (server.exitCode !== null) return;
  if (process.platform === 'win32') {
    spawnSync('taskkill', ['/PID', String(server.pid), '/T', '/F'], { stdio: 'ignore' });
  } else {
    try {
      process.kill(-server.pid, 'SIGTERM');
    } catch {
      server.kill('SIGTERM');
    }
  }
};
process.on('exit', stop);
process.on('SIGINT', () => process.exit(130));

const started = Date.now();
while (!(fs.existsSync(fixtures) && /Ready!/.test(log))) {
  if (server.exitCode !== null) {
    console.error(log);
    console.error('Playground exited before it was ready.');
    process.exit(1);
  }
  if (Date.now() - started > bootTimeoutMs) {
    console.error(log);
    console.error('Playground did not become ready in time.');
    process.exit(1);
  }
  await new Promise((r) => setTimeout(r, 2000));
}

const info = JSON.parse(fs.readFileSync(fixtures, 'utf8'));
console.log(`Ready in ${Math.round((Date.now() - started) / 1000)}s: WordPress ${info.wordpress}, WooCommerce ${info.woocommerce}`);

// LET_AGENTS_KEEP=1 leaves the site running for manual or browser checks (admin / password).
if (process.env.LET_AGENTS_KEEP === '1') {
  console.log(`KEEP http://127.0.0.1:${port}/wp-admin/admin.php?page=let-agents  server pid ${server.pid}`);
  await new Promise(() => {});
}

const result = spawnSync(process.execPath, ['--test', '--test-reporter=spec', 'tests/playground/smoke.test.mjs'], {
  cwd: pluginDir,
  stdio: 'inherit',
  env: { ...process.env, LET_AGENTS_BASE_URL: `http://127.0.0.1:${port}`, LET_AGENTS_FIXTURES: fixtures },
});

stop();
fs.rmSync(outDir, { recursive: true, force: true });
process.exit(result.status ?? 1);
