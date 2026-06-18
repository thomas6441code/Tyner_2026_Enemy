<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class EapmsStatus extends Command
{
    protected $signature = 'eapms:status {--watch : Refresh every 5 seconds}';

    protected $description = 'Show live health status of all EAPMS services';

    private array $services = [
        'yner_main (Laravel)' => ['url' => null,                        'port' => 8000],
        'ai-service'          => ['url' => 'http://127.0.0.1:8001',     'port' => 8001],
        'bio-service'         => ['url' => 'http://127.0.0.1:8002',     'port' => 8002],
    ];

    public function handle(): int
    {
        $watch = $this->option('watch');

        do {
            $this->renderDashboard();

            if ($watch) {
                sleep(5);
                $this->output->write("\033[" . (count($this->services) + 6) . "A"); // move cursor up
            }
        } while ($watch);

        return self::SUCCESS;
    }

    private function renderDashboard(): void
    {
        $this->line('');
        $this->line(' <fg=cyan;options=bold>EAPMS — Employee Attendance & Permission Management System</>');
        $this->line(' <fg=gray>Service Health Dashboard — ' . now()->format('Y-m-d H:i:s') . '</>');
        $this->line(' ' . str_repeat('─', 55));

        foreach ($this->services as $name => $config) {
            [$status, $latency] = $this->checkService($name, $config);

            $icon    = $status === 'UP' ? '<fg=green>●</>' : '<fg=red>●</>';
            $badge   = $status === 'UP' ? '<fg=green;options=bold> UP  </>' : '<fg=red;options=bold> DOWN</>';
            $portStr = "<fg=gray>:{$config['port']}</>";
            $latStr  = $status === 'UP' ? " <fg=gray>{$latency}ms</>" : '';

            $this->line("  {$icon} {$badge}  <options=bold>{$name}</>  {$portStr}{$latStr}");
        }

        $this->line(' ' . str_repeat('─', 55));
        $this->line(' <fg=gray>Run <options=bold>php artisan eapms:start</> to launch all services.</>');
        $this->line('');
    }

    private function checkService(string $name, array $config): array
    {
        // yner_main checks itself — always up if this command runs
        if ($config['url'] === null) {
            return ['UP', 0];
        }

        $start = microtime(true);

        try {
            $response = Http::timeout(2)->get("{$config['url']}/health");
            $latency  = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful() && ($response->json('status') === 'ok')) {
                return ['UP', $latency];
            }
        } catch (\Throwable) {
            // service unreachable
        }

        return ['DOWN', 0];
    }
}
