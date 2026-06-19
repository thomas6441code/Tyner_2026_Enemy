<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class EapmsStatus extends Command
{
    protected $signature = 'eapms:status {--watch : Refresh every 5 seconds}';

    protected $description = 'Show live health status of all EAPMS services';

    private array $services = [
        'yner_main (Laravel)' => ['url' => null,                    'port' => 8000],
        'ai-service (FastAPI)' => ['url' => 'http://127.0.0.1:8001', 'port' => 8001],
        'bio-service (FastAPI)' => ['url' => 'http://127.0.0.1:8002', 'port' => 8002],
    ];

    public function handle(): int
    {
        $watch = $this->option('watch');

        if ($watch) {
            $this->line(' <fg=gray>Watching — press Ctrl+C to exit.</>');
        }

        do {
            if ($watch) {
                // ANSI: clear screen and move cursor to top-left
                $this->output->write("\033[2J\033[H");
            }

            $this->renderDashboard();

            if ($watch) {
                sleep(5);
            }
        } while ($watch);

        return self::SUCCESS;
    }

    private function renderDashboard(): void
    {
        $rows = [];
        $allUp = true;
        $anyDown = false;

        foreach ($this->services as $name => $config) {
            [$status, $latency] = $this->checkService($config);

            if ($status !== 'UP') {
                $allUp = false;
                $anyDown = true;
            }

            $statusCell = $status === 'UP'
                ? '<fg=green>● UP  </>'
                : '<fg=red>● DOWN</>';
            $latencyCell = $status === 'UP' && $latency > 0
                ? "{$latency} ms"
                : ($status === 'UP' ? 'self' : '—');

            $rows[] = [$statusCell, "<options=bold>{$name}</>", ":{$config['port']}", $latencyCell];
        }

        $this->line('');
        $this->line(' <fg=cyan;options=bold>EAPMS — Employee Attendance & Permission Management System</>');
        $this->line(' <fg=gray>'.now()->format('Y-m-d H:i:s').'</>');
        $this->line('');
        $this->table(['Status', 'Service', 'Port', 'Latency'], $rows);

        if ($allUp) {
            $this->line(' <fg=green>All services are running.</>');
        } else {
            $this->line(' <fg=yellow>One or more services are down. Check <options=bold>storage/logs/services/</> for details.</>');
        }

        if ($this->option('watch')) {
            $this->line(' <fg=gray>Next refresh in 5 s — Ctrl+C to exit.</>');
        } else {
            $this->line(' <fg=gray>Run with <options=bold>--watch</> to auto-refresh every 5 s.</>');
        }

        $this->line('');
    }

    private function checkService(array $config): array
    {
        // yner_main — if this command is running, Laravel is alive
        if ($config['url'] === null) {
            return ['UP', 0];
        }

        $start = microtime(true);
        try {
            $resp = Http::timeout(2)->get("{$config['url']}/health");
            $latency = (int) ((microtime(true) - $start) * 1000);
            if ($resp->successful() && $resp->json('status') === 'ok') {
                return ['UP', $latency];
            }
        } catch (\Throwable) {
            // unreachable
        }

        return ['DOWN', 0];
    }
}
