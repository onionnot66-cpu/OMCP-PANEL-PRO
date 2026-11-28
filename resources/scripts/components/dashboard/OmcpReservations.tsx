import React, { useState } from 'react';
import useSWR from 'swr';
import http from '@/api/http';
import { useStoreState } from 'easy-peasy';
import tw from 'twin.macro';
import styled from 'styled-components/macro';
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
    game_address: string | null;
}

interface ReservationPage {
    data: Reservation[];
    current_page: number;
    last_page: number;
    total: number;
}

const InstanceCard = styled.article`
    background: linear-gradient(145deg, #2a283c, #222132);
    border: 1px solid rgba(167,139,250,.14);
    border-radius: 16px;
    padding: 20px;
    margin-bottom: 12px;
    box-shadow: 0 5px 18px rgba(0,0,0,.12);
    transition: border-color .18s ease;
    &:hover { border-color: rgba(167,139,250,.42); }
    @media (prefers-reduced-motion: reduce) { transition: none; }
`;

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
            <div css={tw`flex flex-wrap justify-between items-baseline mb-3`}>
                <div>
                    <h2 css={tw`text-xl font-semibold text-neutral-100 mb-1`}>OMCP reservations</h2>
                    <p css={tw`text-sm text-neutral-400`}>Server plans and explicitly granted trusted runtime access.</p>
                </div>
                <span css={tw`text-sm text-neutral-300`}>{data.total} reservations</span>
            </div>
            {data.data.map((item) => (
                <InstanceCard key={item.id}>
                    <div css={tw`flex flex-wrap justify-between items-center`}>
                        <div css={tw`flex items-center min-w-0`}>
                            <span aria-hidden="true" css={tw`rounded-lg bg-neutral-600 w-10 h-10 flex items-center justify-center mr-3 flex-shrink-0 text-white font-semibold`}>MC</span>
                            <div css={tw`min-w-0`}>
                                <h3 css={tw`text-lg text-neutral-100 font-semibold break-words`}>{item.name}</h3>
                                <p css={tw`text-xs text-neutral-300 capitalize`}>{item.software} {item.version} · {item.runtime || 'Runtime not selected'}</p>
                            </div>
                        </div>
                        <span css={tw`text-xs text-neutral-200 border border-neutral-500 rounded-full px-3 py-1 mt-2 sm:mt-0`}>{item.controls_enabled ? 'Trusted controls' : 'Reserved only'}</span>
                    </div>
                    <div css={tw`grid grid-cols-3 gap-2 mt-4`}>
                        {[['Game port', item.port], ['RAM reserved', item.memory_mib + ' MiB'], ['Disk reserved', item.disk_mib + ' MiB']].map(([label, value]) => (
                            <div key={label} css={tw`rounded-lg bg-neutral-900 bg-opacity-50 p-3 min-w-0`}>
                                <p css={tw`text-xs text-neutral-400`}>{label}</p>
                                <p css={tw`text-sm sm:text-base font-semibold text-white break-words mt-1`}>{value}</p>
                            </div>
                        ))}
                    </div>
                    {item.game_address && <p css={tw`text-sm mt-3 break-all text-neutral-200`}>Game address (operator configured): {item.game_address}</p>}
                    {item.controls_enabled && <OmcpOwnerControls instance={item.id} />}
                </InstanceCard>
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
