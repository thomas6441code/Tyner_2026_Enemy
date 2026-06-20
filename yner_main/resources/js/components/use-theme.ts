import { useCallback, useEffect, useState } from 'react';

export type Theme = 'light' | 'dark';

function currentTheme(): Theme {
    if (typeof document === 'undefined') {
        return 'light';
    }
    return document.documentElement.classList.contains('dark') ? 'dark' : 'light';
}

/**
 * Light/dark theme state synced to <html>.dark and localStorage. The initial class is
 * applied pre-paint by the inline script in app.blade.php, so this just mirrors/toggles it.
 */
export function useTheme() {
    const [theme, setThemeState] = useState<Theme>(currentTheme);

    useEffect(() => {
        const root = document.documentElement;
        root.classList.toggle('dark', theme === 'dark');
        try {
            localStorage.setItem('theme', theme);
        } catch {
            /* ignore storage errors (private mode, etc.) */
        }
    }, [theme]);

    const setTheme = useCallback((next: Theme) => setThemeState(next), []);
    const toggle = useCallback(() => setThemeState((t) => (t === 'dark' ? 'light' : 'dark')), []);

    return { theme, setTheme, toggle };
}
