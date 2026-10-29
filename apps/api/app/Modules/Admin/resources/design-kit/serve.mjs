// Serves this folder at http://localhost:5500, so the panel's design bar can load merchant.css from this computer.
// Never cached: every refresh of the panel gets the file as it is saved now. No packages needed: `node serve.mjs`.
import { createServer } from 'node:http';
import { readFile, stat } from 'node:fs/promises';
import { extname, join, normalize, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('.', import.meta.url));
const port = Number(process.env.PORT || 5500);
const types = { '.css': 'text/css; charset=utf-8', '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.woff2': 'font/woff2', '.md': 'text/plain; charset=utf-8' };

createServer(async (req, res) => {
    const path = normalize(join(root, decodeURIComponent(new URL(req.url, 'http://localhost').pathname)));
    const headers = {
        'Cache-Control': 'no-store',
        'Access-Control-Allow-Origin': '*',
        // the panel is on https and this server on the local network: Chrome asks the page for leave to reach it
        'Access-Control-Allow-Private-Network': 'true',
    };
    if (req.method === 'OPTIONS') {
        res.writeHead(204, { ...headers, 'Access-Control-Allow-Methods': 'GET', 'Access-Control-Allow-Headers': '*' });
        return res.end();
    }
    if (!path.startsWith(root.endsWith(sep) ? root : root + sep)) {
        res.writeHead(403, headers);
        return res.end();
    }
    try {
        if (!(await stat(path)).isFile()) throw new Error('not a file');
        res.writeHead(200, { ...headers, 'Content-Type': types[extname(path)] || 'application/octet-stream' });
        res.end(await readFile(path));
    } catch {
        res.writeHead(404, headers);
        res.end('not found');
    }
}).listen(port, '127.0.0.1', () => {
    console.log(`Serving ${root} at http://localhost:${port}`);
    console.log(`In the panel's design bar, load: http://localhost:${port}/merchant.css`);
});
