import React, { useEffect, useState } from 'react';
import tw from 'twin.macro';

interface Snapshot {
    state: 'live' | 'waiting';
    output: string;
    captured_at: string | null;
}

export default function OmcpLiveConsole({ instance }: { instance: string }) {
    const [enabled, setEnabled] = useState(false);
    const [status, setStatus] = useState('Disconnected');
    const [snapshot, setSnapshot] = useState<Snapshot | null>(null);

    useEffect(() => {
        if (!enabled) return;
        if (window.location.protocol !== 'https:') {
            setStatus('HTTPS is required for live console.');
            setEnabled(false);
            return;
        }
        let active = true;
        setSnapshot(null);
        setStatus('Connecting...');
        const ws = new WebSocket('wss://' + window.location.host + '/omcp-console-ws/' + encodeURIComponent(instance));
        ws.onopen = () => { if (active) setStatus('Connected — waiting for snapshots'); };
        ws.onmessage = (event) => {
            if (!active || typeof event.data !== 'string' || event.data.length > 16384) return;
            try {
                const data: Snapshot = JSON.parse(event.data);
                if (!['live', 'waiting'].includes(data.state) || typeof data.output !== 'string' || data.output.length > 3500) return;
                setSnapshot(data);
                setStatus(data.state === 'live' ? 'Live snapshot' : 'Waiting for fresh connector data');
            } catch { setStatus('Invalid console response'); ws.close(); }
        };
        ws.onerror = () => { if (active) setStatus('Live console relay unavailable'); };
        ws.onclose = () => {
            if (active) {
                setSnapshot(null);
                setStatus('Disconnected — permission or relay may be unavailable');
                setEnabled(false);
            }
        };
        return () => {
            active = false;
            ws.close();
        };
    }, [enabled, instance]);

    return (
        <div css={tw`mt-4 border-t border-neutral-600 pt-3`} aria-label="Live console">
            <div css={tw`flex flex-wrap items-center justify-between`}>
                <h4 css={tw`text-sm font-semibold text-neutral-100`}>Live console (WebSocket snapshots)</h4>
                <button type="button" css={tw`rounded bg-neutral-600 hover:bg-neutral-500 px-3 py-2 text-sm text-white`}
                    onClick={() => { if (enabled) { setSnapshot(null); setStatus('Disconnected'); } setEnabled(!enabled); }}>
                    {enabled ? 'Disconnect' : 'Connect live console'}
                </button>
            </div>
            <p role="status" css={tw`text-xs text-neutral-300 mt-2`}>{status}</p>
            {enabled && snapshot?.captured_at && <p css={tw`text-xs text-neutral-400 mt-1`}>Captured {snapshot.captured_at}</p>}
            {enabled && <pre css={tw`mt-2 p-3 bg-neutral-900 rounded text-xs text-neutral-200 overflow-auto whitespace-pre-wrap break-words max-h-64`}>{snapshot?.output || 'Waiting for console output...'}</pre>}
        </div>
    );
}
