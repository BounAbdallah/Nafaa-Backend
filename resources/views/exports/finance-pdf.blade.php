<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Rapport Financier</title>
<style>
    @page { margin: 0; }
    body {
        font-family: 'Helvetica', 'Arial', sans-serif;
        color: #0F1E30;
        font-size: 11px;
        line-height: 1.6;
        margin: 0;
        padding: 40px;
        background: #fff;
    }
    .accent-bar { height: 4px; background: #3AA0D8; margin: -40px -40px 30px -40px; }
    h1 { font-size: 22px; font-weight: 900; color: #0F1E30; margin: 0 0 4px; }
    .subtitle { font-size: 10px; color: #7A90A4; letter-spacing: 1px; text-transform: uppercase; }
    .period { font-size: 10px; color: #3D5268; margin-top: 6px; }
    .divider { border: none; border-top: 1px solid #e8edf2; margin: 20px 0; }

    /* KPI cards */
    .kpi-grid { display: table; width: 100%; margin: 16px 0; }
    .kpi-cell { display: table-cell; width: 25%; padding: 0 6px 0 0; }
    .kpi-cell:last-child { padding-right: 0; }
    .kpi { background: #f4f8fb; border-radius: 6px; padding: 12px 14px; }
    .kpi-label { font-size: 9px; color: #7A90A4; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
    .kpi-value { font-size: 15px; font-weight: 900; }
    .kpi-in    { color: #10B981; }
    .kpi-out   { color: #EF4444; }
    .kpi-net   { color: #3AA0D8; }
    .kpi-debt  { color: #F59E0B; }

    /* Tables */
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th { background: #3AA0D8; color: #fff; padding: 7px 10px; text-align: left; font-size: 10px; font-weight: 700; }
    td { padding: 6px 10px; font-size: 10px; border-bottom: 1px solid #f0f4f8; }
    tr:nth-child(even) td { background: #f9fbfc; }
    .text-right { text-align: right; }
    .fw { font-weight: 700; }
    .green { color: #10B981; }
    .red   { color: #EF4444; }

    h2 { font-size: 13px; font-weight: 700; color: #0F1E30; margin: 24px 0 8px; border-left: 3px solid #3AA0D8; padding-left: 8px; }

    .footer { margin-top: 30px; font-size: 9px; color: #A0AFC0; text-align: center; border-top: 1px solid #e8edf2; padding-top: 12px; }
</style>
</head>
<body>

<div class="accent-bar"></div>

<h1>{{ $tenant->name ?? 'Ma Boutique' }}</h1>
<div class="subtitle">Rapport Financier</div>
<div class="period">Période : {{ $data['period']['from'] }} → {{ $data['period']['to'] }}</div>

<hr class="divider">

{{-- KPI Cards --}}
@php
    $currency = $tenant->settings['currency'] ?? 'FCFA';
    $fmt = fn($v) => number_format((float)$v, 0, ',', ' ') . ' ' . $currency;
@endphp
<div class="kpi-grid">
    <div class="kpi-cell">
        <div class="kpi"><div class="kpi-label">Total Entrées</div><div class="kpi-value kpi-in">{{ $fmt($data['money_in']) }}</div></div>
    </div>
    <div class="kpi-cell">
        <div class="kpi"><div class="kpi-label">Total Sorties</div><div class="kpi-value kpi-out">{{ $fmt($data['total_out']) }}</div></div>
    </div>
    <div class="kpi-cell">
        <div class="kpi"><div class="kpi-label">Solde Net</div><div class="kpi-value kpi-net">{{ $fmt($data['net']) }}</div></div>
    </div>
    <div class="kpi-cell">
        <div class="kpi"><div class="kpi-label">Créances clients</div><div class="kpi-value kpi-debt">{{ $fmt($data['outstanding_debt']) }}</div></div>
    </div>
</div>

{{-- Détail des entrées --}}
<h2>Détail des entrées</h2>
<table>
    <thead><tr><th>Source</th><th class="text-right">Montant</th></tr></thead>
    <tbody>
        <tr><td>Ventes encaissées</td><td class="text-right">{{ $fmt($data['sales_paid']) }}</td></tr>
        <tr><td>Remboursements clients</td><td class="text-right">{{ $fmt($data['repayments']) }}</td></tr>
        <tr><td>Dépôts d'avance</td><td class="text-right">{{ $fmt($data['deposits']) }}</td></tr>
        @if($data['manual_income'] > 0)
        <tr><td>Autres recettes manuelles</td><td class="text-right">{{ $fmt($data['manual_income']) }}</td></tr>
        @endif
        <tr><td class="fw">TOTAL</td><td class="text-right fw green">{{ $fmt($data['money_in']) }}</td></tr>
    </tbody>
</table>

{{-- Détail des sorties --}}
<h2>Détail des sorties</h2>
<table>
    <thead><tr><th>Source</th><th class="text-right">Montant</th></tr></thead>
    <tbody>
        <tr><td>Dépenses</td><td class="text-right">{{ $fmt($data['expenses']) }}</td></tr>
        @if($data['manual_outflow'] > 0)
        <tr><td>Retraits patron</td><td class="text-right">{{ $fmt($data['manual_outflow']) }}</td></tr>
        @endif
        <tr><td class="fw">TOTAL</td><td class="text-right fw red">{{ $fmt($data['total_out']) }}</td></tr>
    </tbody>
</table>

{{-- Dépenses par catégorie --}}
@if(count($data['by_category']) > 0)
<h2>Dépenses par catégorie</h2>
<table>
    <thead><tr><th>Catégorie</th><th class="text-right">Montant</th></tr></thead>
    <tbody>
        @foreach($data['by_category'] as $cat)
        <tr>
            <td>{{ $cat['category'] }}</td>
            <td class="text-right">{{ $fmt($cat['total']) }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

{{-- Mouvements manuels --}}
@if(!empty($data['cash_movements']))
<h2>Mouvements de trésorerie manuels</h2>
<table>
    <thead><tr><th>Date</th><th>Type</th><th>Libellé</th><th class="text-right">Montant</th></tr></thead>
    <tbody>
        @foreach($data['cash_movements'] as $mv)
        <tr>
            <td>{{ $mv['movement_date'] }}</td>
            <td>{{ $mv['type_label'] }}</td>
            <td>{{ $mv['label'] }}</td>
            <td class="text-right">{{ $fmt($mv['amount']) }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

<div class="footer">
    Document généré le {{ now()->translatedFormat('d F Y à H:i') }} • Qiwam ERP
</div>

</body>
</html>
