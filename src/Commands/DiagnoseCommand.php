<?php

namespace Ashiqfardus\HorizonRunningJobs\Commands;

use Ashiqfardus\HorizonRunningJobs\HealthDiagnoser;
use Illuminate\Console\Command;

class DiagnoseCommand extends Command
{
    protected $signature = 'horizon:diagnose
                            {--json : Output as JSON}';

    protected $description = 'Run a unified health check across supervisors, jobs, and queue depths';

    public function handle(HealthDiagnoser $diagnoser): int
    {
        $result = $diagnoser->diagnose();
        $exitCode = $result['overall_status'] === 'fail' ? self::FAILURE : self::SUCCESS;

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT));
            return $exitCode;
        }

        $this->info('🔍 Horizon Health Diagnosis');
        $this->newLine();

        foreach ($result['checks'] as $check) {
            $marker = match ($check['status']) {
                'pass' => '<fg=green>✓</>',
                'warn' => '<fg=yellow>⚠</>',
                'fail' => '<fg=red>✗</>',
                default => ' ',
            };
            $this->line(sprintf('  %s  %-22s %s', $marker, $check['name'], $check['message']));
        }

        $this->newLine();
        $label = strtoupper($result['overall_status']);
        $color = match ($result['overall_status']) {
            'pass' => 'green',
            'warn' => 'yellow',
            'fail' => 'red',
        };
        $this->line("<fg={$color}>Status: {$label}</>");

        return $exitCode;
    }
}
