<?php
declare(strict_types=1);

/**
 * Eleições 2026 — API direta do TSE
 *
 * A cada chamada:
 * - consulta os arquivos oficiais do TSE;
 * - usa curl_multi para buscar os cargos em paralelo;
 * - agrega os resultados;
 * - mantém cache local por 5 segundos;
 * - nunca cria/fabrica resultado.
 *
 * Requer: PHP 8+ com cURL.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

const TSE = 'https://resultados.tse.jus.br/oficial/ele2026';
const FEDERAL = '6257';
const ESTADUAL = '6259';
const CACHE_TTL = 5;

$ufs = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];

$cacheDir = __DIR__ . DIRECTORY_SEPARATOR . 'cache';
$cacheFile = $cacheDir . DIRECTORY_SEPARATOR . 'resultados.json';

if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0775, true);
}

if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < CACHE_TTL) {
    readfile($cacheFile);
    exit;
}

function urlResult(string $eleicao, string $uf, int $cargo): string {
    $u = strtolower($uf);
    return TSE . "/{$eleicao}/dados/{$u}/{$u}-c" . str_pad((string)$cargo, 4, '0', STR_PAD_LEFT)
        . "-e" . str_pad($eleicao, 6, '0', STR_PAD_LEFT) . "-u.json";
}

function num(string|int|float|null $v): float {
    if ($v === null || $v === '') return 0.0;
    $s = trim((string)$v);
    // Campos TSE de percentual normalmente usam ponto decimal.
    if (str_contains($s, ',') && str_contains($s, '.')) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } elseif (str_contains($s, ',')) {
        $s = str_replace(',', '.', $s);
    }
    return is_numeric($s) ? (float)$s : 0.0;
}

function intv(mixed $v): int {
    if ($v === null || $v === '') return 0;
    return (int)preg_replace('/\D+/', '', (string)$v);
}

function parseTse(array $raw, string $uf, string $office): array {
    $cargo = $raw['carg'][0] ?? [];
    $candidates = [];

    foreach (($cargo['agr'] ?? []) as $agr) {
        foreach (($agr['par'] ?? []) as $par) {
            foreach (($par['cand'] ?? []) as $c) {
                $candidates[] = [
                    'name' => $c['nmu'] ?? $c['nm'] ?? 'Candidato',
                    'party' => $par['sg'] ?? '',
                    'number' => $c['n'] ?? '',
                    'votes' => intv($c['vap'] ?? 0),
                    'pct' => num($c['pvap'] ?? 0),
                    'situation' => $c['st'] ?? '',
                    'elected' => $c['e'] ?? '',
                    'seq' => $c['sqcand'] ?? '',
                ];
            }
        }
    }

    usort($candidates, fn($a,$b) => $b['votes'] <=> $a['votes']);

    $v = $raw['v'] ?? [];
    $s = $raw['s'] ?? [];
    $e = $raw['e'] ?? [];

    return [
        'uf' => $uf,
        'office' => $office,
        'validVotes' => intv($v['vv'] ?? $v['vvc'] ?? 0),
        'totalVotes' => intv($v['tv'] ?? 0),
        'blankVotes' => intv($v['vb'] ?? 0),
        'blankPct' => num($v['pvb'] ?? 0),
        'nullVotes' => intv($v['vn'] ?? 0),
        'nullPct' => num($v['ptvn'] ?? $v['pvn'] ?? 0),
        'electorate' => intv($e['te'] ?? 0),
        'attendance' => intv($e['c'] ?? 0),
        'attendancePct' => num($e['pc'] ?? 0),
        'abstention' => intv($e['a'] ?? 0),
        'abstentionPct' => num($e['pa'] ?? 0),
        'sections' => [
            'total' => intv($s['ts'] ?? 0),
            'counted' => intv($s['st'] ?? 0),
            'percent' => num($s['pst'] ?? 0),
        ],
        'generatedAt' => trim(($raw['dg'] ?? '') . ' ' . ($raw['hg'] ?? '')),
        'final' => (($raw['tf'] ?? '') === 's'),
        'candidates' => $candidates,
    ];
}

$requests = [];
$requests[] = ['key'=>'president', 'uf'=>'BR', 'office'=>'president', 'url'=>urlResult(FEDERAL,'BR',1)];

foreach ($ufs as $uf) {
    $requests[] = ['key'=>"president.$uf", 'uf'=>$uf, 'office'=>'president', 'url'=>urlResult(FEDERAL,$uf,1)];
    $requests[] = ['key'=>"governor.$uf", 'uf'=>$uf, 'office'=>'governor', 'url'=>urlResult(ESTADUAL,$uf,3)];
    $requests[] = ['key'=>"senator.$uf", 'uf'=>$uf, 'office'=>'senator', 'url'=>urlResult(ESTADUAL,$uf,5)];
    $requests[] = ['key'=>"deputy.$uf", 'uf'=>$uf, 'office'=>'deputy', 'url'=>urlResult(ESTADUAL,$uf,6)];
    $cargoStateDep = ($uf === 'DF') ? 8 : 7;
    $requests[] = ['key'=>"stateDeputy.$uf", 'uf'=>$uf, 'office'=>'stateDeputy', 'url'=>urlResult(ESTADUAL,$uf,$cargoStateDep)];
}

$mh = curl_multi_init();
$handles = [];

foreach ($requests as $req) {
    $ch = curl_init($req['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: Eleicoes2026Monitor/2.0'
        ],
    ]);
    curl_multi_add_handle($mh, $ch);
    $handles[$req['key']] = [$ch, $req];
}

$running = null;
do {
    $status = curl_multi_exec($mh, $running);
    if ($running) curl_multi_select($mh, 1.0);
} while ($running && $status === CURLM_OK);

$result = [
    'ok' => true,
    'source' => 'TSE',
    'updatedAt' => date(DATE_ATOM),
    'election' => [
        'federal' => FEDERAL,
        'state' => ESTADUAL,
        'cycle' => 'ele2026',
    ],
    'president' => null,
    'presidentByState' => [],
    'governors' => [],
    'senators' => [],
    'deputies' => [],
    'stateDeputies' => [],
    'summary' => [
        'senatorsByParty' => [],
        'deputiesByParty' => [],
        'stateDeputiesByParty' => [],
        'governorsByParty' => [],
    ],
];

foreach ($handles as $key => [$ch, $req]) {
    $body = curl_multi_getcontent($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    if ($http === 200 && $body !== '') {
        $raw = json_decode($body, true);
        if (is_array($raw)) {
            $parsed = parseTse($raw, $req['uf'], $req['office']);
            if ($key === 'president') $result['president'] = $parsed;
            elseif (str_starts_with($key, 'president.')) $result['presidentByState'][$req['uf']] = $parsed;
            elseif (str_starts_with($key, 'governor.')) $result['governors'][$req['uf']] = $parsed;
            elseif (str_starts_with($key, 'senator.')) $result['senators'][$req['uf']] = $parsed;
            elseif (str_starts_with($key, 'deputy.')) $result['deputies'][$req['uf']] = $parsed;
            elseif (str_starts_with($key, 'stateDeputy.')) $result['stateDeputies'][$req['uf']] = $parsed;
            $result['errors'][] = ['key'=>$key,'error'=>'JSON inválido','http'=>$http];
        }
    } else {
        $result['errors'][] = [
            'key'=>$key,
            'http'=>$http,
            'error'=>$error ?: 'TSE não respondeu'
        ];
    }

    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
}
curl_multi_close($mh);

$senParty = [];
foreach ($result['senators'] as $uf => $sen) {
    foreach (($sen['candidates'] ?? []) as $c) {
        if ($c['elected'] === 's' || str_contains($c['situation'], 'Eleito')) {
            $p = $c['party'] ?: 'OUTROS';
            $senParty[$p] = ($senParty[$p] ?? 0) + 1;
        }
    }
}
arsort($senParty);

$depParty = [];
foreach ($result['deputies'] as $uf => $dep) {
    foreach (($dep['candidates'] ?? []) as $c) {
        if ($c['elected'] === 's' || str_contains($c['situation'], 'Eleito')) {
            $p = $c['party'] ?: 'OUTROS';
            $depParty[$p] = ($depParty[$p] ?? 0) + 1;
        }
    }
}
arsort($depParty);

$stateDepParty = [];
foreach ($result['stateDeputies'] as $uf => $dep) {
    foreach (($dep['candidates'] ?? []) as $c) {
        if ($c['elected'] === 's' || str_contains($c['situation'], 'Eleito')) {
            $p = $c['party'] ?: 'OUTROS';
            $stateDepParty[$p] = ($stateDepParty[$p] ?? 0) + 1;
        }
    }
}
arsort($stateDepParty);

$govParty = [];
$govRunoffParty = [];
foreach ($result['governors'] as $uf => $gov) {
    $cands = $gov['candidates'] ?? [];
    if (!empty($cands)) {
        $top = $cands[0];
        if ($top['pct'] > 50 || str_contains($top['situation'], 'Eleito')) {
            $p = $top['party'] ?: 'OUTROS';
            $govParty[$p] = ($govParty[$p] ?? 0) + 1;
        } elseif (str_contains($top['situation'], '2º turno') || count($cands) > 1) {
            $p1 = $top['party'] ?: 'OUTROS';
            $govRunoffParty[$p1] = ($govRunoffParty[$p1] ?? 0) + 1;
            if (isset($cands[1])) {
                $p2 = $cands[1]['party'] ?: 'OUTROS';
                $govRunoffParty[$p2] = ($govRunoffParty[$p2] ?? 0) + 1;
            }
        }
    }
}
arsort($govParty);
arsort($govRunoffParty);

$result['summary'] = [
    'senatorsByParty' => $senParty,
    'deputiesByParty' => $depParty,
    'governorsByParty' => $govParty,
    'governorsRunoffByParty' => $govRunoffParty,
    'stateDeputiesByParty' => $stateDepParty,
    'governorsByParty' => $govParty,
    'governorsRunoffByParty' => $govRunoffParty,
];

$result['stats'] = [
];

if ($result['president'] === null && is_file($cacheFile)) {
    $old = json_decode((string)file_get_contents($cacheFile), true);
    if (is_array($old) && !empty($old['president'])) {
        $result = $old;
        $result['stats']['cached'] = true;
    }
}

$json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($result['president'] !== null) {
    file_put_contents($cacheFile, $json, LOCK_EX);
}
echo $json;
