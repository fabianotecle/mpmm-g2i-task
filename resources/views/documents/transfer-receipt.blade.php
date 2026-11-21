<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 24px; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: DejaVu Sans, sans-serif; color: #17324d; background: #f4f8fb; }
        .card { border: 2px solid #176ca5; background: #fff; }
        .header { background: #126fa9; color: #fff; padding: 22px 28px; }
        .header table { width: 100%; border-collapse: collapse; }
        .logo { width: 76px; height: 76px; object-fit: cover; border-radius: 12px; }
        .brand { font-size: 25px; font-weight: bold; line-height: 1.05; }
        .brand .gold { color: #ffb515; }
        .title { font-size: 19px; font-weight: bold; }
        .mode { color: #ffcf45; font-size: 13px; margin-top: 6px; }
        .contact { font-size: 11px; margin-top: 9px; }
        .body { text-align: center; padding: 34px 52px 42px; }
        .amount { color: #125e92; font-size: 35px; font-weight: bold; margin-bottom: 22px; }
        .date { color: #536b7c; font-size: 14px; line-height: 1.7; margin-bottom: 26px; }
        .account { background: #edf6fc; border-top: 3px solid #ffb515; border-radius: 7px; padding: 15px; margin: 13px 0; }
        .label { color: #60788d; font-size: 10px; text-transform: uppercase; margin-bottom: 7px; }
        .phone { color: #17324d; font-size: 20px; font-weight: bold; }
        .name { color: #486071; font-size: 12px; margin-top: 6px; }
        .control { margin-top: 25px; padding-top: 18px; border-top: 1px solid #cfe4f3; }
        .control-code { color: #486071; font-family: DejaVu Sans Mono, monospace; font-size: 9px; overflow-wrap: anywhere; }
        .notice { color: #60788d; font-size: 9px; margin-top: 21px; }
    </style>
</head>
<body>
<div class="card">
    <div class="header">
        <table>
            <tr>
                <td style="width: 92px;">@if($logoData)<img class="logo" src="{{ $logoData }}" alt="MpMM">@endif</td>
                <td>
                    <div class="brand"><span class="gold">My</span> Phone<br><span class="gold">My</span> Money<sup>&reg;</sup></div>
                    <div class="mode">Comprovante de transfer&ecirc;ncia {{ $private ? 'privada' : 'pública' }}</div>
                </td>
                <td style="text-align: right; vertical-align: top;">
                    <div class="title">MyPhone MyMoney&reg;</div>
                    <div class="contact">WhatsApp 0800 000 0000</div>
                </td>
            </tr>
        </table>
    </div>
    <div class="body">
        <div class="amount">R$ {{ number_format($amount, 2, ',', '.') }}</div>
        <div class="date">
            {{ $transferredAt->copy()->locale('pt_BR')->translatedFormat('l, d \d\e F \d\e Y') }}<br>
            {{ $transferredAt->format('H:i:s') }}
        </div>
        <div class="account">
            <div class="label">DE</div>
            <div class="phone">{{ $senderPhone }}</div>
            @if($senderName)<div class="name">{{ $senderName }}</div>@endif
        </div>
        <div class="account">
            <div class="label">PARA</div>
            <div class="phone">{{ $recipientPhone }}</div>
            @if($recipientName)<div class="name">{{ $recipientName }}</div>@endif
        </div>
        <div class="control">
            <div class="label">C&oacute;digo de controle</div>
            <div class="control-code">{{ $controlCode }}</div>
        </div>
        @if($private)
            <div class="notice">Os nomes e os n&uacute;meros completos foram ocultados por decis&atilde;o da origem por se tratar de uma transfer&ecirc;ncia privada.</div>
        @endif
    </div>
</div>
</body>
</html>
