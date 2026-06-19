<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class EapmsStart extends Command
{
    protected $signature = 'eapms:start
        {--port=8000     : Port for yner_main (Laravel)}
        {--ai-port=8001  : Port for ai-service}
        {--bio-port=8002 : Port for bio-service}
        {--no-ai         : Skip starting ai-service}
        {--no-bio        : Skip starting bio-service}
        {--no-vite       : Skip starting the Vite dev server}';

    protected $description = 'Start all EAPMS services and stream their status to the console';

    public function handle(): int
    {
        $root = $this->projectRoot();

        if (! $root) {
            $this->error('Could not find project root — ai-service/ and bio-service/ must be siblings of yner_main/.');

            return self::FAILURE;
        }

        $this->printBanner();

        $logDir = storage_path('logs/services');
        if (! is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        if (! $this->option('no-vite')) {
            $this->launchVite($logDir.DIRECTORY_SEPARATOR.'vite.log');
        }

        $started = [];

        if (! $this->option('no-ai')) {
            $port = $this->option('ai-port');
            $dir = $root.DIRECTORY_SEPARATOR.'ai-service';
            $logFile = $logDir.DIRECTORY_SEPARATOR.'ai-service.log';
            $this->ensureVenv('ai-service', $dir);
            $this->launchService('ai-service', $dir, (int) $port, $logFile);
            $started[] = ['name' => 'ai-service', 'port' => (int) $port, 'log' => $logFile];
        }

        if (! $this->option('no-bio')) {
            $port = $this->option('bio-port');
            $dir = $root.DIRECTORY_SEPARATOR.'bio-service';
            $logFile = $logDir.DIRECTORY_SEPARATOR.'bio-service.log';
            $this->ensureVenv('bio-service', $dir);
            $this->launchService('bio-service', $dir, (int) $port, $logFile);
            $started[] = ['name' => 'bio-service', 'port' => (int) $port, 'log' => $logFile];
        }

        if ($started) {
            $this->line(' <fg=gray>Waiting for Python services to boot...</>');
            sleep(3);
        }

        $this->renderStatusTable($started);

        $mainPort = $this->option('port');
        $this->line('');
        $this->line(" <fg=green;options=bold>▶ yner_main starting on port {$mainPort} (foreground — Ctrl+C to stop all)</>");
        $this->line('');

        passthru(PHP_BINARY.' '.base_path('artisan')." serve --host=0.0.0.0 --port={$mainPort}");

        return self::SUCCESS;
    }

    // ── venv management ───────────────────────────────────────────────────────

    private function ensureVenv(string $name, string $dir): void
    {
        $venvDir = $dir.DIRECTORY_SEPARATOR.'venv';
        $isWin = PHP_OS_FAMILY === 'Windows';
        $pyBin = $isWin ? 'python' : 'python3';

        if (! is_dir($venvDir)) {
            $this->line(" <fg=yellow>  [{$name}] No venv found — creating (first time only, please wait)...</>");
            shell_exec("{$pyBin} -m venv \"{$venvDir}\"");
        }

        $pipBin = $isWin
            ? "{$venvDir}\\Scripts\\pip.exe"
            : "{$venvDir}/bin/pip";

        // Check if uvicorn is installed as a proxy for whether requirements were run
        $uvicorn = $isWin
            ? "{$venvDir}\\Scripts\\uvicorn.exe"
            : "{$venvDir}/bin/uvicorn";

        if (! file_exists($uvicorn)) {
            $this->line(" <fg=yellow>  [{$name}] Installing requirements...</>");
            $req = $dir.DIRECTORY_SEPARATOR.'requirements.txt';
            shell_exec("\"{$pipBin}\" install -r \"{$req}\" --quiet 2>&1");
            $this->line(" <fg=green>  [{$name}] Dependencies installed.</>");
        }
    }

    // ── vite dev server ───────────────────────────────────────────────────────

    private function launchVite(string $logFile): void
    {
        $dir = base_path();

        if (PHP_OS_FAMILY === 'Windows') {
            $bat = sys_get_temp_dir().'\\eapms_vite.bat';
            $batContent = "@echo off\r\ncd /d \"{$dir}\"\r\nnpm run dev >> \"{$logFile}\" 2>&1\r\n";
            file_put_contents($bat, $batContent);
            pclose(popen("start /B \"\" \"{$bat}\"", 'r'));
        } else {
            shell_exec("cd \"{$dir}\" && npm run dev >> \"{$logFile}\" 2>&1 &");
        }

        $this->line(' <fg=gray>  [vite] dev server starting → logs/services/vite.log</>');
    }

    // ── process launcher ──────────────────────────────────────────────────────

    private function launchService(string $name, string $dir, int $port, string $logFile): void
    {
        $python = $this->pythonBin($dir);

        if (PHP_OS_FAMILY === 'Windows') {
            // Write a batch file to avoid nested-quote hell with start /B
            $bat = sys_get_temp_dir()."\\eapms_{$name}.bat";
            $batContent = "@echo off\r\ncd /d \"{$dir}\"\r\n\"{$python}\" -m uvicorn main:app --host 0.0.0.0 --port {$port} >> \"{$logFile}\" 2>&1\r\n";
            file_put_contents($bat, $batContent);
            pclose(popen("start /B \"\" \"{$bat}\"", 'r'));
        } else {
            shell_exec("cd \"{$dir}\" && \"{$python}\" -m uvicorn main:app --host 0.0.0.0 --port {$port} >> \"{$logFile}\" 2>&1 &");
        }

        $this->line(" <fg=gray>  [{$name}] started on :{$port} → logs/services/{$name}.log</>");
    }

    private function pythonBin(string $serviceDir): string
    {
        $win = $serviceDir.'\\venv\\Scripts\\python.exe';
        $unix = $serviceDir.'/venv/bin/python';

        if (file_exists($win)) {
            return $win;
        }
        if (file_exists($unix)) {
            return $unix;
        }

        return PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3';
    }

    // ── status table ──────────────────────────────────────────────────────────

    private function renderStatusTable(array $pythonServices): void
    {
        $rows = [];

        $rows[] = [
            '<fg=green>● UP  </>',
            '<options=bold>yner_main</>',
            ':'.$this->option('port'),
            'running (foreground)',
        ];

        foreach ($pythonServices as $svc) {
            $up = $this->ping("http://127.0.0.1:{$svc['port']}/health");
            $rows[] = [
                $up ? '<fg=green>● UP  </>' : '<fg=red>● DOWN</>',
                "<options=bold>{$svc['name']}</>",
                ':'.$svc['port'],
                $up ? 'running' : 'starting… (check logs/services/)',
            ];
        }

        $this->line('');
        $this->line(' <fg=cyan;options=bold>EAPMS Service Status</>');
        $this->table(['Status', 'Service', 'Port', 'Info'], $rows);

        $anyDown = collect($pythonServices)->contains(fn ($s) => ! $this->ping("http://127.0.0.1:{$s['port']}/health"));
        if ($anyDown) {
            $this->line(' <fg=yellow>Tip: run <options=bold>php artisan eapms:status --watch</> in a separate terminal to monitor.</>');
        }
    }

    private function ping(string $url): bool
    {
        try {
            $resp = Http::timeout(2)->get($url);

            return $resp->successful() && $resp->json('status') === 'ok';
        } catch (\Throwable) {
            return false;
        }
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function printBanner(): void
    {
        $this->line('');
        $this->line(' <fg=cyan;options=bold>╔══════════════════════════════════════════════════════╗</>');
        $this->line(' <fg=cyan;options=bold>║    EAPMS  ·  Employee Attendance & Permission Mgmt   ║</>');
        $this->line(' <fg=cyan;options=bold>╚══════════════════════════════════════════════════════╝</>');
        $this->line('');
        $this->line("  <fg=green>●</> yner_main  http://127.0.0.1:{$this->option('port')}");
        if (! $this->option('no-ai')) {
            $this->line("  <fg=cyan>●</> ai-service  http://127.0.0.1:{$this->option('ai-port')}");
        }
        if (! $this->option('no-bio')) {
            $this->line("  <fg=magenta>●</> bio-service  http://127.0.0.1:{$this->option('bio-port')}");
        }
        $this->line('');
    }

    private function projectRoot(): string|false
    {
        $dir = base_path();
        for ($i = 0; $i < 5; $i++) {
            if (is_dir("{$dir}/ai-service") && is_dir("{$dir}/bio-service")) {
                return $dir;
            }
            $dir = dirname($dir);
        }

        return false;
    }
}
