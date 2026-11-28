'use strict';

const http = require('node:http');
const crypto = require('node:crypto');
const { WebSocketServer, WebSocket } = require('ws');

const instancePath = /^\/omcp-console-ws\/([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})$/;
const uuid = /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/;

function validateOrigins(origin, upstream) {
    const publicUrl = new URL(origin);
    const internal = new URL(upstream);
    if (publicUrl.protocol !== 'https:' || publicUrl.origin !== origin || publicUrl.username || publicUrl.password) {
        throw new Error('OMCP_CONSOLE_ORIGIN must be an exact HTTPS origin');
    }
    if (internal.protocol !== 'http:' || internal.hostname !== '127.0.0.1' || internal.origin !== upstream
        || internal.username || internal.password) {
        throw new Error('OMCP_CONSOLE_UPSTREAM must be an exact loopback HTTP origin');
    }
    return publicUrl;
}

async function boundedJson(response) {
    const declared = Number(response.headers.get('content-length'));
    if (declared > 16384 || !response.body) throw new Error('invalid response');
    const reader = response.body.getReader();
    const pieces = [];
    let size = 0;
    try {
        for (;;) {
            const { value, done } = await reader.read();
            if (done) break;
            size += value.length;
            if (size > 16384) throw new Error('console response too large');
            pieces.push(Buffer.from(value));
        }
        return JSON.parse(Buffer.concat(pieces).toString('utf8'));
    } finally {
        await reader.cancel().catch(() => {});
    }
}

function createRelay({ origin, upstream, interval = 5000 }) {
    const publicUrl = validateOrigins(origin, upstream);
    const server = http.createServer((req, res) => {
        res.writeHead(404, { 'Cache-Control': 'no-store' });
        res.end();
    });
    const ws = new WebSocketServer({ noServer: true, maxPayload: 1024, perMessageDeflate: false });
    const sessions = new Map();
    const pending = new Set();
    let slots = 0;

    async function authorize(cookie, id) {
        const response = await fetch(upstream + '/api/client/omcp/instances/' + id + '/console-live', {
            redirect: 'error',
            signal: AbortSignal.timeout(4000),
            headers: {
                Cookie: cookie, Accept: 'application/json', Origin: origin, Referer: origin + '/',
                Host: publicUrl.host, 'X-Forwarded-Proto': 'https', 'X-Requested-With': 'XMLHttpRequest',
            },
        });
        if (!response.ok) {
            await response.body?.cancel();
            throw new Error('not authorized');
        }
        const data = await boundedJson(response);
        if (!data || !uuid.test(data.generation) || !['live', 'waiting'].includes(data.state)
            || typeof data.output !== 'string' || data.output.length > 3500
            || (data.captured_at !== null && (typeof data.captured_at !== 'string' || data.captured_at.length > 64))) {
            throw new Error('invalid console snapshot');
        }
        return data;
    }

    function reject(socket, code = '403 Forbidden') {
        if (!socket.destroyed) socket.end('HTTP/1.1 ' + code + '\r\nConnection: close\r\nContent-Length: 0\r\n\r\n');
    }

    server.on('upgrade', async (req, socket, head) => {
        const match = instancePath.exec(req.url || '');
        const cookie = req.headers.cookie;
        if (req.method !== 'GET' || req.headers.origin !== origin || !match || typeof cookie !== 'string' || cookie.length > 8192 || !cookie) {
            reject(socket);
            return;
        }
        const key = crypto.createHash('sha256').update(cookie).digest('hex');
        const used = sessions.get(key) || 0;
        if (slots >= 64 || used >= 4) { reject(socket, '429 Too Many Requests'); return; }
        slots++;
        sessions.set(key, used + 1);
        pending.add(socket);
        let released = false;
        function release() {
            if (released) return;
            released = true; slots--;
            const n = (sessions.get(key) || 1) - 1;
            if (n) sessions.set(key, n); else sessions.delete(key);
        }
        socket.once('close', release);
        socket.on('error', () => socket.destroy());
        try {
            const first = await authorize(cookie, match[1]);
            pending.delete(socket);
            if (socket.destroyed || released) return;
            ws.handleUpgrade(req, socket, head, (client) => {
                const generation = first.generation;
                let timer;
                let stopped = false;
                let last = '';
                const close = () => {
                    stopped = true; clearTimeout(timer); release();
                };
                client.once('close', close);
                client.on('error', () => { close(); client.terminate(); });
                client.on('message', () => client.close(1008, 'Read-only console'));
                function send(data) {
                    if (stopped || client.readyState !== WebSocket.OPEN) return;
                    if (data.generation !== generation) { client.close(1008, 'Access changed'); return; }
                    const message = JSON.stringify({ state: data.state, output: data.output, captured_at: data.captured_at });
                    if (client.bufferedAmount > 32768) { client.terminate(); return; }
                    if (message !== last) { client.send(message); last = message; }
                }
                async function poll() {
                    if (stopped || client.readyState !== WebSocket.OPEN) return;
                    try { send(await authorize(cookie, match[1])); }
                    catch { client.close(1008, 'Access unavailable'); return; }
                    if (!stopped && client.readyState === WebSocket.OPEN) timer = setTimeout(poll, interval);
                }
                send(first);
                timer = setTimeout(poll, interval);
            });
        } catch {
            pending.delete(socket);
            reject(socket);
        }
    });

    return {
        server,
        close() {
            for (const socket of pending) socket.destroy();
            for (const client of ws.clients) client.terminate();
            ws.close();
            server.close();
        },
    };
}

if (require.main === module) {
    const port = Number(process.env.OMCP_CONSOLE_PORT || '8766');
    if (!Number.isInteger(port) || port < 1024 || port > 65535) throw new Error('Invalid relay port');
    const relay = createRelay({ origin: process.env.OMCP_CONSOLE_ORIGIN, upstream: process.env.OMCP_CONSOLE_UPSTREAM });
    relay.server.listen(port, '127.0.0.1', () => console.log('OMCP console relay listening on loopback'));
    process.once('SIGTERM', () => relay.close());
    process.once('SIGINT', () => relay.close());
}

module.exports = { createRelay, validateOrigins };
