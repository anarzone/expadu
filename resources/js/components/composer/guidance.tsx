import { Link } from '@inertiajs/react';
import { IconArrowUpRight, IconShieldCheck } from '@tabler/icons-react';
import { useEffect, useState } from 'react';
import { guidance } from '@/actions/App/Http/Controllers/ComposerWorkspaceController';
import { paperwork } from '@/routes';
import { ComposerLoading } from './loading';
import { api } from './types';

type Guide = {
    key: string;
    title: string;
    description: string;
    verified_at: string;
    jurisdiction: string;
    sources: { label: string; url: string }[];
};
export function Guidance({ query }: { query: string }) {
    const [guides, setGuides] = useState<Guide[] | null>(null);
    const [error, setError] = useState('');
    useEffect(() => {
        const controller = new AbortController();
        api<{ guides: Guide[] }>(
            guidance.url({ query: { query } }),
            undefined,
            controller.signal,
        )
            .then((result) => setGuides(result.guides))
            .catch((err) => {
                if (!controller.signal.aborted) {
                    setError(err.message);
                }
            });

        return () => controller.abort();
    }, [query]);

    return (
        <div className="composer-guidance">
            {error ? (
                <p role="alert">
                    Could not load reviewed guidance. Your case is still
                    available below.
                </p>
            ) : guides === null ? (
                <ComposerLoading label="Checking reviewed guidance…" />
            ) : guides.length === 0 ? (
                <p>
                    No current reviewed guide matches this question. Open your
                    case to check the available guidance.
                </p>
            ) : (
                guides.map((guide) => (
                    <details key={guide.key}>
                        <summary>
                            <IconShieldCheck size={18} />
                            <span>
                                {guide.title}
                                <small>Reviewed {guide.verified_at}</small>
                            </span>
                        </summary>
                        <p>{guide.description}</p>
                        <small>
                            General guide · {guide.jurisdiction}. Your
                            circumstances determine what applies.
                        </small>
                        <ul>
                            {guide.sources.map((source) => (
                                <li key={source.url}>
                                    <a
                                        href={source.url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        {source.label}
                                        <IconArrowUpRight size={13} />
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </details>
                ))
            )}
            <Link className="composer-text-button" href={paperwork()}>
                Open my paperwork case
                <IconArrowUpRight size={15} />
            </Link>
        </div>
    );
}
