import React, { useEffect, useState } from 'react';
import http from '@/api/http';
import tw from 'twin.macro';
import OmcpLiveConsole from '@/components/dashboard/OmcpLiveConsole';

interface JobResult {
    state: string;
    result: string | null;
}

export default function OmcpOwnerControls({ instance }: { instance: string }) {
    const [job, setJob] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('Ready');
    const [output, setOutput] = useState('');
    const [command, setCommand] = useState('');
    const base = '/api/client/omcp/instances/' + instance;

    useEffect(() => {
        if (!job) return;
        let cancelled = false;
        let timer: ReturnType<typeof setTimeout> | undefined;
        const deadline = Date.now() + 180000;
        const poll = async () => {
            try {
                const { data } = await http.get<JobResult>(base + '/jobs/' + job);
                if (cancelled) return;
                setMessage(data.state);
                if ((data.state === 'queued' || data.state === 'running') && Date.now() < deadline) {
                    timer = setTimeout(poll, 3000);
                    return;
                }
                if (data.state === 'succeeded') setOutput(data.result || '');
                if (data.state === 'failed') setMessage('Action failed. Ask your administrator to check the node.');
                if (data.state === 'queued' || data.state === 'running') setMessage('Result pending; check status later.');
            } catch {
                if (!cancelled) setMessage('Job unavailable or access revoked.');
            }
            if (!cancelled) {
                setJob(null);
                setBusy(false);
            }
        };
        poll();
        return () => {
            cancelled = true;
            if (timer !== undefined) clearTimeout(timer);
        };
    }, [job, base]);

    const send = async (action: string) => {
        if (busy) return;
        if (action === 'command' && !command.trim()) return;
        setBusy(true);
        setMessage('Submitting ' + action + '...');
        setOutput('');
        try {
            const { data } = await http.post<{ id: string }>(base + '/action', {
                action,
                ...(action === 'command' ? { command } : {}),
            });
            if (action === 'command') setCommand('');
            setJob(data.id);
        } catch {
            setMessage('Action unavailable. Check permissions, connector status, and pending jobs.');
            setBusy(false);
        }
    };

    return (
        <div css={tw`mt-4 border-t border-neutral-600 pt-3`} aria-label="Trusted server controls">
            <p css={tw`text-xs text-neutral-300 mb-2`}>Trusted owner access · power and command jobs</p>
            <div css={tw`flex flex-wrap`}>
                {['start', 'stop', 'restart', 'status', 'metrics', 'console'].map((action) => (
                    <button key={action} type="button" disabled={busy} onClick={() => send(action)}
                        css={tw`rounded bg-neutral-600 hover:bg-neutral-500 px-3 py-2 mr-2 mb-2 capitalize text-sm text-white`}>
                        {action}
                    </button>
                ))}
            </div>
            <div css={tw`flex flex-wrap items-center`}>
                <input value={command} maxLength={256} onChange={(e) => setCommand(e.target.value)}
                    disabled={busy} aria-label="Minecraft command" placeholder="list, save-all, say Hello"
                    css={tw`bg-neutral-900 text-white rounded p-2 mr-2 mb-2 flex-1 min-w-0`} />
                <button type="button" disabled={busy || !command.trim()} onClick={() => send('command')}
                    css={tw`rounded bg-neutral-600 hover:bg-neutral-500 px-3 py-2 mb-2 text-white`}>Send</button>
            </div>
            <p role="status" css={tw`text-sm text-neutral-300`}>{message}</p>
            <OmcpLiveConsole instance={instance} />
            {output && <pre css={tw`mt-2 p-3 bg-neutral-900 rounded text-xs text-neutral-200 overflow-auto whitespace-pre-wrap break-words max-h-64`}>{output}</pre>}
        </div>
    );
}
