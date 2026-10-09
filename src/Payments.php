<?php
declare(strict_types=1);

/**
 * Cobranças:
 *  - renew:   cliente/revenda paga um plano -> vencimento estendido automaticamente.
 *             Quem recebe é a revenda mais próxima que tenha Mercado Pago próprio e créditos
 *             (ela "gasta" os créditos do plano ao ser paga); senão, o administrador.
 *  - credits: revenda compra pacote de créditos do administrador.
 * A confirmação chega pelo webhook e, de reserva, por consulta ativa (polling) — nunca dependemos só de um.
 */
final class Payments
{
    public static function tokenFor(int $receiverId): string
    {
        if ($receiverId === 0) {
            return Settings::get('mp_access_token');
        }
        $acc = Account::find($receiverId);
        return $acc ? (string) (Account::settings($acc)['mp']['token'] ?? '') : '';
    }

    /** @return array{0:?array,1:string} [conta recebedora ou null=admin, token] */
    public static function receiverFor(array $acc, ?array $plan = null): array
    {
        if (Settings::get('reseller_mp') === '1') {
            foreach (Account::ancestors($acc) as $anc) {
                if (!Account::isReseller($anc) || Account::expired($anc) || $anc['status'] !== 'active') {
                    continue;
                }
                $token = (string) (Account::settings($anc)['mp']['token'] ?? '');
                if ($token === '') {
                    continue;
                }
                if ($plan && (int) $anc['credits'] < (int) $plan['credits']) {
                    continue; // sem créditos: sobe para o próximo
                }
                return [$anc, $token];
            }
        }
        return [null, Settings::get('mp_access_token')];
    }

    public static function priceFor(?array $receiver, array $plan): float
    {
        if ($receiver) {
            $p = (float) (Account::settings($receiver)['prices'][(string) $plan['id']] ?? 0);
            if ($p > 0) {
                return round($p, 2);
            }
        }
        return round((float) $plan['price'], 2);
    }

    /** Planos com o preço que ESTE cliente vai pagar */
    public static function plansFor(array $acc): array
    {
        $out = [];
        $disc = Referral::discountFor($acc);
        foreach (Plans::all(true) as $p) {
            [$recv] = self::receiverFor($acc, $p);
            $full = self::priceFor($recv, $p);
            $out[] = Plans::publicRow($p, round($full * (100 - $disc) / 100, 2)) + ['full_price' => $full, 'discount_pct' => $disc];
        }
        return $out;
    }

    public static function create(array $acc, array $d): array
    {
        $kind = (string) ($d['kind'] ?? 'renew');
        $method = ($d['method'] ?? 'pix') === 'checkout' ? 'checkout' : 'pix';
        $email = trim((string) ($d['email'] ?? '')) ?: (string) $acc['email'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Informe um e-mail válido para o pagamento');
        }
        if (!$acc['email']) {
            Db::exec('UPDATE accounts SET email = ? WHERE id = ?', [$email, $acc['id']]);
        }
        $tkey = 'pay:' . $acc['id'];
        if (Throttle::count($tkey, 3600) >= 10) {
            throw new DomainException('Muitas cobranças geradas. Tente novamente mais tarde.');
        }

        if ($kind === 'credits') {
            if (!Account::isReseller($acc)) {
                throw new DomainException('Apenas revendas compram créditos');
            }
            $pkg = Settings::creditPackages()[(int) ($d['package'] ?? -1)] ?? null;
            if (!$pkg) {
                throw new InvalidArgumentException('Pacote inválido');
            }
            $receiver = null;
            $token = Settings::get('mp_access_token');
            $amount = $pkg['price'];
            $qty = $pkg['qty'];
            $planId = null;
            $title = "{$qty} créditos — " . Settings::get('brand_name');
        } else {
            $plan = Plans::find((int) ($d['plan_id'] ?? 0));
            if (!$plan || !$plan['active']) {
                throw new InvalidArgumentException('Plano inválido');
            }
            [$receiver, $token] = self::receiverFor($acc, $plan);
            $discount = Referral::discountFor($acc);
            $amount = round(self::priceFor($receiver, $plan) * (100 - $discount) / 100, 2);
            $qty = 0;
            if ($discount >= 100 || $amount <= 0) {
                // 100% de desconto (indicações): renova na hora, sem Mercado Pago
                $now = time();
                $id = Db::insert('payments', ['account_id' => $acc['id'], 'receiver_id' => 0, 'kind' => 'renew', 'plan_id' => $plan['id'],
                    'qty' => 0, 'amount' => 0, 'method' => 'desconto', 'status' => 'pending', 'discount_pct' => $discount, 'created_at' => $now, 'updated_at' => $now]);
                Db::tx(function () use ($id) {
                    Db::exec("UPDATE payments SET status = 'approved', approved_at = ? WHERE id = ?", [time(), $id]);
                    self::apply(Db::one('SELECT * FROM payments WHERE id = ?', [$id]));
                });
                return self::publicRow(Db::one('SELECT * FROM payments WHERE id = ?', [$id]));
            }
            $planId = (int) $plan['id'];
            $title = 'Plano ' . $plan['name'] . ' — ' . Account::brand($acc)['name'];
        }
        if ($token === '') {
            throw new DomainException('Pagamento online ainda não configurado. Fale com o suporte.');
        }
        if ($amount < 1) {
            throw new DomainException('Valor inválido para cobrança (mínimo R$ 1,00)');
        }
        $discount = $discount ?? 0;
        Throttle::hit($tkey);

        $now = time();
        $id = Db::insert('payments', [
            'account_id' => $acc['id'], 'receiver_id' => $receiver ? (int) $receiver['id'] : 0, 'kind' => $kind,
            'plan_id' => $planId, 'qty' => $qty, 'amount' => $amount, 'method' => $method, 'discount_pct' => $discount,
            'status' => 'pending', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $ref = 'SN-' . $id;
        $notify = Settings::baseUrl() . 'api.php?action=mp_webhook&r=' . ($receiver ? (int) $receiver['id'] : 0);
        try {
            if ($method === 'pix') {
                $r = MercadoPago::createPix($token, $amount, $title, $email, $ref, $notify);
                Db::exec('UPDATE payments SET mp_id = ?, mp_status = ?, qr_code = ?, qr_base64 = ?, init_point = ? WHERE id = ?',
                    [$r['id'], $r['status'], $r['qr_code'], $r['qr_base64'], $r['ticket_url'], $id]);
            } else {
                $r = MercadoPago::createPreference($token, $amount, $title, $email, $ref, $notify, Settings::baseUrl() . '#/account?pay=' . $id);
                Db::exec('UPDATE payments SET init_point = ? WHERE id = ?', [$r['init_point'], $id]);
            }
        } catch (Throwable $e) {
            Db::exec("UPDATE payments SET status = 'failed', note = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 300), $id]);
            throw $e;
        }
        Audit::log((int) $acc['id'], 'payment.create', $id, "{$kind} R$ " . number_format($amount, 2, ',', '.') . " via {$method}");
        return self::publicRow(Db::one('SELECT * FROM payments WHERE id = ?', [$id]));
    }

    /** Consulta o Mercado Pago (no máx. a cada 5 s por cobrança) e processa */
    public static function refresh(array $pay, bool $force = false): array
    {
        if ($pay['status'] !== 'pending' || (!$force && time() - (int) $pay['checked_at'] < 5)) {
            return $pay;
        }
        Db::exec('UPDATE payments SET checked_at = ? WHERE id = ?', [time(), $pay['id']]);
        $token = self::tokenFor((int) $pay['receiver_id']);
        try {
            $mp = $pay['mp_id'] !== ''
                ? MercadoPago::getPayment($token, (string) $pay['mp_id'])
                : MercadoPago::findByReference($token, 'SN-' . $pay['id']);
            if ($mp) {
                self::process((int) $pay['id'], $mp);
            } elseif ((int) $pay['created_at'] < time() - 3 * 86400) {
                Db::exec("UPDATE payments SET status = 'expired', updated_at = ? WHERE id = ? AND status = 'pending'", [time(), $pay['id']]);
            }
        } catch (Throwable $e) {
            // falha temporária de rede: tenta de novo na próxima consulta
        }
        return Db::one('SELECT * FROM payments WHERE id = ?', [$pay['id']]);
    }

    /** Aplica o resultado de um pagamento do MP (idempotente) */
    public static function process(int $payId, array $mp): void
    {
        if (($mp['external_reference'] ?? '') !== 'SN-' . $payId) {
            return;
        }
        $status = (string) ($mp['status'] ?? '');
        Db::exec('UPDATE payments SET mp_status = ?, mp_id = ?, updated_at = ? WHERE id = ?', [$status, (string) ($mp['id'] ?? ''), time(), $payId]);
        $pay = Db::one('SELECT * FROM payments WHERE id = ?', [$payId]);
        if (!$pay) {
            return;
        }
        if ($status === 'approved') {
            if ((float) ($mp['transaction_amount'] ?? 0) + 0.01 < (float) $pay['amount']) {
                Db::exec("UPDATE payments SET note = 'Valor pago menor que o cobrado' WHERE id = ?", [$payId]);
                return;
            }
            Db::tx(function () use ($pay) {
                // só um processo consegue mudar pending -> approved
                if (Db::exec("UPDATE payments SET status = 'approved', approved_at = ? WHERE id = ? AND status = 'pending'", [time(), $pay['id']]) !== 1) {
                    return;
                }
                self::apply($pay);
            });
        } elseif (in_array($status, ['rejected', 'cancelled', 'refunded', 'charged_back'], true) && $pay['method'] === 'pix') {
            Db::exec("UPDATE payments SET status = 'failed' WHERE id = ? AND status = 'pending'", [$payId]);
        }
    }

    private static function apply(array $pay): void
    {
        $acc = Account::find((int) $pay['account_id']);
        if (!$acc) {
            return;
        }
        if ($pay['kind'] === 'credits') {
            Db::exec('UPDATE accounts SET credits = credits + ? WHERE id = ?', [$pay['qty'], $acc['id']]);
            Db::insert('credit_log', ['from_id' => 0, 'to_id' => $acc['id'], 'amount' => $pay['qty'], 'reason' => 'Compra #' . $pay['id'], 'created_at' => time()]);
            Audit::log((int) $acc['id'], 'payment.approved', (int) $pay['id'], "+{$pay['qty']} créditos");
            return;
        }
        $plan = Plans::find((int) $pay['plan_id']);
        if (!$plan) {
            return;
        }
        Account::extendWithPlan((int) $acc['id'], $plan);
        Referral::onPaid($acc, $pay);
        if ((int) $pay['receiver_id'] > 0 && (int) $plan['credits'] > 0) {
            // a revenda recebeu o dinheiro: consome os créditos dela (pode ficar negativo; aparece no painel)
            Db::exec('UPDATE accounts SET credits = credits - ? WHERE id = ?', [$plan['credits'], $pay['receiver_id']]);
            Db::insert('credit_log', ['from_id' => $pay['receiver_id'], 'to_id' => $acc['id'], 'amount' => $plan['credits'], 'reason' => 'Renovação paga #' . $pay['id'], 'created_at' => time()]);
        }
        Audit::log((int) $acc['id'], 'payment.approved', (int) $pay['id'], "Plano {$plan['name']} renovado (R$ " . number_format((float) $pay['amount'], 2, ',', '.') . ')');
    }

    /** Webhook do Mercado Pago (formato novo JSON e IPN antigo) */
    public static function webhook(int $receiverId, array $get, array $body): void
    {
        $type = $body['type'] ?? $get['type'] ?? $get['topic'] ?? '';
        $id = (string) ($body['data']['id'] ?? $get['data_id'] ?? ($type === 'payment' ? ($get['id'] ?? '') : ''));
        if ($type !== 'payment' || !ctype_digit($id)) {
            return;
        }
        $token = self::tokenFor($receiverId);
        if ($token === '') {
            return;
        }
        // nunca confiamos no corpo do webhook: buscamos o pagamento direto na API com nosso token
        $mp = MercadoPago::getPayment($token, $id);
        if (!preg_match('/^SN-(\d+)$/', (string) ($mp['external_reference'] ?? ''), $m)) {
            return;
        }
        $pay = Db::one('SELECT * FROM payments WHERE id = ?', [(int) $m[1]]);
        if ($pay && (int) $pay['receiver_id'] === $receiverId) {
            self::process((int) $pay['id'], $mp);
        }
    }

    /** Reserva para webhooks perdidos (rodado pelo worker/cron) */
    public static function refreshPending(): void
    {
        foreach (Db::all("SELECT * FROM payments WHERE status = 'pending' AND created_at > ? ORDER BY id DESC LIMIT 30", [time() - 4 * 86400]) as $p) {
            self::refresh($p);
        }
    }

    public static function list(array $actor, int $limit = 200): array
    {
        $sql = 'SELECT p.*, a.username, pl.name plan_name FROM payments p LEFT JOIN accounts a ON a.id = p.account_id LEFT JOIN plans pl ON pl.id = p.plan_id';
        if (Account::isAdmin($actor)) {
            $rows = Db::all($sql . ' ORDER BY p.id DESC LIMIT ' . $limit);
        } elseif (Account::isReseller($actor)) {
            $rows = Db::all($sql . ' WHERE p.account_id = ? OR p.receiver_id = ? OR a.path LIKE ? ORDER BY p.id DESC LIMIT ' . $limit,
                [$actor['id'], $actor['id'], $actor['path'] . $actor['id'] . '/%']);
        } else {
            $rows = Db::all($sql . ' WHERE p.account_id = ? ORDER BY p.id DESC LIMIT 50', [$actor['id']]);
        }
        return array_map(fn($r) => self::publicRow($r) + ['username' => $r['username'], 'plan_name' => $r['plan_name']], $rows);
    }

    public static function publicRow(array $p): array
    {
        return [
            'id' => (int) $p['id'], 'account_id' => (int) $p['account_id'], 'receiver_id' => (int) $p['receiver_id'],
            'kind' => $p['kind'], 'plan_id' => (int) $p['plan_id'], 'qty' => (int) $p['qty'], 'amount' => (float) $p['amount'],
            'method' => $p['method'], 'status' => $p['status'], 'mp_status' => $p['mp_status'], 'discount_pct' => (int) ($p['discount_pct'] ?? 0),
            'qr_code' => $p['status'] === 'pending' ? $p['qr_code'] : '', 'qr_base64' => $p['status'] === 'pending' ? $p['qr_base64'] : '',
            'init_point' => $p['status'] === 'pending' ? $p['init_point'] : '', 'note' => $p['note'],
            'created_at' => (int) $p['created_at'], 'approved_at' => (int) $p['approved_at'],
        ];
    }
}
