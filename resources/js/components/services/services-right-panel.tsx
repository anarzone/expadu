import {
    IconAmbulance,
    IconBuildingBank,
    IconStethoscope,
} from '@tabler/icons-react';
import { useState } from 'react';
import { ICON_STROKE } from '@/constants/icons';

export function ServicesRightPanel() {
    const [suggestHover, setSuggestHover] = useState(false);

    return (
        <>
            {/* Quick links */}
            <div className="mb-3.5 overflow-hidden rounded-[14px] border border-[#E2DFD6] bg-white">
                <div className="border-b border-[#E2DFD6] px-[15px] py-3">
                    <span style={{ fontSize: 13, fontWeight: 700 }}>
                        Quick links
                    </span>
                </div>
                <div className="flex cursor-pointer items-start gap-2.5 border-b border-[#E2DFD6] px-[15px] py-[11px] transition-colors hover:bg-[#EFEDE7]">
                    <IconAmbulance
                        size={16}
                        stroke={ICON_STROKE}
                        className="mt-px shrink-0 text-[#6B6860]"
                    />
                    <div className="min-w-0 flex-1">
                        <div
                            style={{
                                fontSize: 12,
                                fontWeight: 600,
                                marginBottom: 1,
                            }}
                        >
                            Emergency: 112
                        </div>
                        <div style={{ fontSize: 11, color: '#6B6860' }}>
                            Police: 110 · Poison: 0800 192 0087
                        </div>
                    </div>
                </div>
                <div
                    className="flex cursor-pointer items-start gap-2.5 border-b border-[#E2DFD6] px-[15px] py-[11px] transition-colors hover:bg-[#EFEDE7]"
                    onClick={() =>
                        window.open('https://www.doctolib.de', '_blank')
                    }
                >
                    <IconStethoscope
                        size={16}
                        stroke={ICON_STROKE}
                        className="mt-px shrink-0 text-[#6B6860]"
                    />
                    <div className="min-w-0 flex-1">
                        <div
                            style={{
                                fontSize: 12,
                                fontWeight: 600,
                                marginBottom: 1,
                            }}
                        >
                            Find a doctor on Doctolib
                        </div>
                        <div style={{ fontSize: 11, color: '#6B6860' }}>
                            Book online, filter by English
                        </div>
                    </div>
                </div>
                <div
                    className="flex cursor-pointer items-start gap-2.5 px-[15px] py-[11px] transition-colors hover:bg-[#EFEDE7]"
                    onClick={() => window.open('https://n26.com', '_blank')}
                >
                    <IconBuildingBank
                        size={16}
                        stroke={ICON_STROKE}
                        className="mt-px shrink-0 text-[#6B6860]"
                    />
                    <div className="min-w-0 flex-1">
                        <div
                            style={{
                                fontSize: 12,
                                fontWeight: 600,
                                marginBottom: 1,
                            }}
                        >
                            Open N26 account
                        </div>
                        <div style={{ fontSize: 11, color: '#6B6860' }}>
                            English-first online bank
                        </div>
                    </div>
                </div>
            </div>

            {/* Suggest a service */}
            <div className="mb-3.5 overflow-hidden rounded-[14px] border border-[#E2DFD6] bg-white">
                <div className="border-b border-[#E2DFD6] px-[15px] py-3">
                    <span style={{ fontSize: 13, fontWeight: 700 }}>
                        Suggest a service
                    </span>
                </div>
                <div className="px-[15px] py-3.5">
                    <div
                        style={{
                            fontSize: 13,
                            color: '#6B6860',
                            lineHeight: 1.5,
                            marginBottom: 10,
                        }}
                    >
                        Know a great English-friendly doctor, lawyer, or
                        advisor? Add them to Expadu.
                    </div>
                    <button
                        onMouseEnter={() => setSuggestHover(true)}
                        onMouseLeave={() => setSuggestHover(false)}
                        className="w-full cursor-pointer rounded-[9px] border-none py-[9px]"
                        style={{
                            background: suggestHover ? '#1540B8' : '#1A4CD4',
                            color: 'white',
                            fontFamily: "'Geist', sans-serif",
                            fontSize: 13,
                            fontWeight: 600,
                            transition: 'background .2s',
                        }}
                    >
                        + Suggest a service
                    </button>
                </div>
            </div>
        </>
    );
}
