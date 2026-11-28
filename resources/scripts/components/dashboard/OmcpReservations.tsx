import React, { useState } from 'react';
import useSWR from 'swr';
import http from '@/api/http';
import { useStoreState } from 'easy-peasy';
import tw from 'twin.macro';
import OmcpOwnerControls from '@/components/dashboard/OmcpOwnerControls';

interface Reservation {
    id: string;
    name: string;
    software: string;
    version: string;
    port: number;
    memory_mib: number;
    disk_mib: number;
    controls_enabled: boolean;
    runtime: string | null;
}

interface ReservationPage {
    data: Reservation[];
    current_page: number;
    last_page: number;
}

export default function OmcpReservations() {
    const uuid = useStoreState((state) => state.user.data!.uuid);
    const [page, setPage] = useState(1);
    const { data, error } = useSWR<ReservationPage>(
        ['omcp-reservations', uuid, page],
        () => http.get<ReservationPage>('/api/client/omcp/instances', { params: { page } }).then((r) => r.data),
        { refreshInterval: 15000 }
    );
    if (error) return <p css={tw`text-sm text-neutral-400 mb-4`}>OMCP reservations could not be loaded.</p>;
    if (!data) return null;
    if (!data.data.length && page === 1) return null;
    return (
        <section css={tw`mb-6`} aria-label="OMCP reservations">
            <h2 css={tw`text-xl font-medium text-neutral-100 mb-2`}>OMCP reservations</h2>
            <p css={tw`text-sm text-neutral-400 mb-3`}>Server plans and explicitly granted trusted runtime access.</p>
            {data.data.map((item) => (
                <div key={item.id} css={tw`bg-neutral-700 rounded-lg p-4 mb-2`}>
                    <div css={tw`flex flex-wrap justify-between items-center`}>
                        <p css={tw`text-neutral-100 font-medium`}>{item.name}</p>
                        <span css={tw`text-xs text-neutral-300`}>{item.controls_enabled ? 'Trusted controls' : 'Reserved only'}</span>
                    </div>
                    <p css={tw`text-sm text-neutral-300 mt-2`}>
                        {item.software} {item.version} · Port {item.port} · {item.memory_mib} MiB RAM · {item.disk_mib} MiB disk
                    </p>
                    {item.controls_enabled && <OmcpOwnerControls instance={item.id} />}
                </div>
            ))}
            {data.last_page > 1 || page > 1 ? (
                <div css={tw`flex items-center justify-between text-sm text-neutral-300`}>
                    <button type="button" disabled={page <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))}>Previous</button>
                    <span>Page {page} / {data.last_page}</span>
                    <button type="button" disabled={page >= data.last_page} onClick={() => setPage((p) => p + 1)}>Next</button>
                </div>
            ) : null}
        </section>
    );
}
