'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const { once } = require('node:events');
const { WebSocket } = require('ws');
const { createRelay, validateOrigins } = require('../../scripts/omcp-console-relay.cjs');

const origin = 'https://panel.example.com';
const id = 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa';
const initial = 'bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb';
const replacement = 'cccccccc-cccc-4ccc-cccc-cccccccccccc';

async function listen(server) {
    server.listen(0, '127.0.0.1');
    await once(server, 'listening');
    return server.address().port;
}

test('relay validates exact origins and refuses external upstream', () => {
    assert.throws(() => validateOrigins('http://panel.example.com', 'http://127.0.0.1:8080'));
    assert.throws(() => validateOrigins(origin, 'http://example.com'));
    assert.throws(() => validateOrigins(origin + '/path', 'http://127.0.0.1:8080'));
});

test('actual WebSocket handshake, snapshot and grant revocation', { timeout: 10000 }, async () => {
    let generation = initial;
    let allowed = true;
    const upstream = http.createServer((req, res) => {
        if (!allowed || req.headers.cookie !== 'fixture_session=valid'
            || req.headers.origin !== origin || req.url !== '/api/client/omcp/instances/' + id + '/console-live') {
            res.writeHead(403); res.end(); return;
        }
        res.setHeader('Content-Type', 'application/json');
        res.end(JSON.stringify({ generation, state: 'live', output: 'fixture log', captured_at: '2026-11-29T00:00:00Z' }));
    });
    const upstreamPort = await listen(upstream);
    const relay = createRelay({ origin, upstream: 'http://127.0.0.1:' + upstreamPort, interval: 50 });
    const relayPort = await listen(relay.server);
    const url = 'ws://127.0.0.1:' + relayPort + '/omcp-console-ws/' + id;
    const clients = [];
    function connect(source, cookie) {
        const ws = new WebSocket(url, { origin: source, headers: { Cookie: cookie }, handshakeTimeout: 3000 });
        clients.push(ws);
        return ws;
    }
    async function rejected(source, cookie) {
        const ws = connect(source, cookie);
        const [err] = await once(ws, 'error');
        assert.match(err.message, /403/);
    }
    try {
        await rejected('https://evil.example.com', 'fixture_session=valid');
        await rejected(origin, 'fixture_session=wrong');
        const ws = connect(origin, 'fixture_session=valid');
        const [raw] = await once(ws, 'message');
        const data = JSON.parse(raw.toString());
        assert.equal(data.output, 'fixture log');
        assert.equal(data.generation, undefined);
        const closed = once(ws, 'close');
        generation = replacement;
        assert.equal((await closed)[0], 1008);
        const next = connect(origin, 'fixture_session=valid');
        await once(next, 'message');
        const denied = once(next, 'close');
        allowed = false;
        assert.equal((await denied)[0], 1008);
        await rejected(origin, 'fixture_session=valid');
    } finally {
        for (const client of clients) client.terminate();
        relay.close();
        upstream.closeAllConnections();
        upstream.close();
    }
});
