<?php
// Ranking do Enxame. Guarda os dados FORA da pasta pública (public_html).
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$pos = strpos(__DIR__, '/public_html');      // pasta acima de public_html, nunca acessível pela web
$dataDir = $pos !== false ? substr(__DIR__, 0, $pos) : dirname(__DIR__);
$file    = $dataDir . '/enxame-ranking.json';
$rlFile  = $dataDir . '/enxame-ratelimit.json';
$KEEP    = 200;

function out($code, $arr) { http_response_code($code); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }
function sortRank(&$d) { usort($d, function ($a, $b) { return [$b['wave'], $b['kills']] <=> [$a['wave'], $a['kills']]; }); }
function publicRow($r) { return ['name' => $r['name'], 'wave' => (int)$r['wave'], 'kills' => (int)$r['kills']]; }

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $d = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
    sortRank($d);
    $n = max(1, min(50, (int)($_GET['top'] ?? 10)));
    out(200, ['top' => array_map('publicRow', array_slice($d, 0, $n))]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(405, ['error' => 'Método não permitido']);

$in    = json_decode((string)file_get_contents('php://input'), true) ?: [];
$name  = trim(preg_replace('/[^\p{L}\p{N} _.\-]/u', '', (string)($in['name'] ?? '')));
$name  = mb_substr(preg_replace('/\s+/u', ' ', $name), 0, 16);
$wave  = (int)($in['wave'] ?? 0);
$kills = (int)($in['kills'] ?? 0);
if (mb_strlen($name) < 2 || $wave < 1 || $wave > 300 || $kills < 0 || $kills > $wave * 80) {
    out(400, ['error' => 'Pontuação inválida']);
}

// limite simples: 1 envio a cada 15 s por IP
$ip  = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '0');
$now = time();
$rl  = is_file($rlFile) ? (json_decode((string)file_get_contents($rlFile), true) ?: []) : [];
foreach ($rl as $k => $t) if ($now - $t > 3600) unset($rl[$k]);
if (isset($rl[$ip]) && $now - $rl[$ip] < 15) out(429, ['error' => 'Aguarde alguns segundos para enviar de novo']);
$rl[$ip] = $now;
@file_put_contents($rlFile, json_encode($rl), LOCK_EX);

$fp = fopen($file, 'c+');
if (!$fp) out(500, ['error' => 'Não foi possível gravar o ranking']);
flock($fp, LOCK_EX);
$d = json_decode((string)stream_get_contents($fp), true) ?: [];

$key = mb_strtolower($name);
$found = false;
foreach ($d as &$r) {
    if (mb_strtolower($r['name']) === $key) {
        $found = true;
        if ($wave > $r['wave'] || ($wave === (int)$r['wave'] && $kills > $r['kills'])) {
            $r = ['name' => $name, 'wave' => $wave, 'kills' => $kills, 'at' => date('c')];
        }
        break;
    }
}
unset($r);
if (!$found) $d[] = ['name' => $name, 'wave' => $wave, 'kills' => $kills, 'at' => date('c')];
sortRank($d);
$d = array_slice($d, 0, $KEEP);

ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($d, JSON_UNESCAPED_UNICODE));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

$rank = null;
foreach ($d as $i => $r) if (mb_strtolower($r['name']) === $key) { $rank = $i + 1; break; }
out(200, ['ok' => true, 'rank' => $rank, 'top' => array_map('publicRow', array_slice($d, 0, 10))]);
