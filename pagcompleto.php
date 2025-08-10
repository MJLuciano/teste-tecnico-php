<?php

const DB_DSN  = 'pgsql:host=localhost;port=5432;dbname=loja_db';
const DB_USER = 'loja_user';
const DB_PASS = 'loja_senha';


const ACCESS_TOKEN = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJ1c2VySWQiOjI2ODQsInN0b3JlSWQiOjE5NzksImlhdCI6MTc1Mzk2MjIwOCwiZXhwIjoxNzU2Njg0Nzk5fQ.WlLjEihOHihKoznQkQLvVGIvYjJ4WmpoikSZmuTZ7oU';


const BASE_URI = 'https://apiinterna.ecompleto.com.br';
const ENDPOINT = '/exams/processTransaction';

const ID_GATEWAY_PAGCOMPLETO = 1;
const ID_SIT_AGUARDANDO = 1;
const ID_SIT_PAGAMENTO_OK = 2;
const ID_SIT_CANCELADO = 3;
const ID_FORMA_CARTAO_CREDITO = 3;


function formatExpirationToMMYY(string $yyyy_mm): string {
    [$y, $m] = explode('-', $yyyy_mm);
    return sprintf('%02d%s', (int)$m, substr($y, -2));
}


function postTransaction(array $payload): array {
    $url = BASE_URI . ENDPOINT . '?accessToken=' . urlencode(ACCESS_TOKEN);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        return [
            'Error' => true,
            'Transaction_code' => null,
            'Message' => 'HTTP error: ' . $err,
            '_http' => $http,
            '_raw' => null,
        ];
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return [
            'Error' => true,
            'Transaction_code' => null,
            'Message' => 'Invalid JSON',
            '_http' => $http,
            '_raw' => $raw,
        ];
    }

    $json['_http'] = $http;
    $json['_raw']  = $raw;
    return $json;
}


try { 
    $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $sql = <<<SQL
    SELECT
        p.id AS pedido_id,
        p.valor_total,
        p.valor_frete,
        p.id_cliente,
        p.id_loja,

        c.nome AS cliente_nome,
        c.cpf_cnpj AS cliente_cpf,
        c.email AS cliente_email,
        c.data_nasc AS cliente_nasc,

        pp.id AS pedido_pag_id,
        pp.num_cartao,
        pp.nome_portador,
        pp.codigo_verificacao,
        pp.vencimento
    FROM pedidos p
    JOIN pedidos_pagamentos pp ON pp.id_pedido = p.id
    JOIN lojas_gateway lg ON lg.id_loja = p.id_loja AND lg.id_gateway = :gw
    JOIN clientes c ON c.id = p.id_cliente
    WHERE
        p.id_situacao = :sit
        AND pp.id_formapagto = :fp;
    SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':gw', ID_GATEWAY_PAGCOMPLETO, PDO::PARAM_INT);
    $stmt->bindValue(':sit', ID_SIT_AGUARDANDO, PDO::PARAM_INT);
    $stmt->bindValue(':fp', ID_FORMA_CARTAO_CREDITO, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $amount = (float)$r['valor_total'] + (float)$r['valor_frete'];
        $cardNumber = preg_replace('/\D+/', '', (string)$r['num_cartao']);
        $cpfNumber  = preg_replace('/\D+/', '', (string)$r['cliente_cpf']);
        $expMMYY = formatExpirationToMMYY((string)$r['vencimento']);

        $payload = [
            "external_order_id" => (int)$r['pedido_id'],
            "amount" => round($amount, 2),
            "card_number" => $cardNumber,
            "card_cvv" => (string)$r['codigo_verificacao'],
            "card_expiration_date" => $expMMYY,
            "card_holder_name" => (string)$r['nome_portador'],
            "customer" => [
                "external_id" => (string)$r['id_cliente'],
                "name" => (string)$r['cliente_nome'],
                "type" => "individual",
                "email" => (string)$r['cliente_email'],
                "documents" => [
                    ["type" => "cpf", "number" => $cpfNumber]
                ],
                "birthday" => (string)$r['cliente_nasc'],
            ],
        ];

        $resp = postTransaction($payload);
        $raw  = isset($resp['_raw']) ? $resp['_raw'] : json_encode($resp, JSON_UNESCAPED_UNICODE);

        $approved = (
            isset($resp['Error']) &&
            $resp['Error'] === false &&
            isset($resp['Transaction_code']) &&
            $resp['Transaction_code'] === '00'
        );

        $pdo->beginTransaction();
        try {
            $up1 = $pdo->prepare("
                UPDATE pedidos_pagamentos
                   SET retorno_intermediador = :ret,
                       data_processamento = NOW()
                 WHERE id = :id
            ");
            
            $up1->execute([
                ':ret' => $raw,
                ':id' => $r['pedido_pag_id'],
            ]);

            $nova_situacao = $approved ? ID_SIT_PAGAMENTO_OK : ID_SIT_CANCELADO;
            $up2 = $pdo->prepare("UPDATE pedidos SET id_situacao = :sit WHERE id = :id");
            $up2->execute([
                ':sit' => $nova_situacao,
                ':id' => $r['pedido_id'],
            ]);

            $pdo->commit();
            echo sprintf(
                "[OK] Pedido %d -> %s\n",
                $r['pedido_id'],
                $approved ? 'APROVADO' : 'CANCELADO'
            );
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "[ERR] Pedido {$r['pedido_id']}: " . $e->getMessage() . "\n";
        }
    }

} catch (Exception $e) {
    echo "FATAL: " . $e->getMessage() . PHP_EOL;
}
