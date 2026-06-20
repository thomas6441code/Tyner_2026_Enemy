import { cn } from '@/lib/utils';

/**
 * The "tyner" wordmark — a rounded lowercase logotype with the three signature dots
 * (cyan / sky / blue) sitting above the word. Sizes with the font-size of `className`
 * (everything inside is em-based), and inherits the current text color for the letters.
 */
export function Logo({ className }: { className?: string }) {
    return (
        <span
            className={cn(
                'relative inline-block select-none font-bold lowercase leading-none tracking-tight text-foreground',
                className,
            )}
            style={{ fontFamily: '"Quicksand", "Inter", sans-serif' }}
        >
            <span className="absolute left-1/2 top-[-0.4em] flex -translate-x-1/2 gap-[0.16em]">
                <span className="h-[0.2em] w-[0.2em] rounded-full bg-cyan-400" />
                <span className="h-[0.2em] w-[0.2em] rounded-full bg-sky-500" />
                <span className="h-[0.2em] w-[0.2em] rounded-full bg-blue-600" />
            </span>
            tyner
        </span>
    );
}
