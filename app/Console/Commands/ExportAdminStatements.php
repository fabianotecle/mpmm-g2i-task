<?php

namespace App\Console\Commands;

use App\Services\Documents\AdminStatementExportService;
use Illuminate\Console\Command;

class ExportAdminStatements extends Command
{
    protected $signature = 'statements:export-admin {--directory=}';

    protected $description = 'Exporta PDFs completos e Excel administrativo de todos os Cartões';

    public function handle(AdminStatementExportService $exporter): int
    {
        $this->info('Gerando extratos administrativos completos...');
        $bar = $this->output->createProgressBar();
        $result = $exporter->exportAll(
            $this->option('directory') ?: null,
            function (int $current, int $total) use ($bar): void {
                if ($bar->getMaxSteps() === 0) {
                    $bar->setMaxSteps($total);
                }
                $bar->setProgress($current);
            },
        );
        $bar->finish();
        $this->newLine(2);
        $this->table(['Cartões', 'Movimentos', 'Excel', 'Pacote ZIP'], [[
            $result['customers'], $result['movements'], $result['xlsx'], $result['zip'],
        ]]);

        return self::SUCCESS;
    }
}
