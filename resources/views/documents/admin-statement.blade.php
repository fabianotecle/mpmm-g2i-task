<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 20px 24px 28px; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #17324d; font-family: DejaVu Sans, sans-serif; font-size: 7.5px; }
        .page { page-break-after: always; }
        .page:last-child { page-break-after: auto; }
        .header, table { width: 100%; border-collapse: collapse; }
        .header { background: #173f68; border-bottom: 4px solid #ffb515; color: white; margin-bottom: 7px; }
        .header td { padding: 9px 12px; }
        .logo { width: 48px; height: 35px; object-fit: cover; vertical-align: middle; margin-right: 8px; }
        .title { font-size: 17px; font-weight: bold; text-align: right; }
        .warning { color: #ffda72; font-weight: bold; text-align: right; }
        .identity td { border-bottom: 1px solid #d9e6f0; padding: 3px 5px; }
        .label { color: #667d91; width: 12%; }
        .value { font-weight: bold; width: 21%; }
        .stats { margin: 8px 0; table-layout: fixed; }
        .stats td { padding: 2px; }
        .card { background: #edf6fc; border: 1px solid #cfe4f3; border-top: 2px solid #ffb515; min-height: 33px; padding: 4px; }
        .card-label { color: #60788d; font-size: 6px; text-transform: uppercase; }
        .card-value { color: #165f96; font-size: 9px; font-weight: bold; margin-top: 2px; text-align: right; }
        .events { color: #526d83; margin: 5px 0 8px; }
        .movements { table-layout: fixed; }
        .movements thead { display: table-header-group; }
        .movements th { background: #1d70ad; color: white; padding: 4px; text-align: left; }
        .movements td { border-bottom: 1px solid #dfeaf2; padding: 4px; vertical-align: top; }
        .movements tbody tr:nth-child(even) { background: #f3f8fc; }
        .date { width: 17%; } .description { width: 47%; }
        .money { text-align: right; width: 12%; white-space: nowrap; }
        .credit { color: #176da8; } .debit { color: #b34848; }
        .footer { color: #70879a; margin-top: 5px; text-align: center; }
        .page-number { float: right; }
    </style>
</head>
<body>
@foreach ($pages->isEmpty() ? collect([collect()]) : $pages as $pageIndex => $pageMovements)
    <section class="page">
        <table class="header"><tr>
            <td>@if ($logoData)<img class="logo" src="{{ $logoData }}">@endif <strong>MyPhone MyMoney®</strong></td>
            <td><div class="title">Extrato administrativo completo</div><div class="warning">EXCLUSIVO PARA ADMINISTRADORES · SEM LIMITE DE MOVIMENTOS</div></td>
        </tr></table>

        <table class="identity">
            <tr><td class="label">ID do Cartão</td><td class="value">{{ $customer->id }}</td><td class="label">WhatsApp</td><td class="value">{{ $formattedPhone }}</td><td class="label">Nome WhatsApp</td><td class="value">{{ $whatsappName }}</td></tr>
            <tr><td class="label">Nome cadastrado</td><td class="value">{{ $customer->name ?: 'Não informado' }}</td><td class="label">CPF/CNPJ</td><td class="value">{{ $customer->cpf ?: 'Não informado' }}</td><td class="label">Status / Opt-in</td><td class="value">{{ $customer->status }} / {{ $customer->opt_in ? 'Sim' : 'Não' }}</td></tr>
            <tr><td class="label">Cartão criado</td><td class="value">{{ $customer->created_at?->timezone($timezone)->format('d/m/Y H:i:s') }}</td><td class="label">Primeiro acesso</td><td class="value">{{ $firstAccessAt ? \Illuminate\Support\Carbon::parse($firstAccessAt)->timezone($timezone)->format('d/m/Y H:i:s') : 'Não registrado' }}</td><td class="label">Último acesso</td><td class="value">{{ $lastAccessAt ? \Illuminate\Support\Carbon::parse($lastAccessAt)->timezone($timezone)->format('d/m/Y H:i:s') : 'Não registrado' }}</td></tr>
        </table>

        @if ($pageIndex === 0)
            @php($cards = [
                'Movimentações' => $statistics['movement_count'], 'Entradas (qtd.)' => $statistics['credits_count'],
                'Saídas (qtd.)' => $statistics['debits_count'], 'Dias ativos' => $statistics['active_days'],
                'Total de entradas' => $statistics['credits_total'], 'Total de saídas' => $statistics['debits_total'],
                'Maior entrada' => $statistics['largest_credit'], 'Maior saída' => $statistics['largest_debit'],
                'Valor médio' => $statistics['average_amount'], 'Saldo atual' => (float) $customer->balance,
                'Cashback' => (float) $customer->redeemable_balance, 'Limite' => (float) $customer->limit,
                'Sessões' => $statistics['sessions_count'],
            ])
            <table class="stats">
                @foreach (array_chunk($cards, 7, true) as $cardRow)<tr>
                    @foreach ($cardRow as $label => $value)<td><div class="card"><div class="card-label">{{ $label }}</div><div class="card-value">{{ in_array($label, ['Movimentações', 'Entradas (qtd.)', 'Saídas (qtd.)', 'Dias ativos', 'Sessões']) ? number_format($value, 0, ',', '.') : 'R$ '.number_format($value, 2, ',', '.') }}</div></div></td>@endforeach
                </tr>@endforeach
            </table>
        @endif

        <table class="movements">
            <thead><tr><th class="date">Data</th><th class="description">Descrição</th><th class="money">Entrada</th><th class="money">Saída</th><th class="money">Saldo</th></tr></thead>
            <tbody>
            @forelse ($pageMovements as $row)
                <tr><td>{{ $row['date'] }}</td><td>{{ $row['description'] }}</td><td class="money credit">{{ $row['credit'] > 0 ? number_format($row['credit'], 2, ',', '.') : '' }}</td><td class="money debit">{{ $row['debit'] > 0 ? number_format($row['debit'], 2, ',', '.') : '' }}</td><td class="money">{{ number_format($row['balance'], 2, ',', '.') }}</td></tr>
            @empty
                <tr><td colspan="5" style="padding: 20px; text-align: center">Cartão sem movimentações financeiras de frontoffice.</td></tr>
            @endforelse
            </tbody>
        </table>
        <div class="footer">Emitido em {{ $generatedAt->timezone($timezone)->format('d/m/Y H:i:s') }} · Documento administrativo confidencial <span class="page-number">Página {{ $pageIndex + 1 }} de {{ max(1, $pages->count()) }}</span></div>
    </section>
@endforeach
</body>
</html>
