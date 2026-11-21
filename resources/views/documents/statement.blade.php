<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <style>
        @page { size: A4 portrait; margin: 8mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #17324d; font-family: DejaVu Sans, sans-serif; font-size: 9px; }
        .statement-page { min-height: 279mm; page-break-after: always; position: relative; }
        .statement-page:last-child { page-break-after: auto; }
        .header { background: #1d70ad; border-bottom: 5px solid #ffb515; border-radius: 10px; color: #fff; padding: 12px 16px 10px; }
        .header table, .identity, .summary, .movements { width: 100%; border-collapse: collapse; }
        .brand-logo { height: 44px; width: 58px; border-radius: 7px; object-fit: cover; }
        .brand { font-size: 15px; font-weight: bold; padding-left: 10px; }
        .brand-contact { color: #e9f6ff; font-size: 9px; margin-top: 3px; }
        .whatsapp-logo { height: 15px; margin-right: 5px; vertical-align: middle; width: 15px; }
        .title { font-size: 14px; font-weight: bold; text-align: right; text-transform: uppercase; }
        .description { color: #fff0bd; font-size: 9px; margin-top: 3px; text-align: right; }
        .issued { color: #d9efff; font-size: 8px; margin-top: 3px; text-align: right; }
        .identity { font-size: 8px; margin: 10px 0 9px; text-align: justify; text-align-last: justify; white-space: nowrap; }
        .identity strong { color: #17324d; }
        .summary { margin-bottom: 10px; table-layout: fixed; }
        .summary td { padding: 0 5px 5px 0; width: 25%; }
        .summary td:nth-child(4n) { padding-right: 0; }
        .card { background: #edf6fc; border: 1px solid #cfe4f3; border-top: 2px solid #ffb515; border-radius: 6px; height: 43px; padding: 5px 7px; }
        .card-label { color: #60788d; font-size: 6.8px; text-transform: uppercase; }
        .card-value { color: #165f96; font-size: 10.5px; font-weight: bold; margin-top: 3px; text-align: right; }
        .movements { table-layout: fixed; }
        .movements thead { display: table-header-group; }
        .movements th { background: #1d70ad; color: white; font-size: 7px; padding: 6px; text-align: left; text-transform: uppercase; }
        .movements th.date { width: 22%; }
        .movements th.description-column { width: 48%; }
        .movements th.amount { width: 14%; }
        .movements th.balance { width: 16%; }
        .movements td { border-bottom: 1px solid #dfeaf2; line-height: 1.25; padding: 6px; vertical-align: top; }
        .movements tbody tr:nth-child(even) { background: #f3f8fc; }
        .movements tbody tr.month-break td { border-top: 4px solid #b9d8ec; padding-top: 8px; }
        .amount, .balance { text-align: right !important; white-space: nowrap; }
        .credit { color: #176da8; font-weight: bold; }
        .debit { color: #b34848; font-weight: bold; }
        .empty { background: #f3f8fc; border: 1px solid #dfeaf2; border-radius: 8px; padding: 28px; text-align: center; }
        .footer { bottom: 0; color: #70879a; font-size: 7px; left: 0; position: absolute; right: 0; text-align: center; }
        .footer-contact { left: 0; position: absolute; }
        .page-number { position: absolute; right: 0; }
    </style>
</head>
<body>
@foreach ($pages as $pageIndex => $pageMovements)
    <section class="statement-page">
        <div class="header">
            <table>
                <tr>
                    <td style="width: 62%">
                        <table>
                            <tr>
                                <td style="width: 64px">
                                    @if ($logoData)
                                        <img class="brand-logo" src="{{ $logoData }}" alt="WhatsApp">
                                    @endif
                                </td>
                                <td class="brand">
                                    <em>MyPhone MyMoney®</em>
                                    <div class="brand-contact">@if($whatsappLogoData ?? null)<img class="whatsapp-logo" src="{{ $whatsappLogoData }}" alt="WhatsApp">@endif 0800 000 0000</div>
                                </td>
                            </tr>
                        </table>
                    </td>
                    <td>
                        <div class="title">EXTRATO DOS ÚLTIMOS MOVIMENTOS DO CARTÃO 🎁</div>
                        <div class="issued">Emitido em {{ now()->timezone($timezone)->format('d/m/Y \à\s H:i:s O') }}</div>
                    </td>
                </tr>
            </table>
        </div>

        <div class="identity">Cartão 🎁 vinculado ao nº de WhatsApp <strong>{{ $formattedPhone }}</strong> e nome do membro no WhatsApp <strong>{{ $whatsappName }}</strong></div>

        @if ($pageIndex === 0)
            @php
                $cards = [
                    'Saldo anterior' => $summary['previous_balance'],
                    'Saldo atual' => $summary['current_balance'],
                    'Total de entradas' => $summary['credits'],
                    'Total de saídas' => $summary['debits'],
                    'Movimentações' => $summary['count'],
                    'Total de cargas' => $summary['deposits'],
                    'Total de descargas' => $summary['withdrawals'],
                    'Cargas líquidas' => $summary['net_deposits'],
                    'Cash Back' => $summary['cashback'],
                    'Disponível para descarga' => $summary['available_for_cash_out'],
                    'Transferências recebidas' => $summary['transfers_received'],
                    'Transferências enviadas' => $summary['transfers_sent'],
                    'Crédito concedido' => $summary['credit_limit'],
                    'Crédito utilizado' => $summary['credit_used'],
                    'Crédito disponível' => $summary['credit_available'],
                ];
            @endphp
            <table class="summary">
                @foreach (array_chunk($cards, 4, true) as $cardRow)
                    <tr>
                        @foreach ($cardRow as $label => $value)
                            <td><div class="card"><div class="card-label">{{ $label }}</div><div class="card-value">{{ $label === 'Movimentações' ? number_format((int) $value, 0, ',', '.') : number_format((float) $value, 2, ',', '.') }}</div></div></td>
                        @endforeach
                    </tr>
                @endforeach
            </table>
        @endif

        @if ($pageMovements->isEmpty())
            <div class="empty">Nenhuma movimentação financeira encontrada para este Cartão.</div>
        @else
            <table class="movements">
                <thead>
                    <tr>
                        <th class="date">Data</th>
                        <th class="description-column">Descrição</th>
                        <th class="amount">Valor</th>
                        <th class="balance">Saldo</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($pageMovements as $rowIndex => $row)
                        <tr class="{{ $row['month_break'] ? 'month-break' : '' }}">
                            <td>{{ $row['date'] }}</td>
                            <td>{{ $row['description'] }}</td>
                            <td class="amount {{ $row['direction'] ?? '' }}">
                                @if ($row['amount'] !== null)
                                    {{ $row['amount'] > 0 ? '+' : '−' }} {{ number_format(abs($row['amount']), 2, ',', '.') }}
                                @endif
                            </td>
                            <td class="balance">{{ number_format((float) $row['balance'], 2, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <div class="footer">
            <span class="footer-contact">💬0800 000 0000</span>
            MyPhone MyMoney® · Documento gerado automaticamente
            <span class="page-number">Página {{ $pageIndex + 1 }} de {{ $pages->count() }}</span>
        </div>
    </section>
@endforeach
</body>
</html>
