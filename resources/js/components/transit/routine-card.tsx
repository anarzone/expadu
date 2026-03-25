import { router } from '@inertiajs/react';

const dayLabels = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
const dayDisplay = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];

export function RoutineCard({ routine }: { routine: { id: number; name: string; emoji: string | null; from_stop: string; to_stop: string; days: string[]; departure_time: string; is_active: boolean } }) {
    function toggleActive() {
        router.put(`/routines/${routine.id}`, { is_active: !routine.is_active }, { preserveScroll: true });
    }

    function deleteRoutine() {
        router.delete(`/routines/${routine.id}`, { preserveScroll: true });
    }

    return (
        <div className="rounded-xl border border-border bg-card p-4">
            <div className="mb-2 flex items-center justify-between">
                <div className="flex items-center gap-2">
                    <span className="text-lg">{routine.emoji || '🚇'}</span>
                    <span className="text-sm font-semibold">{routine.name}</span>
                </div>
                <div className="flex items-center gap-2">
                    <span className={`rounded-full px-2 py-0.5 text-[10px] font-bold uppercase ${routine.is_active ? 'bg-success-soft text-success' : 'bg-secondary text-muted-foreground'}`}>
                        {routine.is_active ? 'Active' : 'Paused'}
                    </span>
                    <button onClick={deleteRoutine} className="text-xs text-muted-foreground hover:text-destructive">&#x2715;</button>
                </div>
            </div>
            <div className="mb-2 text-xs text-muted-foreground">
                {routine.from_stop} &rarr; {routine.to_stop} &middot; {routine.departure_time}
            </div>
            <div className="flex items-center gap-1">
                {dayLabels.map((day, i) => (
                    <span
                        key={day}
                        className={`flex size-7 items-center justify-center rounded-full text-[10px] font-bold ${
                            routine.days.includes(day) ? 'bg-primary text-white' : 'bg-secondary text-muted-foreground'
                        }`}
                    >
                        {dayDisplay[i]}
                    </span>
                ))}
                <button onClick={toggleActive} className="ml-auto text-xs font-medium text-primary">
                    {routine.is_active ? 'Pause' : 'Resume'}
                </button>
            </div>
        </div>
    );
}
