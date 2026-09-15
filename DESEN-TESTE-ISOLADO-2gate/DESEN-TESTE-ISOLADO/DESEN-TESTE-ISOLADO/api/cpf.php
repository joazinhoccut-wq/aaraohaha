<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$action = $_GET['action'] ?? 'consultar';

if ($action === 'getcpf') {
    // ===== Conteúdo original de getCpf.php =====

    if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Método não permitido']);
        exit;
    }

    $cpf = preg_replace('/\D/', '', $_GET['cpf'] ?? $_POST['cpf'] ?? '');
    if ($cpf === '' || strlen($cpf) !== 11) {
        echo json_encode(['success' => false, 'message' => 'CPF inválido. Deve conter 11 dígitos.']);
        exit;
    }

    if (!function_exists('curl_init')) {
        echo json_encode(['success' => false, 'message' => 'Extensão cURL não disponível no servidor']);
        exit;
    }

    function cpf_normaliza_sexo(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        if (preg_match('/(masculino|feminino)/i', $raw, $m)) {
            return ucfirst(strtolower($m[1]));
        }
        $s = strtoupper($raw);
        if (in_array($s, ['M', 'MASCULINO', 'MALE'], true)) {
            return 'Masculino';
        }
        if (in_array($s, ['F', 'FEMININO', 'FEMALE'], true)) {
            return 'Feminino';
        }
        return $raw;
    }

    function cpf_fetch(string $url, array $extraHeaders = []): array
    {
        $headers = array_merge(
            [
                'Accept: application/json',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
            ],
            $extraHeaders
        );

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_errno($ch) ? curl_error($ch) : '';
        curl_close($ch);

        return [$body, $code, $err];
    }

    $magmaHeaders = [];

    $magmaTokens = [
        ''
    ];

    $networkFail = 0;

    foreach ($magmaTokens as $i => $token) {
        [$body, $httpCode, $curlErr] = cpf_fetch(
            'https://base2.sistemafullativo.online:80/api/cad?CPF=' . urlencode($cpf),
            $magmaHeaders
        );

        if ($curlErr !== '') {
            $networkFail++;
            continue;
        }

        $j = json_decode((string) $body, true);
        if ($httpCode !== 200 || !is_array($j) || empty($j['nome'])) {
            continue;
        }

        $nasc = trim((string) ($j['dataNascimento'] ?? ''));

        echo json_encode([
            'success'    => true,
            'nome'       => trim((string) $j['nome']),
            'cpf'        => preg_replace('/\D/', '', (string) ($j['cpf'] ?? $cpf)) ?: $cpf,
            'nascimento' => $nasc,
            'mae'        => trim((string) ($j['nomeMae'] ?? '')) ?: null,
            'sexo'       => cpf_normaliza_sexo(trim((string) ($j['sexo'] ?? ''))) ?: null,
            'data'       => $j,
            '_provider'  => 'base2',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $message = $networkFail === count($magmaTokens)
        ? 'Serviço de consulta temporariamente indisponível. Tente novamente em instantes.'
        : 'CPF não encontrado nas bases consultadas.';

    echo json_encode([
        'success' => false,
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE);
    exit;

} else {
    // ===== Conteúdo original de consultar-cpf.php (padrão) =====

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(array('ok' => false, 'error' => 'method_not_allowed'));
        exit;
    }

    $cpf = preg_replace('/\D/', '', isset($_GET['cpf']) ? $_GET['cpf'] : '');
    if (strlen($cpf) !== 11) {
        http_response_code(400);
        echo json_encode(array('ok' => false, 'error' => 'cpf_invalido'));
        exit;
    }

    $token = 'd9ad2b68-3f28-44f8-9962-c1c476ff44e0';
    $url = 'https://api.amnesiatecnologia.lat/?token=' . urlencode($token) . '&cpf=' . urlencode($cpf);

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => array('Accept: application/json'),
    ));
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno || $raw === false) {
        http_response_code(502);
        echo json_encode(array('ok' => false, 'error' => 'api_unreachable'));
        exit;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        http_response_code(502);
        echo json_encode(array('ok' => false, 'error' => 'api_invalid_json', 'http' => $http));
        exit;
    }

    $dados = null;
    if (isset($data['DADOS']) && is_array($data['DADOS'])) {
        $dados = $data['DADOS'];
    } elseif (isset($data['dados']) && is_array($data['dados'])) {
        $dados = $data['dados'];
    } else {
        $dados = $data;
    }

    if (!is_array($dados)) {
        http_response_code(404);
        echo json_encode(array('ok' => false, 'error' => 'cpf_nao_encontrado'));
        exit;
    }

    function cpf_pick($src, $keys) {
        foreach ($keys as $k) {
            if (isset($src[$k]) && $src[$k] !== '' && $src[$k] !== null) {
                return $src[$k];
            }
        }
        return '';
    }

    $nome = cpf_pick($dados, array('nome', 'NOME', 'name', 'Nome'));
    $cpfResp = preg_replace('/\D/', '', (string) cpf_pick($dados, array('cpf', 'CPF', 'documento', 'DOCUMENTO')));
    if ($cpfResp === '') { $cpfResp = $cpf; }
    $nomeMae = cpf_pick($dados, array('nome_mae', 'NOME_MAE', 'mae', 'nomeMae', 'MAE'));
    $dataNasc = cpf_pick($dados, array('data_nascimento', 'DATA_NASCIMENTO', 'nasc', 'nascimento', 'NASC', 'dt_nascimento'));
    $sexo = cpf_pick($dados, array('sexo', 'SEXO', 'sex', 'gender'));

    if ($nome === '') {
        http_response_code(404);
        echo json_encode(array('ok' => false, 'error' => 'cpf_nao_encontrado', 'raw_keys' => array_keys($dados)));
        exit;
    }

    echo json_encode(array(
        'ok' => true,
        'cpf' => $cpfResp,
        'nome' => $nome,
        'nome_mae' => $nomeMae,
        'data_nascimento' => $dataNasc,
        'sexo' => $sexo,
        'DADOS' => array(
            'cpf' => $cpfResp,
            'nome' => $nome,
            'nome_mae' => $nomeMae,
            'data_nascimento' => $dataNasc,
            'sexo' => $sexo,
        ),
    ), JSON_UNESCAPED_UNICODE);
}
