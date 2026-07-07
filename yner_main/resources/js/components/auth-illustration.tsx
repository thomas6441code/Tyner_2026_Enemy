import { Check } from 'lucide-react';

const bars = [40, 62, 48, 80, 56, 70, 36];

/** Decorative product mockup for the auth/onboarding brand panel. */
export function AuthIllustration() {
    return (
        <div className="relative w-full max-w-sm">
            <div className="absolute -left-10 -top-10 h-40 w-40 rounded-full bg-primary/25 blur-3xl" />
            <div className="absolute -bottom-12 right-0 h-44 w-44 rounded-full bg-emerald-400/20 blur-3xl" />

            {/* App preview card */}
            <div className="relative rounded-2xl bg-white p-5 shadow-xl dark:bg-slate-900">
                <div className="flex items-center gap-3">
                    <div className="flex h-10 w-10 items-center justify-center rounded-full bg-primary text-primary-foreground">
                        <Check className="h-5 w-5" />
                    </div>
                    <div className="space-y-1.5">
                        <div className="h-2.5 w-28 rounded-full bg-slate-200 dark:bg-slate-700" />
                        <div className="h-2 w-20 rounded-full bg-slate-100 dark:bg-slate-800" />
                    </div>
                    <span className="ml-auto rounded-md bg-primary/10 px-2 py-1 text-[10px] font-semibold text-primary">
                        Present
                    </span>
                </div>

                <div className="mt-6 flex h-24 items-end justify-between gap-2">
                    {bars.map((h, i) => (
                        <div
                            key={i}
                            className={`w-full rounded-md ${i === 3 ? 'bg-primary' : 'bg-slate-100 dark:bg-slate-800'}`}
                            style={{ height: `${h}%` }}
                        />
                    ))}
                </div>

                <div className="mt-5 grid grid-cols-2 gap-3">
                    <div className="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60">
                        <div className="text-lg font-bold text-slate-900 dark:text-white">98%</div>
                        <div className="text-[10px] text-slate-400">Attendance</div>
                    </div>
                    <div className="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60">
                        <div className="text-lg font-bold text-slate-900 dark:text-white">42</div>
                        <div className="text-[10px] text-slate-400">Present today</div>
                    </div>
                </div>
            </div>

            {/* Floating pills */}
            <div className="absolute -right-5 top-10 flex items-center gap-1.5 rounded-xl bg-white px-3 py-2 text-xs font-medium text-slate-700 shadow-lg dark:bg-slate-900 dark:text-slate-200">
                <span className="flex h-4 w-4 items-center justify-center rounded-full bg-primary text-primary-foreground">
                    <Check className="h-2.5 w-2.5" />
                </span>
                Checked in
            </div>
            <div className="absolute -left-5 bottom-12 rounded-xl bg-white px-3 py-2 text-xs font-medium text-slate-700 shadow-lg dark:bg-slate-900 dark:text-slate-200">
                On time · 08:02
            </div>
        </div>
    );
}
