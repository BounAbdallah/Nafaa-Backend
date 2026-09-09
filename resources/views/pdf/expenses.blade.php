<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Rapport de Dépenses - Qiwam ERP</title>
    <style>
        body { font-family: 'Helvetica', 'Arial', sans-serif; color: #0F1E30; line-height: 1.5; font-size: 11px; margin: 0; padding: 0; }
        .header { border-bottom: 3px solid #3AA0D8; padding: 20px 40px; background: #fff; }
        .logo-container { vertical-align: middle; }
        .company-info { text-align: right; vertical-align: middle; }
        .company-name { font-size: 16px; font-weight: bold; color: #0F1E30; margin-bottom: 2px; }
        .company-sub { font-size: 10px; color: #7A90A4; text-transform: uppercase; letter-spacing: 1px; }
        
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
            background: #0F1E30;
            border-radius: 50%;
            border: 1px solid #3AA0D8;
        }

        .main-content { padding: 30px 40px; }
        .report-title { font-size: 22px; font-weight: bold; color: #0F1E30; margin-bottom: 30px; text-transform: uppercase; letter-spacing: -0.5px; border-left: 5px solid #3AA0D8; padding-left: 15px; }
        
        .meta-period { color: #7A90A4; font-size: 10px; margin-bottom: 20px; font-style: italic; }
        
        .items-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .items-table th { background: #0F1E30; color: #fff; padding: 10px; text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: 1px; }
        .items-table td { border-bottom: 1px solid #F1F5F9; padding: 10px; font-size: 10px; }
        
        .total-box { margin-top: 30px; padding: 20px; background: #FFF5F4; border-radius: 8px; text-align: right; border: 1px solid #FFEBE9; }
        .total-label { font-size: 9px; color: #7A90A4; text-transform: uppercase; font-weight: bold; margin-bottom: 5px; }
        .total-amount { font-size: 20px; font-weight: 800; color: #3AA0D8; }
        
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
                    <div class="company-sub">Gestion des Dépenses</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="main-content">
        <div class="report-title">Rapport des Dépenses</div>
        
        <div class="meta-period">
            Période : 
            @if($startDate && $endDate)
                du {{ date('d/m/Y', strtotime($startDate)) }} au {{ date('d/m/Y', strtotime($endDate)) }}
            @elseif($month)
                {{ date('F Y', strtotime($month)) }}
            @else
                Toutes les dépenses
            @endif
        </div>

        <table class="items-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Catégorie</th>
                    <th>Description</th>
                    <th>Paiement</th>
                    <th style="text-align: right;">Montant</th>
                </tr>
            </thead>
            <tbody>
                @php 
                    $categories = \App\Models\Expense::categories();
                    $payments = \App\Models\Expense::paymentMethods();
                @endphp
                @foreach($expenses as $expense)
                    <tr>
                        <td>{{ $expense->expense_date->format('d/m/Y') }}</td>
                        <td style="font-weight: bold; color: #3AA0D8;">{{ $categories[$expense->category] ?? $expense->category }}</td>
                        <td>{{ $expense->description }}</td>
                        <td style="color: #7A90A4; font-size: 9px;">{{ $payments[$expense->payment_method] ?? $expense->payment_method }}</td>
                        <td style="text-align: right; font-weight: bold;">{{ number_format($expense->amount, 0, ',', ' ') }} FCFA</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="total-box">
            <div class="total-label">Total des Dépenses sur la période</div>
            <div class="total-amount">{{ number_format($total, 0, ',', ' ') }} FCFA</div>
        </div>
    </div>

    <div class="footer">
        Document généré par Qiwam ERP - {{ date('d/m/Y H:i') }} - Page 1/1
    </div>
</body>
</html>
