import { Link } from '@inertiajs/react';
import { BellRing, Check, Fingerprint, TrendingUp } from 'lucide-react';
import { PropsWithChildren, ReactNode, useEffect, useState } from 'react';

import { AuthIllustration } from '@/components/auth-illustration';
import { Logo } from '@/components/logo';
import { cn } from '@/lib/utils';

export interface AuthSlide {
    key: string;
    title: string;
    description: string;
    /** Rendered React content for the slide (a "template"). Ignored when `image` is set. */
    visual?: ReactNode;
    /** Plain image slide — path or URL, shown as a rounded card. */
    image?: string;
}

const SLIDE_INTERVAL_MS = 6000;

/** "On-time score" ring card with floating biometric chips. */
function ScoreSlide() {
    return (
        <div className="relative w-full max-w-sm">
            <div className="absolute -left-6 top-4 flex h-12 w-12 items-center justify-center rounded-full bg-white text-primary shadow-lg dark:bg-slate-900">
                <Fingerprint className="h-5 w-5" />
            </div>
            <div className="absolute -right-4 bottom-8 flex h-12 w-12 items-center justify-center rounded-full bg-white text-primary shadow-lg dark:bg-slate-900">
                <BellRing className="h-5 w-5" />
            </div>

            <div className="relative mx-auto w-64 rounded-2xl bg-white p-6 text-center shadow-xl dark:bg-slate-900">
                <div className="text-sm font-semibold text-slate-900 dark:text-white">On-time score</div>
                <div className="relative mx-auto mt-4 h-32 w-32">
                    <svg viewBox="0 0 128 128" className="h-full w-full -rotate-90">
                        <circle cx="64" cy="64" r="54" fill="none" strokeWidth="10" className="stroke-slate-100 dark:stroke-slate-800" />
                        <circle
                            cx="64"
                            cy="64"
                            r="54"
                            fill="none"
                            strokeWidth="10"
                            strokeLinecap="round"
                            strokeDasharray="339.3"
                            strokeDashoffset="27"
                            className="stroke-primary"
                        />
                    </svg>
                    <div className="absolute inset-0 flex flex-col items-center justify-center">
                        <span className="text-2xl font-bold text-slate-900 dark:text-white">92%</span>
                        <span className="text-[10px] text-slate-400">this month</span>
                    </div>
                </div>
                <div className="mt-4 flex items-center justify-center gap-1.5 text-xs font-medium text-slate-500 dark:text-slate-400">
                    <span className="flex h-4 w-4 items-center justify-center rounded-full bg-primary text-primary-foreground">
                        <Check className="h-2.5 w-2.5" />
                    </span>
                    Punctuality up 4% vs last month
                </div>
            </div>
        </div>
    );
}

/** Attendance trend area-chart card with a floating sync pill. */
function TrendSlide() {
    return (
        <div className="relative w-full max-w-sm">
            <div className="relative rounded-2xl bg-white p-5 shadow-xl dark:bg-slate-900">
                <div className="flex items-baseline justify-between">
                    <div>
                        <div className="text-2xl font-bold text-slate-900 dark:text-white">1,248</div>
                        <div className="text-[11px] text-slate-400">check-ins this month</div>
                    </div>
                    <span className="flex items-center gap-1 rounded-md bg-primary/10 px-2 py-1 text-[10px] font-semibold text-primary">
                        <TrendingUp className="h-3 w-3" /> +8.3%
                    </span>
                </div>

                <svg viewBox="0 0 280 110" className="mt-4 w-full">
                    <defs>
                        <linearGradient id="guest-trend-fill" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" className="[stop-color:var(--primary)]" stopOpacity="0.25" />
                            <stop offset="100%" className="[stop-color:var(--primary)]" stopOpacity="0" />
                        </linearGradient>
                    </defs>
                    <path
                        d="M0 80 C 30 70, 50 40, 80 48 S 130 90, 160 70 S 210 20, 240 32 S 270 50, 280 42 L 280 110 L 0 110 Z"
                        fill="url(#guest-trend-fill)"
                    />
                    <path
                        d="M0 80 C 30 70, 50 40, 80 48 S 130 90, 160 70 S 210 20, 240 32 S 270 50, 280 42"
                        fill="none"
                        strokeWidth="2.5"
                        strokeLinecap="round"
                        className="stroke-primary"
                    />
                </svg>
                <div className="mt-2 flex justify-between text-[10px] font-medium text-slate-400">
                    {['MAR', 'APR', 'MAY', 'JUN', 'JUL'].map((m) => (
                        <span key={m} className={m === 'JUL' ? 'text-primary' : undefined}>
                            {m}
                        </span>
                    ))}
                </div>
            </div>

            <div className="absolute -right-5 -top-4 flex items-center gap-1.5 rounded-xl bg-white px-3 py-2 text-xs font-medium text-slate-700 shadow-lg dark:bg-slate-900 dark:text-slate-200">
                <span className="flex h-4 w-4 items-center justify-center rounded-full bg-primary text-primary-foreground">
                    <Check className="h-2.5 w-2.5" />
                </span>
                Devices synced
            </div>
        </div>
    );
}

const defaultSlides: AuthSlide[] = [
    {
        key: 'sync',
        title: 'Attendance & permissions, finally in sync.',
        description: 'Biometric punches and HR approvals reconcile automatically — approved leave never reads as absent.',
        visual: <AuthIllustration />,
    },
    {
        key: 'score',
        title: 'Know who shows up, before it matters.',
        description: 'AI anomaly detection and absenteeism prediction turn raw punches into early, actionable signals.',
        visual: <ScoreSlide />,
    },
    {
        key: 'reports',
        title: 'Reports that explain themselves.',
        description: 'Monthly narratives written from aggregated stats only — clear summaries with zero PII exposure.',
        visual: <TrendSlide />,
    },
];

/** Geometric shapes echoing the brand panel background pattern. */
function PanelDecorations() {
    return (
        <div aria-hidden className="pointer-events-none absolute inset-0 overflow-hidden">
            <div className="absolute -right-20 -top-20 h-72 w-72 rounded-full bg-white/10 blur-3xl" />
            <div className="absolute -bottom-24 -left-16 h-80 w-80 rounded-full bg-black/10 blur-3xl" />

            {/* Stepped squares, top-right */}
            <div className="absolute right-8 top-8 grid grid-cols-4 gap-1.5">
                {Array.from({ length: 12 }).map((_, i) => (
                    <div key={i} className={cn('h-5 w-5', i % 3 === 0 ? 'bg-white/15' : 'bg-white/5')} />
                ))}
            </div>

            {/* Triangle row, upper-left */}
            <div className="absolute left-10 top-16 flex gap-2 opacity-30">
                {Array.from({ length: 4 }).map((_, i) => (
                    <div key={i} className="h-5 w-5 bg-white/40 [clip-path:polygon(50%_0,100%_100%,0_100%)]" />
                ))}
            </div>

            {/* Dot grid, bottom-right */}
            <div
                className="absolute bottom-24 right-12 h-28 w-28 opacity-25"
                style={{ backgroundImage: 'radial-gradient(rgba(255,255,255,0.7) 1.5px, transparent 1.5px)', backgroundSize: '14px 14px' }}
            />
        </div>
    );
}

export default function GuestLayout({ children, slides = defaultSlides }: PropsWithChildren<{ slides?: AuthSlide[] }>) {
    const [index, setIndex] = useState(0);
    const [paused, setPaused] = useState(false);

    useEffect(() => {
        if (paused || slides.length <= 1) return;
        const id = setInterval(() => setIndex((i) => (i + 1) % slides.length), SLIDE_INTERVAL_MS);
        return () => clearInterval(id);
    }, [paused, slides.length]);

    return (
        <div className="flex min-h-screen bg-background text-foreground">
            {/* Form panel */}
            <div className="flex w-full flex-col px-6 py-8 sm:px-12 lg:w-1/2 xl:w-[45%]">
                <Link href="/" className="text-center pt-2 mt-5  rounded-full w-30 h-30 flex items-center justify-center mx-auto">
                    <Logo className="text-[2.5rem]" />
                </Link>

                <div className="flex flex-1 items-center justify-center md:py-10">
                    <div className="w-full max-w-sm">{children}</div>
                </div>

                <p className="text-center text-xs text-muted-foreground">
                    © {new Date().getFullYear()} Tyner EAPMS. All rights reserved.
                </p>
            </div>

            {/* Brand carousel panel */}
            <div
                className="relative hidden overflow-hidden bg-primary bg-gradient-to-br from-white/10 via-transparent to-black/25 lg:block lg:w-1/2 xl:w-[55%]"
                onMouseEnter={() => setPaused(true)}
                onMouseLeave={() => setPaused(false)}
                role="region"
                aria-roledescription="carousel"
                aria-label="Product highlights"
            >
                <PanelDecorations />

                {slides.map((slide, i) => (
                    <div
                        key={slide.key}
                        aria-hidden={i !== index}
                        className={cn(
                            'absolute inset-0 flex flex-col items-center justify-center px-12 pb-28 pt-16 transition-all duration-700 ease-out',
                            i === index ? 'translate-x-0 opacity-100' : 'pointer-events-none translate-x-8 opacity-0',
                        )}
                    >
                        <div className="flex min-h-0 flex-1 items-center justify-center">
                            {slide.image ? (
                                <img
                                    src={slide.image}
                                    alt=""
                                    className="max-h-[52vh] w-auto max-w-full rounded-2xl object-cover shadow-2xl"
                                />
                            ) : (
                                slide.visual
                            )}
                        </div>

                        <div className="mt-10 max-w-md text-center">
                            <h2 className="text-3xl font-bold leading-snug tracking-tight text-white">{slide.title}</h2>
                            <p className="mt-3 text-sm leading-relaxed text-white/75">{slide.description}</p>
                        </div>
                    </div>
                ))}

                {slides.length > 1 && (
                    <div className="absolute bottom-10 left-1/2 z-10 flex -translate-x-1/2 gap-2">
                        {slides.map((slide, i) => (
                            <button
                                key={slide.key}
                                type="button"
                                onClick={() => setIndex(i)}
                                aria-label={`Go to slide ${i + 1}: ${slide.title}`}
                                aria-current={i === index}
                                className={cn(
                                    'h-2 rounded-full transition-all duration-300',
                                    i === index ? 'w-6 bg-white' : 'w-2 bg-white/40 hover:bg-white/70',
                                )}
                            />
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}
