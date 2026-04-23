<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Facture {{ $order->reference }}</title>
    <style>
        body { font-family: 'Helvetica', 'Arial', sans-serif; color: #333; font-size: 12px; line-height: 1.4; }
        .header { margin-bottom: 30px; }
        .logo { font-size: 24px; font-weight: bold; color: #3AA0D8; }
        .company-info { float: left; width: 50%; }
        .invoice-info { float: right; width: 40%; text-align: right; }
        .clear { clear: both; }
        .client-info { margin-top: 20px; padding: 15px; background: #f9f9f9; border-radius: 5px; }
        .table { width: 100%; border-collapse: collapse; margin-top: 30px; }
        .table th { background: #3AA0D8; color: #fff; padding: 10px; text-align: left; text-transform: uppercase; font-size: 10px; }
        .table td { padding: 10px; border-bottom: 1px solid #eee; }
        .totals { float: right; width: 35%; margin-top: 20px; }
        .totals-row { padding: 5px 0; border-bottom: 1px solid #eee; }
        .totals-row.grand-total { border-bottom: none; font-size: 16px; font-weight: bold; color: #3AA0D8; margin-top: 10px; }
        .footer { margin-top: 50px; text-align: center; font-size: 10px; color: #999; border-top: 1px solid #eee; padding-top: 20px; }
        .badge { display: inline-block; padding: 3px 8px; border-radius: 3px; font-size: 9px; font-weight: bold; text-transform: uppercase; }
        .badge-paid { background: #d4edda; color: #155724; }
        .badge-partial { background: #fff3cd; color: #856404; }
        .payment-methods { margin-top: 20px; font-size: 10px; color: #666; }
    </style>
</head>
<body>
    <div class="header">
        <div class="company-info">
            <div class="logo">NAFAA</div>
            <div style="margin-top: 10px;">
                <strong>{{ $order->tenant->name }}</strong><br>
                Email: {{ $order->tenant->email ?? 'N/A' }}<br>
                Tél: {{ $order->tenant->phone ?? 'N/A' }}
            </div>
        </div>
        <div class="invoice-info">
            <h1 style="margin: 0; font-size: 20px;">FACTURE</h1>
            <div style="margin-top: 10px;">
                Réf: <strong>{{ $order->reference }}</strong><br>
                Date: {{ $order->created_at->format('d/m/Y H:i') }}<br>
                Statut: <span class="badge badge-paid">PAYÉE</span>
            </div>
        </div>
        <div class="clear"></div>
    </div>

    <div class="client-info">
        <div style="font-size: 10px; color: #666; text-transform: uppercase; margin-bottom: 5px;">Facturé à :</div>
        <strong>{{ $order->customer->name ?? 'Client de passage' }}</strong><br>
        {{ $order->customer->email ?? '' }}<br>
        {{ $order->customer->phone ?? '' }}
    </div>

    <table class="table">
        <thead>
            <tr>
                <th style="width: 50%;">Description</th>
                <th style="text-align: center;">Qté</th>
                <th style="text-align: right;">Prix Unit.</th>
                <th style="text-align: right;">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($order->items as $item)
            <tr>
                <td>{{ $item->description }}</td>
                <td style="text-align: center;">{{ $item->quantity }}</td>
                <td style="text-align: right;">{{ number_format($item->unit_price, 0, ',', ' ') }} FCFA</td>
                <td style="text-align: right; font-weight: bold;">{{ number_format($item->subtotal, 0, ',', ' ') }} FCFA</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        <div class="totals-row">
            <span style="float: left;">Sous-total :</span>
            <span style="float: right;">{{ number_format($order->subtotal, 0, ',', ' ') }} FCFA</span>
            <div class="clear"></div>
        </div>
        @if($order->discount_amount > 0)
        <div class="totals-row" style="color: #dc3545;">
            <span style="float: left;">Remise :</span>
            <span style="float: right;">-{{ number_format($order->discount_amount, 0, ',', ' ') }} FCFA</span>
            <div class="clear"></div>
        </div>
        @endif
        <div class="totals-row grand-total">
            <span style="float: left;">TOTAL :</span>
            <span style="float: right;">{{ number_format($order->total_amount, 0, ',', ' ') }} FCFA</span>
            <div class="clear"></div>
        </div>
        <div style="margin-top: 10px; font-size: 10px; text-align: right; color: #666;">
            Montant payé: {{ number_format($order->paid_amount, 0, ',', ' ') }} FCFA<br>
            Rendu monnaie: {{ number_format($order->change_amount, 0, ',', ' ') }} FCFA
        </div>
    </div>
    <div class="clear"></div>

    <div class="payment-methods">
        <strong>Modes de règlement :</strong><br>
        @foreach($order->payments as $payment)
            {{ ucfirst(str_replace('_', ' ', $payment->payment_method)) }}: {{ number_format($payment->amount, 0, ',', ' ') }} FCFA 
            @if($payment->reference) (Réf: {{ $payment->reference }}) @endif<br>
        @endforeach
    </div>

    <div class="footer">
        Merci de votre confiance !<br>
        Cette facture est générée numériquement par le système NAFAA ERP.
    </div>
</body>
</html>
