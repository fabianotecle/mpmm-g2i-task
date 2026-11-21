<?php

namespace App\Services\Documents;

use App\Models\Customer;
use App\Models\Movement;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

class AdminStatementExportService
{
    public function __construct(
        private readonly StatementPdfService $statements,
        private readonly SimpleXlsxWriter $xlsx,
    ) {}

    /** @param null|callable(int, int, Customer): void $progress */
    public function exportAll(?string $directory = null, ?callable $progress = null): array
    {
        $generatedAt = now();
        $directory ??= storage_path('app/admin-exports/extratos-administrativos-'.$generatedAt->format('Ymd-His'));
        $pdfDirectory = $directory.DIRECTORY_SEPARATOR.'pdf';
        File::ensureDirectoryExists($pdfDirectory);

        $customers = Customer::query()
            ->withCount('movements')
            ->orderBy('movements_count')
            ->orderBy('id')
            ->get();
        $accountsRows = [[
            'Ordem', 'Movimentos', 'ID', 'WhatsApp', 'Nome WhatsApp', 'Nome cadastrado', 'CPF/CNPJ',
            'Status', 'Opt-in', 'Saldo', 'Cashback', 'Limite', 'Criado em', 'Primeiro acesso',
            'Último acesso', 'Dias ativos', 'Sessões', 'Total entradas', 'Total saídas', 'PDF',
        ]];
        $movementRows = [[
            'Ordem do Cartão', 'Total de movimentos do Cartão', 'ID do Cartão', 'WhatsApp', 'Nome', 'ID movimento',
            'Data', 'Evento', 'Descrição', 'Crédito', 'Débito', 'Saldo', 'Provedor', 'ID externo',
        ]];
        $logoPath = public_path('assets/whatsapp/logo.jpg');
        $logoData = is_file($logoPath)
            ? 'data:image/jpeg;base64,'.base64_encode((string) file_get_contents($logoPath))
            : null;
        $total = $customers->count();

        foreach ($customers as $index => $customer) {
            $data = $this->statements->dataForAdminCustomer($customer);
            $position = $index + 1;
            $pdfName = sprintf(
                '%04d-movimentos_%04d-conta_%s.pdf',
                $customer->movements_count,
                $customer->id,
                preg_replace('/\D+/', '', $customer->phone),
            );
            $pdfPath = $pdfDirectory.DIRECTORY_SEPARATOR.$pdfName;
            if (! File::exists($pdfPath)) {
                $pdf = Pdf::loadView('documents.admin-statement', [
                    ...$data,
                    'logoData' => $logoData,
                    'generatedAt' => $generatedAt,
                ])->setPaper('a4', 'landscape');
                File::put($pdfPath, $pdf->output());
            }

            $stats = $data['statistics'];
            $accountsRows[] = [
                $position,
                $stats['movement_count'],
                $customer->id,
                $customer->phone,
                $data['whatsappName'],
                $customer->name,
                $customer->cpf,
                $customer->status,
                $customer->opt_in ? 'Sim' : 'Não',
                (float) $customer->balance,
                (float) $customer->redeemable_balance,
                (float) $customer->limit,
                $customer->created_at?->timezone($data['timezone'])->format('d/m/Y H:i:s'),
                $this->date($data['firstAccessAt'], $data['timezone']),
                $this->date($data['lastAccessAt'], $data['timezone']),
                $stats['active_days'],
                $stats['sessions_count'],
                $stats['credits_total'],
                $stats['debits_total'],
                $pdfName,
            ];

            foreach ($data['movements'] as $movementIndex => $movement) {
                $row = $data['pages']->flatten(1)->firstWhere('id', $movement->id);
                $movementRows[] = [
                    $position,
                    $stats['movement_count'],
                    $customer->id,
                    $customer->phone,
                    $data['whatsappName'],
                    $movement->id,
                    $row['date'] ?? '',
                    $movement->event,
                    $row['description'] ?? '',
                    (float) $movement->credit,
                    (float) $movement->debit,
                    (float) $movement->balance,
                    $movement->external_provider,
                    $movement->external_operation_id,
                ];
            }

            $progress?->__invoke($position, $total, $customer);
        }

        $xlsxPath = $directory.DIRECTORY_SEPARATOR.'extratos-administrativos.xlsx';
        $this->xlsx->write($xlsxPath, [
            'Cartões por movimentos' => $accountsRows,
            'Todas as movimentações' => $movementRows,
        ]);

        $zipPath = $directory.'.zip';
        $this->zip($zipPath, $directory);

        return [
            'directory' => $directory,
            'zip' => $zipPath,
            'xlsx' => $xlsxPath,
            'customers' => $total,
            'movements' => count($movementRows) - 1,
        ];
    }

    private function date(mixed $value, string $timezone): ?string
    {
        return $value ? Carbon::parse($value)->timezone($timezone)->format('d/m/Y H:i:s') : null;
    }

    private function zip(string $path, string $directory): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Não foi possível criar {$path}.");
        }

        foreach (File::allFiles($directory) as $file) {
            $zip->addFile($file->getRealPath(), str_replace('\\', '/', $file->getRelativePathname()));
        }
        $zip->close();
    }
}
