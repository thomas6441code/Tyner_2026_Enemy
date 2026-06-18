<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class EapmsStart extends Command
{
    protected $signature = 'eapms:start
        {--port=8000    : Port for yner_main (Laravel)}
        {--ai-port=8001 : Port for ai-service}
        {--bio-port=8002 : Port for bio-service}
        {--no-ai        : Skip starting ai-service}
        {--no-bio       : Skip starting bio-service}';

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

        $started = [];

        if (! $this->option('no-ai')) {
            $port    = $this->option('ai-port');
            $dir     = $root . DIRECTORY_SEPARATOR . 'ai-service';
            $logFile = $logDir . DIRECTORY_SEPARATOR . 'ai-service.log';
            $this->launchPythonService('ai-service', $dir, $port, $logFile);
            $started[] = ['name' => 'ai-service', 'port' => $port, 'log' => $logFile, 'color' => 'cyan'];
        }

        if (! $this->option('no-bio')) {
            $port    = $this->option('bio-port');
            $dir     = $root . DIRECTORY_SEPARATOR . 'bio-service';
            $logFile = $logDir . DIRECTORY_SEPARATOR . 'bio-service.log';
            $this->launchPythonService('bio-service', $dir, $port, $logFile);
            $started[] = ['name' => 'bio-service', 'port' => $port, 'log' => $logFile, 'color' => 'magenta'];
        }

        // Give Python services a moment to boot
        if ($started) {
            $this->line(' <fg=gray>Waiting for Python services to boot...</>');
            sleep(2);
        }

        // Show initial status snapshot
        $this->renderStatusTable($started);

        // Start Laravel serve in the foreground (keeps the terminal alive and streams its output)
        $mainPort = $this->option('port');
        $this->line('');
        $this->line(" <fg=green;options=bold>▶ Starting yner_main on port {$mainPort} (foreground — Ctrl+C to stop all)</>  ");
        $this->line('');

        $artisan = PHP_BINARY . ' ' . base_path('artisan');
        passthru("{$artisan} serve --host=0.0.0.0 --port={$mainPort}");

        return self::SUCCESS;
    }

    // ── service launcher ──────────────────────────────────────────────────────

    private function launchPythonService(string $name, string $dir, int|string $port, string $logFile): void
    {
        $uvicorn = $this->uvicornBin($dir);

        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = "cmd /c \"cd /d {$dir} && {$uvicorn} main:app --host 0.0.0.0 --port {$port} >> \"{$logFile}\" 2>&1\"";
            pclose(popen("start /B {$cmd}", 'r'));
        } else {
            $cmd = "cd {$dir} && {$uvicorn} main:app --host 0.0.0.0 --port {$port} >> {$logFile} 2>&1 &";
            shell_exec($cmd);
        }

        $this->line(" <fg=gray>  [{$name}] started → log: storage/logs/services/{$name}.log</>");
    }

    private function uvicornBin(string $serviceDir): string
    {
        $win  = $serviceDir . '/venv/Scripts/uvicorn.exe';
        $unix = $serviceDir . '/venv/bin/uvicorn';

        if (file_exists($win))  return "\"{$win}\"";
        if (file_exists($unix)) return $unix;

        return 'uvicorn';
    }

    // ── status table ─────────────────────────────────────────────────────────

    private function renderStatusTable(array $pythonServices): void
    {
        $rows   = [];
        $allUp  = true;

        // yner_main is always up (we ARE running inside it)
        $rows[] = [
            "<fg=green>●</> <fg=green;options=bold>UP</>",
            "<options=bold>yner_main</>",
            ":{$this->option('port')}",
            "running",
        ];

        foreach ($pythonServices as $svc) {
            $up = $this->ping("http://127.0.0.1:{$svc['port']}/health");
            if (! $up) $allUp = false;
            $statusIcon  = $up ? "<fg=green>●</> <fg=green;options=bold>UP  </>" : "<fg=red>●</> <fg=red;options=bold>DOWN</>";
            $rows[] = [
                $statusIcon,
                "<options=bold>{$svc['name']}</>",
                ":{$svc['port']}",
                $up ? "running" : "starting...",
            ];
        }

        $this->line('');
        $this->line(' <fg=cyan;options=bold>EAPMS Service Status</>');
        $this->table(['Status', 'Service', 'Port', 'Info'], $rows);

        if (! $allUp) {
            $this->line(' <fg=yellow>Some services are still starting. Run <options=bold>php artisan eapms:status</> to check again.</>');
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

    // ── helpers ──────────────────────────────────────────────────────────────

    private function printBanner(): void
    {
        $this->line('');
        $this->line(' <fg=cyan;options=bold>╔══════════════════════════════════════════════════════╗</>');
        $this->line(' <fg=cyan;options=bold>║    EAPMS  ·  Employee Attendance & Permission Mgmt   ║</>');
        $this->line(' <fg=cyan;options=bold>╚══════════════════════════════════════════════════════╝</>');
        $this->line('');
        $this->line("  <fg=green>●</> yner_main (Laravel)   http://127.0.0.1:{$this->option('port')}");
        if (! $this->option('no-ai')) {
            $this->line("  <fg=cyan>●</> ai-service (FastAPI)   http://127.0.0.1:{$this->option('ai-port')}");
        }
        if (! $this->option('no-bio')) {
            $this->line("  <fg=magenta>●</> bio-service (FastAPI)  http://127.0.0.1:{$this->option('bio-port')}");
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
