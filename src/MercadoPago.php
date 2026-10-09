<?php
declare(strict_types=1);

/**
 * Cliente mínimo da API do Mercado Pago (https://www.mercadopago.com.br/developers/pt/reference)
 *  - Pix:          POST /v1/payments (payment_method_id = pix) -> QR Code + copia-e-cola
 *  - Checkout Pro: POST /checkout/preferences -> link (cartão, boleto, Pix, saldo MP)
 *  - Consulta:     GET  /v1/payments/{id}  e  /v1/payments/search?external_reference=
 */
final class MercadoPago
{
    private const API = 'https://api.mercadopago.com';

    public static function request(string $token, string $method, string $path, ?array $body = null): array
    {
        if ($token === '') {
            throw new RuntimeException('Mercado Pago não configurado');
        }
        $headers = ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json'];
        if ($method === 'POST') {
            $headers[] = 'X-Idempotency-Key: ' . bin2hex(random_bytes(16));
        }
        $ch = curl_init(self::API . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POSTFIELDS => $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        if ($raw === false) {
            throw new RuntimeException('Sem conexão com o Mercado Pago: ' . $err);
        }
        $data = json_decode((string) $raw, true);
        if ($code >= 400 || !is_array($data)) {
            $msg = $data['message'] ?? ($data['cause'][0]['description'] ?? "HTTP {$code}");
            if ($code === 401) {
                $msg = 'Access Token do Mercado Pago inválido';
            }
            throw new RuntimeException('Mercado Pago: ' . $msg);
        }
        return $data;
    }

    private static function httpsUrl(string $url): ?string
    {
        return str_starts_with($url, 'https://') && !preg_match('#//(localhost|127\.)#', $url) ? $url : null;
    }

    public static function createPix(string $token, float $amount, string $desc, string $email, string $ref, string $notifyUrl): array
    {
        $body = [
            'transaction_amount' => round($amount, 2),
            'description' => mb_substr($desc, 0, 200),
            'payment_method_id' => 'pix',
            'payer' => ['email' => $email],
            'external_reference' => $ref,
            'date_of_expiration' => date('Y-m-d\TH:i:s.000P', time() + 3600),
        ];
        if ($u = self::httpsUrl($notifyUrl)) {
            $body['notification_url'] = $u;
        }
        $r = self::request($token, 'POST', '/v1/payments', $body);
        $tx = $r['point_of_interaction']['transaction_data'] ?? [];
        return ['id' => (string) $r['id'], 'status' => (string) $r['status'], 'qr_code' => (string) ($tx['qr_code'] ?? ''),
                'qr_base64' => (string) ($tx['qr_code_base64'] ?? ''), 'ticket_url' => (string) ($tx['ticket_url'] ?? '')];
    }

    public static function createPreference(string $token, float $amount, string $title, string $email, string $ref, string $notifyUrl, string $backUrl): array
    {
        $body = [
            'items' => [['title' => mb_substr($title, 0, 120), 'quantity' => 1, 'currency_id' => 'BRL', 'unit_price' => round($amount, 2)]],
            'payer' => ['email' => $email],
            'external_reference' => $ref,
            'expires' => true,
            'expiration_date_to' => date('Y-m-d\TH:i:s.000P', time() + 3 * 86400),
        ];
        if ($u = self::httpsUrl($notifyUrl)) {
            $body['notification_url'] = $u;
        }
        if ($b = self::httpsUrl($backUrl)) {
            $body['back_urls'] = ['success' => $b, 'pending' => $b, 'failure' => $b];
            $body['auto_return'] = 'approved';
        }
        $r = self::request($token, 'POST', '/checkout/preferences', $body);
        return ['id' => (string) $r['id'], 'init_point' => (string) $r['init_point']];
    }

    public static function getPayment(string $token, string $id): array
    {
        if (!ctype_digit($id)) {
            throw new InvalidArgumentException('ID de pagamento inválido');
        }
        return self::request($token, 'GET', '/v1/payments/' . $id);
    }

    /** Pagamento mais relevante de uma referência (aprovado tem prioridade) */
    public static function findByReference(string $token, string $ref): ?array
    {
        $r = self::request($token, 'GET', '/v1/payments/search?' . http_build_query([
            'external_reference' => $ref, 'sort' => 'date_created', 'criteria' => 'desc', 'limit' => 10,
        ]));
        $results = $r['results'] ?? [];
        foreach ($results as $p) {
            if (($p['status'] ?? '') === 'approved') {
                return $p;
            }
        }
        return $results[0] ?? null;
    }

    public static function whoami(string $token): array
    {
        $r = self::request($token, 'GET', '/users/me');
        return ['id' => $r['id'] ?? null, 'nickname' => $r['nickname'] ?? '', 'email' => $r['email'] ?? '', 'site' => $r['site_id'] ?? ''];
    }
}
