<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Rapport de Production - {{ $production->reference }}</title>
    <style>
        body { font-family: 'Helvetica', 'Arial', sans-serif; color: #0F1E30; line-height: 1.5; font-size: 11px; margin: 0; padding: 0; }
        .header { border-bottom: 3px solid #3AA0D8; padding: 20px 40px; background: #fff; }
        .logo-container { vertical-align: middle; }
        .company-info { text-align: right; vertical-align: middle; }
        .company-name { font-size: 16px; font-weight: bold; color: #0F1E30; margin-bottom: 2px; }
        .company-sub { font-size: 10px; color: #7A90A4; text-transform: uppercase; letter-spacing: 1px; }
        
        /* Qiwam Logo Style */
        .qiwam-logo-box {
            width: 36px;
            height: 36px;
            background: #3AA0D8;
            border-radius: 8px;
            position: relative;
            padding: 5px;
        }
        .qiwam-grid {
            width: 100%;
            height: 100%;
            border-collapse: separate;
            border-spacing: 2px;
        }
        .qiwam-cell {
            background: #ffffff;
            border-radius: 2px;
            width: 12px;
            height: 12px;
        }
        .qiwam-dot {
            position: absolute;
            bottom: 4px;
            right: 4px;
            width: 6px;
            height: 6px;
            background: #E8A020;
            border-radius: 50%;
            border: 1px solid #3AA0D8;
        }

        .main-content { padding: 30px 40px; }
        .report-title { font-size: 22px; font-weight: bold; color: #0F1E30; margin-bottom: 30px; text-transform: uppercase; letter-spacing: -0.5px; border-left: 5px solid #3AA0D8; padding-left: 15px; }
        
        .section-title { background: #F4F8FB; padding: 8px 12px; font-weight: bold; font-size: 10px; color: #3AA0D8; text-transform: uppercase; letter-spacing: 1.5px; margin-top: 25px; border-radius: 4px; }
        
        .info-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .info-table td { padding: 8px 0; border-bottom: 1px solid #F1F5F9; }
        .info-table .label { font-weight: bold; color: #7A90A4; width: 25%; font-size: 9px; text-transform: uppercase; }
        .info-table .value { font-weight: 600; color: #0F1E30; width: 25%; }
        
        .items-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .items-table th { background: #0F1E30; color: #fff; padding: 10px; text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: 1px; }
        .items-table td { border-bottom: 1px solid #F1F5F9; padding: 10px; font-size: 10px; }
        
        .total-box { margin-top: 30px; padding: 20px; background: #F4F8FB; border-radius: 8px; text-align: right; }
        .total-label { font-size: 9px; color: #7A90A4; text-transform: uppercase; font-weight: bold; margin-bottom: 5px; }
        .total-amount { font-size: 20px; font-weight: 800; color: #3AA0D8; }
        
        .status { padding: 4px 10px; border-radius: 99px; font-weight: bold; font-size: 9px; text-transform: uppercase; }
        .status-completed { background: #E3F5EC; color: #1A7A45; }
        .status-in_progress { background: #EEF7FC; color: #3AA0D8; }
        .status-pending { background: #F1F5F9; color: #3D5268; }
        
        .footer { position: fixed; bottom: 30px; left: 40px; right: 40px; font-size: 9px; color: #C4D0DC; text-align: center; border-top: 1px solid #F1F5F9; padding-top: 10px; }
    </style>
</head>
<body>
    <div class="header">
        <table style="width: 100%;">
            <tr>
                <td class="logo-container">
                    <table style="border-collapse: collapse;">
                        <tr>
                            <td>
                                <div class="qiwam-logo-box">
                                    <table class="qiwam-grid">
                                        <tr>
                                            <td class="qiwam-cell" style="opacity: 1"></td>
                                            <td class="qiwam-cell" style="opacity: 0.7"></td>
                                        </tr>
                                        <tr>
                                            <td class="qiwam-cell" style="opacity: 0.45"></td>
                                            <td class="qiwam-cell" style="opacity: 0.25"></td>
                                        </tr>
                                    </table>
                                    <div class="qiwam-dot"></div>
                                </div>
                            </td>
                            <td style="padding-left: 12px;">
                                <div style="font-weight: 900; color: #0F1E30; font-size: 18px; line-height: 1; text-transform: uppercase; letter-spacing: -0.5px;">Qiwam</div>
                                <div style="font-weight: 500; color: #3AA0D8; font-size: 8px; letter-spacing: 2.5px; text-transform: uppercase; margin-top: 2px;">ERP Solution</div>
                            </td>
                        </tr>
                    </table>
                </td>
                <td class="company-info">
                    <div class="company-name">{{ $tenant->name }}</div>
                    <div class="company-sub">Référence: {{ $production->reference }}</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="main-content">
        <div class="report-title">Rapport de Production</div>

        <div class="section-title">Informations de Fabrication</div>
        <table class="info-table">
            <tr>
                <td class="label">Produit final</td>
                <td class="value">{{ $production->product->name }}</td>
                <td class="label">Statut</td>
                <td class="value"><span class="status status-{{ $production->status }}">{{ $production->status }}</span></td>
            </tr>
            <tr>
                <td class="label">N° de Lot (Batch)</td>
                <td class="value">{{ $production->batch_number }}</td>
                <td class="label">Recette</td>
                <td class="value">{{ $production->bom->name }}</td>
            </tr>
            <tr>
                <td class="label">Date début</td>
                <td class="value">{{ $production->started_at ? $production->started_at->format('d/m/Y H:i') : '—' }}</td>
                <td class="label">Date clôture</td>
                <td class="value">{{ $production->completed_at ? $production->completed_at->format('d/m/Y H:i') : '—' }}</td>
            </tr>
            <tr>
                <td class="label">Responsable</td>
                <td class="value">{{ $production->user->name }}</td>
                <td class="label">Date d'expiration</td>
                <td class="value">{{ $production->expiry_date ? $production->expiry_date->format('d/m/Y') : '—' }}</td>
            </tr>
        </table>

        <div class="section-title">Bilan des Quantités</div>
        <table class="info-table">
            <tr>
                <td class="label">Quantité prévue</td>
                <td class="value">{{ number_format($production->planned_quantity, 2) }} {{ $production->product->unit }}</td>
                <td class="label">Quantité réelle</td>
                <td class="value"><strong>{{ number_format($production->actual_quantity, 2) }} {{ $production->product->unit }}</strong></td>
            </tr>
            <tr>
                <td class="label">Pertes constatées</td>
                <td class="value">{{ number_format($production->waste_quantity, 2) }} {{ $production->product->unit }}</td>
                <td class="label">Efficacité</td>
                <td class="value">{{ $production->planned_quantity > 0 ? round(($production->actual_quantity / $production->planned_quantity) * 100, 2) : 0 }} %</td>
            </tr>
        </table>

        <div class="section-title">Consommation des Matières Premières</div>
        <table class="items-table">
            <thead>
                <tr>
                    <th>Ingrédient</th>
                    <th>Quantité Consommée</th>
                    <th style="text-align: right;">Coût Unitaire</th>
                    <th style="text-align: right;">Sous-total</th>
                </tr>
            </thead>
            <tbody>
                @php 
                    $ratio = $production->planned_quantity / $production->bom->quantity;
                    $bomWaste = 1 + ($production->bom->waste_percentage / 100);
                @endphp
                @foreach($production->bom->items as $item)
                    @php 
                        $consumed = $item->quantity * $ratio * $bomWaste;
                        $cost = $consumed * $item->ingredient->cost_price;
                    @endphp
                    <tr>
                        <td>{{ $item->ingredient->name }}</td>
                        <td>{{ number_format($consumed, 2) }} {{ $item->ingredient->unit }}</td>
                        <td style="text-align: right;">{{ number_format($item->ingredient->cost_price, 0, ',', ' ') }} FCFA</td>
                        <td style="text-align: right;">{{ number_format($cost, 0, ',', ' ') }} FCFA</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="total-box">
            <div class="total-label">Coût total de fabrication (Matières)</div>
            <div class="total-amount">{{ number_format($production->total_cost, 0, ',', ' ') }} FCFA</div>
            <div style="font-size: 10px; color: #7A90A4; margin-top: 8px;">
                Coût unitaire de revient : <strong>{{ $production->actual_quantity > 0 ? number_format($production->total_cost / $production->actual_quantity, 0, ',', ' ') : 0 }} FCFA / {{ $production->product->unit }}</strong>
            </div>
        </div>
    </div>

    <div class="footer">
        Document généré par Qiwam ERP - {{ date('d/m/Y') }} - Page 1/1
    </div>
</body>
</html>
