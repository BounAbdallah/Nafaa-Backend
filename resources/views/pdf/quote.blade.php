<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Devis {{ $quote->reference }}</title>
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
            background: linear-gradient(90deg, #3AA0D8 0%, #d9a518 100%);
        }
        .header { margin-bottom: 30px; position: relative; }
        .logo-section { float: left; width: 60%; }
        .logo-text { 
            font-size: 26px; 
            font-weight: 900; 
            color: #0F1E30; 
            letter-spacing: -1px;
            text-transform: uppercase;
        }
        .logo-dot { color: #d9a518; }
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
        
        .billing-section { margin-top: 20px; margin-bottom: 30px; }
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

        .document-title {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 10px;
            color: #0F1E30;
        }

        /* TipTap Content Styles */
        .tiptap-content {
            margin-bottom: 30px;
        }
        .tiptap-content p { margin: 0 0 10px 0; }
        .tiptap-content h1 { font-size: 18px; margin: 15px 0 10px; color: #0F1E30; }
        .tiptap-content h2 { font-size: 16px; margin: 15px 0 10px; color: #0F1E30; }
        .tiptap-content h3 { font-size: 14px; margin: 15px 0 10px; color: #0F1E30; }
        .tiptap-content ul, .tiptap-content ol { margin: 0 0 10px 20px; padding: 0; }
        .tiptap-content li { margin-bottom: 5px; }
        .tiptap-content table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        .tiptap-content th, .tiptap-content td { border: 1px solid #EEF7FC; padding: 8px; text-align: left; }
        .tiptap-content th { background: #F4F8FB; font-weight: bold; }

        .table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
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

        .footer-section { margin-top: 20px; }
        .terms-info { float: left; width: 55%; }
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
        .badge-status { background: #F4F8FB; color: #3D5268; }

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
            @if($quote->tenant->logo && \Illuminate\Support\Facades\Storage::disk('public')->exists($quote->tenant->logo))
                @php
                    $logoPath = \Illuminate\Support\Facades\Storage::disk('public')->path($quote->tenant->logo);
                    $logoData = base64_encode(file_get_contents($logoPath));
                    $logoSrc = 'data:image/' . pathinfo($logoPath, PATHINFO_EXTENSION) . ';base64,' . $logoData;
                @endphp
                <img src="{{ $logoSrc }}" alt="Logo" style="max-height: 60px; max-width: 200px; margin-bottom: 10px;">
            @else
                <div class="logo-text">QI<span class="logo-dot">W</span>AM</div>
            @endif
            
            <div class="company-details">
                <strong style="color: #0F1E30; font-size: 14px;">{{ $quote->tenant->name }}</strong><br>
                <span style="font-size: 10px;">
                    @if(!empty($quote->tenant->settings['address']))
                        {{ $quote->tenant->settings['address'] }}<br>
                    @endif
                    @if(!empty($quote->tenant->settings['email']))
                        Email: {{ $quote->tenant->settings['email'] }}<br>
                    @endif
                    @if(!empty($quote->tenant->settings['phone']))
                        Téléphone: {{ $quote->tenant->settings['phone'] }}<br>
                    @endif
                    @if(!empty($quote->tenant->settings['ninea']))
                        NINEA: {{ $quote->tenant->settings['ninea'] }}<br>
                    @endif
                    @if(!empty($quote->tenant->settings['rc']))
                        RC: {{ $quote->tenant->settings['rc'] }}
                    @endif
                </span>
            </div>
        </div>
        
        <div class="invoice-meta">
            <h1 class="invoice-title">DEVIS</h1>
            <div class="meta-row">
                <span class="meta-label">Référence :</span> <strong>{{ $quote->reference }}</strong>
            </div>
            <div class="meta-row">
                <span class="meta-label">Date :</span> {{ $quote->issued_at ? $quote->issued_at->format('d/m/Y') : '' }}
            </div>
            @if($quote->expires_at)
            <div class="meta-row">
                <span class="meta-label">Valable jusqu'au :</span> {{ $quote->expires_at->format('d/m/Y') }}
            </div>
            @endif
        </div>
        <div class="clear"></div>
    </div>

    <div class="billing-section">
        <div class="billing-card">
            <div class="section-label">Client :</div>
            <strong style="font-size: 13px; color: #0F1E30;">{{ $quote->customer->name ?? '' }}</strong><br>
            <div style="margin-top: 5px; color: #3D5268;">
                {{ $quote->customer->address ?? '' }}<br>
                {{ $quote->customer->email ?? '' }}<br>
                {{ $quote->customer->phone ?? '' }}
            </div>
        </div>
    </div>

    <div class="document-title">{{ $quote->title }}</div>

    @if(!empty($quote->content))
    <div class="tiptap-content">
        {!! $quote->content !!}
    </div>
    @endif

    <table class="table">
        <thead>
            <tr>
                <th style="width: 55%;">Description</th>
                <th style="text-align: center; width: 10%;">Qté</th>
                <th style="text-align: right; width: 15%;">Prix Unit.</th>
                <th style="text-align: right; width: 20%;">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($quote->items as $item)
            <tr>
                <td style="font-weight: bold;">{{ $item->description }}</td>
                <td style="text-align: center;">{{ $item->quantity }}</td>
                <td style="text-align: right;">{{ number_format($item->unit_price, 0, ',', ' ') }}</td>
                <td style="text-align: right; font-weight: 900; color: #0F1E30;">{{ number_format($item->total, 0, ',', ' ') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer-section">
        <div class="terms-info">
            @if(!empty($quote->notes))
            <div style="margin-bottom: 15px;">
                <div class="section-label">Notes</div>
                <div style="font-size: 10px; color: #3D5268;">{!! nl2br(e($quote->notes)) !!}</div>
            </div>
            @endif

            @if(!empty($quote->terms))
            <div>
                <div class="section-label">Conditions</div>
                <div style="font-size: 10px; color: #3D5268;">{!! nl2br(e($quote->terms)) !!}</div>
            </div>
            @endif
        </div>

        <div class="totals-section">
            <div class="total-row">
                <span style="float: left; color: #7A90A4;">Sous-total</span>
                <span style="float: right; font-weight: bold;">{{ number_format($quote->subtotal, 0, ',', ' ') }} {{ $quote->currency }}</span>
                <div class="clear"></div>
            </div>
            @if($quote->discount > 0)
            <div class="total-row">
                <span style="float: left; color: #E05A2B;">Remise</span>
                <span style="float: right; font-weight: bold; color: #E05A2B;">-{{ number_format($quote->discount, 0, ',', ' ') }} {{ $quote->currency }}</span>
                <div class="clear"></div>
            </div>
            @endif
            @if($quote->tax_amount > 0)
            <div class="total-row">
                <span style="float: left; color: #7A90A4;">TVA ({{ $quote->tax_rate }}%)</span>
                <span style="float: right; font-weight: bold;">{{ number_format($quote->tax_amount, 0, ',', ' ') }} {{ $quote->currency }}</span>
                <div class="clear"></div>
            </div>
            @endif
            <div class="total-row grand-total">
                <span class="label" style="float: left;">TOTAL TTC</span>
                <span style="float: right;">{{ number_format($quote->total, 0, ',', ' ') }} <span style="font-size: 12px; font-weight: normal;">{{ $quote->currency }}</span></span>
                <div class="clear"></div>
            </div>
        </div>
        <div class="clear"></div>
    </div>

    <div class="page-footer">
        Document généré le {{ now()->format('d/m/Y') }} par le système <strong>Qiwam ERP</strong>.
    </div>
</body>
</html>
