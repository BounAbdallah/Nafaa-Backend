<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body { font-family: 'Helvetica', 'Arial', sans-serif; color: #0F1E30; line-height: 1.5; font-size: 11px; margin: 0; padding: 0; }
        .header { border-bottom: 3px solid #3AA0D8; padding: 20px 40px; background: #fff; }
        .header-table { width: 100%; }
        .company-name { font-size: 16px; font-weight: bold; color: #0F1E30; margin-bottom: 2px; }
        .company-sub { font-size: 10px; color: #7A90A4; text-transform: uppercase; letter-spacing: 1px; }
        .header-right { text-align: right; font-size: 10px; color: #7A90A4; }

        .qiwam-logo-box { width: 36px; height: 36px; background: #3AA0D8; border-radius: 8px; padding: 5px; }
        .qiwam-grid { width: 100%; height: 100%; border-collapse: separate; border-spacing: 2px; }
        .qiwam-cell { background: #ffffff; border-radius: 2px; width: 12px; height: 12px; }

        .main-content { padding: 30px 40px; }
        .report-title { font-size: 20px; font-weight: bold; color: #0F1E30; margin-bottom: 6px; letter-spacing: -0.5px; border-left: 5px solid #3AA0D8; padding-left: 15px; }
        .meta-period { color: #7A90A4; font-size: 11px; margin-bottom: 28px; padding-left: 20px; }

        .section { margin-bottom: 26px; }
        .section-title { font-size: 12px; font-weight: bold; color: #fff; background: #0F1E30; padding: 8px 14px; border-radius: 6px 6px 0 0; text-transform: uppercase; letter-spacing: 1px; }
        .rows-table { width: 100%; border-collapse: collapse; border: 1px solid #E8EFF5; border-top: none; }
        .rows-table td { padding: 9px 14px; font-size: 11px; border-bottom: 1px solid #F1F5F9; }
        .rows-table tr:last-child td { border-bottom: none; }
        .rows-table tr:nth-child(even) td { background: #F8FBFD; }
        .row-label { color: #3D5268; }
        .row-value { text-align: right; font-weight: bold; color: #0F1E30; }

        .footer { position: fixed; bottom: 25px; left: 40px; right: 40px; font-size: 9px; color: #C4D0DC; text-align: center; border-top: 1px solid #F1F5F9; padding-top: 10px; }
    </style>
</head>
<body>
    <div class="header">
        <table class="header-table">
            <tr>
                <td style="width: 50px; vertical-align: middle;">
                    <div class="qiwam-logo-box">
                        <table class="qiwam-grid">
                            <tr><td class="qiwam-cell"></td><td class="qiwam-cell"></td></tr>
                            <tr><td class="qiwam-cell"></td><td class="qiwam-cell"></td></tr>
                        </table>
                    </div>
                </td>
                <td style="vertical-align: middle;">
                    <div class="company-name">Qiwam ERP</div>
                    <div class="company-sub">Rapport automatique</div>
                </td>
                <td class="header-right" style="vertical-align: middle;">
                    Généré le {{ now()->format('d/m/Y à H:i') }}<br>
                    Destinataire : {{ $recipientName }}
                </td>
            </tr>
        </table>
    </div>

    <div class="main-content">
        <div class="report-title">{{ $title }}</div>
        <div class="meta-period">Période couverte : {{ $periodLabel }}</div>

        @foreach($sections as $section)
            <div class="section">
                <div class="section-title">{{ $section['title'] }}</div>
                <table class="rows-table">
                    @foreach($section['rows'] as $row)
                        <tr>
                            <td class="row-label">{{ $row['label'] }}</td>
                            <td class="row-value">{{ $row['value'] }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endforeach
    </div>

    <div class="footer">
        Qiwam ERP — Rapport généré automatiquement. Modifiez la fréquence de réception depuis votre profil.
    </div>
</body>
</html>
