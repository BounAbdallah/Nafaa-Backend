<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PrintController extends Controller
{
    /**
     * Imprimer un ticket de caisse sur l'imprimante WiFi du tenant.
     * L'imprimante doit être accessible en TCP sur le réseau local.
     */
    public function receipt(Request $request, int $orderId): JsonResponse
    {
        $user  = Auth::user();
        $order = Order::where('tenant_id', $user->tenant_id)
                      ->with(['items', 'payments', 'customer', 'tenant'])
                      ->findOrFail($orderId);

        $printerCfg = $this->getPrinterConfig($order->tenant);

        if (!$printerCfg) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune imprimante configurée. Ajoutez l\'IP de l\'imprimante dans les paramètres.',
            ], 422);
        }

        $escpos = $this->buildReceipt($order, $printerCfg);

        $result = $this->sendToTcpPrinter(
            $printerCfg['ip'],
            (int) ($printerCfg['port'] ?? 9100),
            $escpos,
            (int) ($printerCfg['timeout'] ?? 3)
        );

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => 'Imprimante injoignable : ' . $result['error'],
                'hint'    => 'Vérifiez que l\'imprimante est allumée et sur le même réseau.',
            ], 503);
        }

        return response()->json([
            'success' => true,
            'message' => 'Ticket imprimé avec succès.',
        ]);
    }

    /**
     * Tester la connexion à l'imprimante (ping + impression d'un ticket de test).
     */
    public function test(Request $request): JsonResponse
    {
        $user        = Auth::user();
        $printerCfg  = $this->getPrinterConfig($user->tenant);

        if (!$printerCfg) {
            return response()->json(['success' => false, 'message' => 'Aucune imprimante configurée.'], 422);
        }

        $esc  = chr(0x1B) . chr(0x40);                    // Init
        $esc .= $this->center("TEST IMPRIMANTE\n", $printerCfg['chars'] ?? 32);
        $esc .= $this->center("Qiwam ERP\n", $printerCfg['chars'] ?? 32);
        $esc .= $this->line('-', $printerCfg['chars'] ?? 32);
        $esc .= $this->center(now()->format('d/m/Y H:i') . "\n", $printerCfg['chars'] ?? 32);
        $esc .= $this->line('-', $printerCfg['chars'] ?? 32);
        $esc .= $this->center("Connexion OK ✓\n\n\n", $printerCfg['chars'] ?? 32);
        $esc .= chr(0x1D) . chr(0x56) . chr(0x41) . chr(0x03); // Cut

        $result = $this->sendToTcpPrinter(
            $printerCfg['ip'],
            (int) ($printerCfg['port'] ?? 9100),
            $esc,
            (int) ($printerCfg['timeout'] ?? 3)
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['success']
                ? 'Imprimante connectée — ticket test imprimé.'
                : 'Imprimante injoignable : ' . $result['error'],
        ], $result['success'] ? 200 : 503);
    }

    /* ─────────────────── Private helpers ─────────────────── */

    private function getPrinterConfig($tenant): ?array
    {
        $cfg = $tenant->settings['printer'] ?? null;
        if (empty($cfg['ip']) || empty($cfg['enabled'])) return null;

        return [
            'ip'      => $cfg['ip'],
            'port'    => $cfg['port']    ?? 9100,
            'chars'   => $cfg['paper_width_chars'] ?? ($cfg['width'] == 58 ? 32 : 42),
            'width'   => $cfg['width']   ?? 80,
            'timeout' => $cfg['timeout'] ?? 3,
        ];
    }

    private function buildReceipt(Order $order, array $cfg): string
    {
        $chars  = $cfg['chars'];
        $tenant = $order->tenant;

        $esc = chr(0x1B) . chr(0x40); // ESC @ — Init imprimante

        // ── Logo / Nom commerce ──────────────────────────────
        $esc .= chr(0x1B) . chr(0x61) . chr(1);  // Centre
        $esc .= chr(0x1B) . chr(0x21) . chr(0x30); // Double hauteur + gras
        $esc .= strtoupper($tenant->name) . "\n";
        $esc .= chr(0x1B) . chr(0x21) . chr(0x00); // Normal

        if (!empty($tenant->settings['address'])) {
            $esc .= $tenant->settings['address'] . "\n";
        }
        if (!empty($tenant->settings['phone'])) {
            $esc .= 'Tel: ' . $tenant->settings['phone'] . "\n";
        }
        if (!empty($tenant->settings['ninea'])) {
            $esc .= 'NINEA: ' . $tenant->settings['ninea'] . "\n";
        }

        $esc .= chr(0x1B) . chr(0x61) . chr(0); // Gauche
        $esc .= $this->line('=', $chars);

        // ── Référence & date ────────────────────────────────
        $esc .= 'Ref: ' . $order->reference . "\n";
        $esc .= 'Date: ' . $order->created_at->format('d/m/Y H:i') . "\n";
        if ($order->customer?->name) {
            $esc .= 'Client: ' . $order->customer->name . "\n";
        }
        $esc .= $this->line('-', $chars);

        // ── En-tête colonnes ────────────────────────────────
        $esc .= $this->columns('Article', 'Qté', 'Prix', $chars);
        $esc .= $this->line('-', $chars);

        // ── Articles ────────────────────────────────────────
        foreach ($order->items as $item) {
            $name   = mb_strimwidth($item->description, 0, $chars - 14, '..');
            $qty    = (string) $item->quantity;
            $price  = number_format($item->subtotal, 0, ',', ' ');
            $esc   .= $this->columns($name, $qty, $price, $chars);
        }

        $esc .= $this->line('-', $chars);

        // ── Totaux ──────────────────────────────────────────
        if ($order->discount_amount > 0) {
            $esc .= $this->row2('Remise', '-' . number_format($order->discount_amount, 0, ',', ' ') . ' F', $chars);
        }

        if (($order->vat_rate ?? 0) > 0) {
            $vatLabel = 'TVA (' . $order->vat_rate . '%)';
            $esc .= $this->row2($vatLabel, number_format($order->vat_amount, 0, ',', ' ') . ' F', $chars);
        }

        // Total en gras
        $esc .= chr(0x1B) . chr(0x21) . chr(0x30);
        $totalLabel = (($order->vat_rate ?? 0) > 0) ? 'TOTAL TTC' : 'TOTAL';
        $esc .= $this->row2($totalLabel, number_format($order->total_amount, 0, ',', ' ') . ' FCFA', $chars);
        $esc .= chr(0x1B) . chr(0x21) . chr(0x00);
        $esc .= $this->line('-', $chars);

        // ── Règlements ──────────────────────────────────────
        $PAYMENT_LABELS = [
            'cash'          => 'Especes',
            'wave'          => 'Wave',
            'orange_money'  => 'Orange Money',
            'card'          => 'Carte',
            'mobile_money'  => 'Mobile Money',
            'bank_transfer' => 'Virement',
        ];
        foreach ($order->payments as $p) {
            $label = $PAYMENT_LABELS[$p->payment_method] ?? ucfirst($p->payment_method);
            $esc  .= $this->row2($label, number_format($p->amount, 0, ',', ' ') . ' F', $chars);
        }
        if ($order->change_amount > 0) {
            $esc .= $this->row2('Monnaie rendue', number_format($order->change_amount, 0, ',', ' ') . ' F', $chars);
        }

        $esc .= $this->line('=', $chars);

        // ── Pied de page ────────────────────────────────────
        $esc .= chr(0x1B) . chr(0x61) . chr(1); // Centre
        $esc .= "Merci de votre confiance !\n";
        $esc .= "Powered by Qiwam ERP\n";
        $esc .= "\n\n\n";
        $esc .= chr(0x1B) . chr(0x61) . chr(0); // Gauche

        // ── Coupe papier ────────────────────────────────────
        $esc .= chr(0x1D) . chr(0x56) . chr(0x41) . chr(0x03);

        return $esc;
    }

    /** Envoi ESC/POS sur socket TCP */
    private function sendToTcpPrinter(string $ip, int $port, string $data, int $timeout = 3): array
    {
        $socket = @fsockopen($ip, $port, $errno, $errstr, $timeout);

        if (!$socket) {
            return ['success' => false, 'error' => "$errstr ($errno)"];
        }

        stream_set_timeout($socket, $timeout);
        $written = fwrite($socket, $data);
        fclose($socket);

        if ($written === false) {
            return ['success' => false, 'error' => 'Échec écriture socket'];
        }

        return ['success' => true];
    }

    /* ── Helpers formatage ESC/POS ── */

    private function line(string $char, int $width): string
    {
        return str_repeat($char, $width) . "\n";
    }

    private function center(string $text, int $width): string
    {
        $len = mb_strlen(rtrim($text));
        $pad = max(0, intdiv($width - $len, 2));
        return str_repeat(' ', $pad) . $text;
    }

    /** 3 colonnes : description | qty | prix */
    private function columns(string $left, string $mid, string $right, int $width): string
    {
        $rightLen = mb_strlen($right);
        $midLen   = mb_strlen($mid);
        $leftMax  = $width - $midLen - $rightLen - 3;
        $left     = mb_strimwidth($left, 0, $leftMax, '.');
        $leftPad  = str_pad($left, $leftMax);
        $midPad   = str_pad($mid,  $midLen + 1);
        return $leftPad . ' ' . $midPad . $right . "\n";
    }

    /** 2 colonnes : label | valeur */
    private function row2(string $left, string $right, int $width): string
    {
        $rightLen = mb_strlen($right);
        $leftMax  = $width - $rightLen - 1;
        $leftPad  = str_pad(mb_strimwidth($left, 0, $leftMax), $leftMax);
        return $leftPad . ' ' . $right . "\n";
    }
}
