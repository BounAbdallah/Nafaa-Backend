<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Facture {{ $order->reference }}</title>
    <style>
        @page { margin: 0; }
        body { 
            font-family: 'Helvetica', 'Arial', sans-serif; 
            color: #0F1E30; 
            font-size: 11px; 
            line-height: 1.5;
            margin: 0;
            padding: 40px;
            background: #fff;
        }
        .accent-bar {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #3AA0D8 0%, #7EC3E8 60%, #C8E5F5 100%);
        }
        .header { margin-bottom: 40px; position: relative; }
        .logo-section { float: left; width: 60%; }
        .logo-text { 
            font-size: 26px; 
            font-weight: 900; 
            color: #0F1E30; 
            letter-spacing: -1px;
            text-transform: uppercase;
        }
        .logo-dot { color: #E8A020; }
        .logo-sub {
            font-size: 9px;
            letter-spacing: 2px;
            color: #7A90A4;
            text-transform: uppercase;
            margin-top: -5px;
        }
        .company-details { margin-top: 15px; color: #3D5268; }
        
        .invoice-meta { float: right; width: 35%; text-align: right; }
        .invoice-title { 
            font-size: 32px; 
            font-weight: 900; 
            color: #3AA0D8; 
            margin: 0;
            letter-spacing: -1px;
        }
        .meta-row { margin-top: 5px; font-size: 10px; }
        .meta-label { color: #7A90A4; text-transform: uppercase; font-weight: bold; }
        
        .clear { clear: both; }
        
        .billing-section { margin-top: 30px; margin-bottom: 40px; }
        .billing-card { 
            background: #F4F8FB; 
            padding: 20px; 
            border-radius: 10px; 
            border-left: 3px solid #3AA0D8;
        }
        .section-label { 
            font-size: 9px; 
            font-weight: bold; 
            color: #7A90A4; 
            text-transform: uppercase; 
            margin-bottom: 8px;
            letter-spacing: 1px;
        }

        .table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .table th { 
            background: #0F1E30; 
            color: #fff; 
            padding: 12px 10px; 
            text-align: left; 
            text-transform: uppercase; 
            font-size: 9px;
            letter-spacing: 1px;
        }
        .table td { padding: 12px 10px; border-bottom: 1px solid #EEF7FC; }
        .table tr:nth-child(even) { background: #FAFCFE; }

        .footer-section { margin-top: 40px; }
        .payment-info { float: left; width: 55%; }
        .totals-section { float: right; width: 40%; }
        
        .total-row { padding: 8px 0; border-bottom: 1px solid #EEF7FC; }
        .total-row.grand-total { 
            border-bottom: none; 
            font-size: 18px; 
            font-weight: 900; 
            color: #0F1E30; 
            margin-top: 10px;
            background: #EEF7FC;
            padding: 15px 10px;
            border-radius: 6px;
        }
        .total-row.grand-total .label { color: #3AA0D8; }
        
        .badge { 
            display: inline-block; 
            padding: 4px 10px; 
            border-radius: 99px; 
            font-size: 9px; 
            font-weight: bold; 
            text-transform: uppercase;
        }
        .badge-paid { background: #E3F5EC; color: #1A7A45; }

        .page-footer { 
            position: absolute;
            bottom: 40px;
            left: 40px;
            right: 40px;
            text-align: center; 
            font-size: 9px; 
            color: #7A90A4; 
            border-top: 1px solid #EEF7FC; 
            padding-top: 20px; 
        }
    </style>
</head>
<body>
    <div class="accent-bar"></div>

    <div class="header">
        <div class="logo-section">
            <div class="logo-text">QI<span class="logo-dot">W</span>AM</div>
            <div class="logo-sub">ERP SAAS SYSTEM</div>
            
            <div class="company-details">
                <strong style="color: #0F1E30; font-size: 14px;">{{ $order->tenant->name }}</strong><br>
                <span style="font-size: 10px;">
                    Email: {{ $order->tenant->email ?? 'N/A' }}<br>
                    Téléphone: {{ $order->tenant->phone ?? 'N/A' }}
                </span>
            </div>
        </div>
        
        <div class="invoice-meta">
            <h1 class="invoice-title">FACTURE</h1>
            <div class="meta-row">
                <span class="meta-label">Référence :</span> <strong>{{ $order->reference }}</strong>
            </div>
            <div class="meta-row">
                <span class="meta-label">Date :</span> {{ $order->created_at->format('d/m/Y H:i') }}
            </div>
            <div class="meta-row" style="margin-top: 10px;">
                <span class="badge badge-paid">Document de Vente — PAYÉ</span>
            </div>
        </div>
        <div class="clear"></div>
    </div>

    <div class="billing-section">
        <div class="billing-card">
            <div class="section-label">Facturé à :</div>
            <strong style="font-size: 13px; color: #0F1E30;">{{ $order->customer->name ?? 'Client de passage' }}</strong><br>
            <div style="margin-top: 5px; color: #3D5268;">
                {{ $order->customer->email ?? '' }}<br>
                {{ $order->customer->phone ?? '' }}
            </div>
        </div>
    </div>

    <table class="table">
        <thead>
            <tr>
                <th style="width: 55%;">Description de l'article</th>
                <th style="text-align: center; width: 10%;">Qté</th>
                <th style="text-align: right; width: 15%;">Prix Unit.</th>
                <th style="text-align: right; width: 20%;">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($order->items as $item)
            <tr>
                <td style="font-weight: bold;">{{ $item->description }}</td>
                <td style="text-align: center;">{{ $item->quantity }}</td>
                <td style="text-align: right;">{{ number_format($item->unit_price, 0, ',', ' ') }}</td>
                <td style="text-align: right; font-weight: 900; color: #0F1E30;">{{ number_format($item->subtotal, 0, ',', ' ') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer-section">
        <div class="payment-info">
            <div class="section-label">Détails des règlements</div>
            <div style="background: #FAFCFE; border: 1px solid #EEF7FC; padding: 15px; border-radius: 8px;">
                @foreach($order->payments as $payment)
                    <div style="margin-bottom: 5px;">
                        <span style="color: #7A90A4; font-size: 10px;">{{ ucfirst(str_replace('_', ' ', $payment->payment_method)) }} :</span>
                        <strong style="color: #0F1E30;">{{ number_format($payment->amount, 0, ',', ' ') }} FCFA</strong>
                        @if($payment->reference) <span style="font-size: 9px; color: #3AA0D8;">(Réf: {{ $payment->reference }})</span> @endif
                    </div>
                @endforeach
                
                @if($order->change_amount > 0)
                    <div style="margin-top: 10px; padding-top: 10px; border-top: 1px dashed #C8E5F5; color: #1A7A45; font-size: 10px;">
                        <strong>Monnaie rendue : {{ number_format($order->change_amount, 0, ',', ' ') }} FCFA</strong>
                    </div>
                @endif
            </div>
        </div>

        <div class="totals-section">
            <div class="total-row">
                <span style="float: left; color: #7A90A4;">Sous-total</span>
                <span style="float: right; font-weight: bold;">{{ number_format($order->subtotal, 0, ',', ' ') }} FCFA</span>
                <div class="clear"></div>
            </div>
            @if($order->discount_amount > 0)
            <div class="total-row">
                <span style="float: left; color: #E05A2B;">Remise appliquée</span>
                <span style="float: right; font-weight: bold; color: #E05A2B;">-{{ number_format($order->discount_amount, 0, ',', ' ') }} FCFA</span>
                <div class="clear"></div>
            </div>
            @endif
            <div class="total-row grand-total">
                <span class="label" style="float: left;">TOTAL À PAYER</span>
                <span style="float: right;">{{ number_format($order->total_amount, 0, ',', ' ') }} <span style="font-size: 12px; font-weight: normal;">FCFA</span></span>
                <div class="clear"></div>
            </div>
        </div>
        <div class="clear"></div>
    </div>

    <div class="page-footer">
        <strong>Merci de votre confiance !</strong><br>
        Cette facture est générée numériquement par le système <strong>Qiwam ERP</strong>.
    </div>
</body>
</html>
