<?php
/**
 * docs/build_note.php — 코드 공부노트 빌더
 *
 * 사용법:  php build_note.php <템플릿.html> <출력.html>
 *
 * 왜 이런 걸 쓰나:
 *   노트에 코드를 손으로 옮겨 적으면 (1) 오타가 나고 (2) 코드를 고쳐도 노트가 옛날 그대로 남는다.
 *   그래서 템플릿에는 "여기에 이 파일의 이 부분을 넣어라"는 표시만 두고,
 *   빌더가 빌드할 때마다 **실제 파일을 읽어** 넣는다. 그러면 노트는 항상 진짜 코드와 같다.
 *
 * 템플릿에 쓸 수 있는 표시 3가지:
 *   <!--CODE:user/func.php-->                     전체 파일
 *   <!--CODE:user/func.php:100-160-->             줄 번호 범위 (100~160줄)
 *   <!--CODE:user/func.php:FN:getMinuteCandles--> 함수 하나 (앞에 붙은 주석까지 자동 포함)
 *
 *   ⚠️ 줄 번호 방식은 코드를 조금만 고쳐도 엉뚱한 데를 가리킨다.
 *      그래서 되도록 FN(함수 이름) 방식을 쓴다. 함수는 위치가 밀려도 이름으로 다시 찾으니까.
 *
 * 경로 기준: 이 파일이 있는 docs/ 의 부모(stock-project/)와 그 옆 stock-ws/ 를 자동으로 찾는다.
 */

// ---------------------------------------------------------------
// 0. 인자 확인
// ---------------------------------------------------------------
if ($argc < 3) {
    fwrite(STDERR, "사용법: php build_note.php <템플릿.html> <출력.html>\n");
    exit(1);
}
$tplPath = $argv[1];
$outPath = $argv[2];

if (!is_file($tplPath)) {
    fwrite(STDERR, "템플릿을 못 찾음: $tplPath\n");
    exit(1);
}

// 코드를 찾을 뿌리 경로 2개. stock-project 안이면 그대로, 'stock-ws/...' 로 시작하면 옆 폴더.
$ROOT_PROJECT = dirname(__DIR__);              // .../www/stock-project
$ROOT_WWW     = dirname($ROOT_PROJECT);        // .../www

$stats = ['blocks' => 0, 'lines' => 0, 'files' => []];
$errors = [];
$maskCount = 0;

// ---------------------------------------------------------------
// 1. 상대경로 → 실제 경로
// ---------------------------------------------------------------
function resolvePath($rel, $ROOT_PROJECT, $ROOT_WWW)
{
    $rel = trim($rel);
    // 'stock-ws/index.js' 처럼 프로젝트 밖을 가리키면 www 기준으로 찾는다
    foreach ([$ROOT_PROJECT . '/' . $rel, $ROOT_WWW . '/' . $rel] as $p) {
        if (is_file($p)) return $p;
    }
    return null;
}

// ---------------------------------------------------------------
// 2. 함수 하나만 잘라내기
// ---------------------------------------------------------------
// 이름으로 함수 정의 줄을 찾고, 중괄호 { } 개수를 세면서 닫히는 줄까지 가져온다.
// 문자열이나 주석 안의 중괄호까지 세면 틀어지므로, 그런 구간은 미리 지운 사본으로 센다.
// 함수 바로 위에 붙어 있는 주석(//, /* */)은 '왜 이렇게 짰나'가 적혀 있어 같이 가져온다.
function extractFunction($src, $name)
{
    $lines = explode("\n", $src);
    $start = -1;

    // PHP: function 이름(   /  JS: function 이름( , const 이름 = ( , 이름( ... ) {  형태
    $patterns = [
        '/^\s*(?:async\s+)?function\s+' . preg_quote($name, '/') . '\s*\(/',
        '/^\s*(?:const|let|var)\s+' . preg_quote($name, '/') . '\s*=\s*(?:async\s*)?(?:function)?\s*\(/',
        '/^\s*' . preg_quote($name, '/') . '\s*\([^)]*\)\s*\{/',      // 객체 메서드 축약형
    ];
    foreach ($lines as $i => $ln) {
        foreach ($patterns as $p) {
            if (preg_match($p, $ln)) { $start = $i; break 2; }
        }
    }
    if ($start === -1) return null;

    // 위로 올라가며 붙어 있는 주석 줄을 흡수한다 (빈 줄을 만나면 멈춤)
    $head = $start;
    while ($head > 0) {
        $prev = trim($lines[$head - 1]);
        if ($prev === '') break;
        if (str_starts_with($prev, '//') || str_starts_with($prev, '*') ||
            str_starts_with($prev, '/*') || str_starts_with($prev, '#')) {
            $head--;
            continue;
        }
        break;
    }

    // 중괄호 균형으로 끝을 찾는다
    $depth = 0; $seen = false; $end = -1;
    for ($i = $start; $i < count($lines); $i++) {
        $clean = stripNonCode($lines[$i]);
        $depth += substr_count($clean, '{') - substr_count($clean, '}');
        if (strpos($clean, '{') !== false) $seen = true;
        if ($seen && $depth <= 0) { $end = $i; break; }
    }
    if ($end === -1) return null;

    return ['from' => $head + 1, 'to' => $end + 1];   // 1-base 줄번호
}

// 중괄호를 셀 때 방해가 되는 것(문자열·주석)을 지운 사본을 만든다.
function stripNonCode($line)
{
    $line = preg_replace('/\'(?:\\\\.|[^\'\\\\])*\'/', "''", $line);   // '...'
    $line = preg_replace('/"(?:\\\\.|[^"\\\\])*"/', '""', $line);      // "..."
    $line = preg_replace('/`(?:\\\\.|[^`\\\\])*`/', '``', $line);      // `...` (JS 템플릿 문자열)
    $line = preg_replace('#//.*$#', '', $line);                        // // 주석
    $line = preg_replace('#/\*.*?\*/#', '', $line);                    // /* */ 한 줄짜리
    return $line;
}

// ---------------------------------------------------------------
// 2.5 비밀값 마스킹  🔒 (이 노트를 남에게 보여줄 수 있게 하는 안전장치)
// ---------------------------------------------------------------
// 노트는 바탕화면에 두고 카톡으로 보내기도 하는 '문서'다.
// 그런데 db.php 에는 실제 DB 비밀번호가, kis_config.php 에는 한투 앱키/시크릿이 평문으로 있다.
// 그대로 넣으면 비밀이 문서에 영구 박제된다 → 빌드할 때 자동으로 가린다.
//
// 🔑 값을 코드에 적어두지 않고 '설정 파일에서 읽어와서' 가린다.
//    그래야 나중에 형이 비밀번호를 바꿔도 빌더를 고칠 필요 없이 계속 가려진다.
function collectSecrets($ROOT_PROJECT, $ROOT_WWW)
{
    $secrets = [];
    $push = function ($v) use (&$secrets) {
        $v = trim((string) $v);
        // 6자 미만은 흔한 단어일 수 있어 건드리지 않는다(엉뚱한 코드가 가려지면 노트가 이상해짐)
        if (strlen($v) >= 6) $secrets[$v] = true;
    };

    // config/db.php 의 $pass = '...'
    $db = $ROOT_PROJECT . '/config/db.php';
    if (is_file($db) && preg_match('/\$pass\s*=\s*[\'"]([^\'"]+)[\'"]/', file_get_contents($db), $m)) {
        $push($m[1]);
    }

    // config/kis_config.php 의 define('KIS_...', '...')
    $kis = $ROOT_PROJECT . '/config/kis_config.php';
    if (is_file($kis) && preg_match_all('/define\s*\(\s*[\'"][^\'"]*[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/', file_get_contents($kis), $m)) {
        foreach ($m[1] as $v) $push($v);
    }

    // stock-ws/.env 의 KEY=VALUE
    // ⚠️ 모든 값을 가리면 안 된다. DB_HOST·DB_NAME·DB_USER 까지 가려버리면
    //    "어느 DB를 쓰는가"라는 설명 자체가 ●●●● 가 되어 노트가 읽을 수 없게 된다.
    //    → 진짜 비밀(비밀번호·시크릿·키·토큰)만 골라서 가린다.
    $env = $ROOT_WWW . '/stock-ws/.env';
    if (is_file($env)) {
        foreach (explode("\n", file_get_contents($env)) as $line) {
            if (!preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.+)$/i', $line, $m)) continue;
            if (!preg_match('/(PASS|SECRET|KEY|TOKEN)/i', $m[1])) continue;
            $push(trim($m[2], " \t\"'"));
        }
    }

    // 긴 것부터 지워야 한다. 짧은 값이 먼저 지워지면 긴 값의 일부만 가려져 나머지가 남는다.
    $list = array_keys($secrets);
    usort($list, fn($a, $b) => strlen($b) - strlen($a));
    return $list;
}

function maskSecrets($code, $secrets, &$maskCount)
{
    foreach ($secrets as $s) {
        $n = 0;
        $code = str_replace($s, '••••••••[마스킹됨]', $code, $n);
        $maskCount += $n;
    }
    return $code;
}

// ---------------------------------------------------------------
// 3. 코드 → HTML (줄번호 + 이스케이프)
// ---------------------------------------------------------------
// htmlspecialchars 를 반드시 거친다. <?php 의 < 나 && 가 태그로 먹히면 문서가 깨진다.
function renderCode($code, $fromLine, $title)
{
    $lines = explode("\n", rtrim($code, "\n"));
    $out = '';
    $n = $fromLine;
    foreach ($lines as $ln) {
        $num = str_pad((string) $n, 4, ' ', STR_PAD_LEFT);
        $out .= '<span class="ln">' . $num . '</span> '
              . htmlspecialchars($ln, ENT_QUOTES, 'UTF-8') . "\n";
        $n++;
    }
    return '<div class="codewrap"><div class="codehead">'
         . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
         . '</div><pre><code>' . $out . '</code></pre></div>';
}

// ---------------------------------------------------------------
// 4. 템플릿 훑으면서 <!--CODE:...--> 를 치환
// ---------------------------------------------------------------
$tpl = file_get_contents($tplPath);
$SECRETS = collectSecrets($ROOT_PROJECT, $ROOT_WWW);

$result = preg_replace_callback(
    '/<!--CODE:([^>]+?)-->/',
    function ($m) use ($ROOT_PROJECT, $ROOT_WWW, $SECRETS, &$stats, &$errors, &$maskCount) {
        $spec = trim($m[1]);
        $parts = explode(':', $spec);
        $rel = array_shift($parts);

        $abs = resolvePath($rel, $ROOT_PROJECT, $ROOT_WWW);
        if ($abs === null) {
            $errors[] = "파일 없음: $rel";
            return '<div class="bad">빌드 오류 — 파일을 찾을 수 없습니다: '
                 . htmlspecialchars($rel) . '</div>';
        }

        $src = file_get_contents($abs);
        $src = str_replace("\r\n", "\n", $src);
        $all = explode("\n", $src);

        $from = 1;
        $to   = count($all);
        $tag  = '전체';

        if ($parts) {
            if (strtoupper($parts[0]) === 'FN') {
                $fname = $parts[1] ?? '';
                $range = extractFunction($src, $fname);
                if ($range === null) {
                    $errors[] = "함수 없음: $rel :: $fname";
                    return '<div class="bad">빌드 오류 — 함수를 찾을 수 없습니다: '
                         . htmlspecialchars("$rel :: $fname") . '</div>';
                }
                $from = $range['from'];
                $to   = $range['to'];
                $tag  = $fname . '()';
            } elseif (preg_match('/^(\d+)-(\d+)$/', $parts[0], $mm)) {
                $from = max(1, (int) $mm[1]);
                $to   = min(count($all), (int) $mm[2]);
                $tag  = "{$from}~{$to}줄";
            }
        }

        $slice = implode("\n", array_slice($all, $from - 1, $to - $from + 1));
        $slice = maskSecrets($slice, $SECRETS, $maskCount);   // 🔒 비밀번호·앱키는 여기서 가려진다

        $stats['blocks']++;
        $stats['lines'] += ($to - $from + 1);
        $stats['files'][$rel] = true;

        return renderCode($slice, $from, "$rel  ($tag)");
    },
    $tpl
);

// ---------------------------------------------------------------
// 5. 저장 + 결과 보고
// ---------------------------------------------------------------
file_put_contents($outPath, $result);

echo "빌드 완료: $outPath\n";
echo "  코드블록 {$stats['blocks']}개 / 총 {$stats['lines']}줄 / 원본파일 "
   . count($stats['files']) . "개\n";
echo "  출력 크기 " . number_format(strlen($result)) . " bytes\n";
echo "  🔒 비밀값 마스킹 {$maskCount}곳 (감시 대상 " . count($SECRETS) . "개)\n";

// 최종 안전검사: 혹시 마스킹을 빠져나간 비밀값이 결과물에 남았는지 직접 확인한다.
// (템플릿 본문에 실수로 적었을 수도 있으므로 코드블록만이 아니라 '출력 전체'를 검사한다)
$leak = [];
foreach ($SECRETS as $s) if (strpos($result, $s) !== false) $leak[] = substr($s, 0, 4) . '…';
if ($leak) {
    echo "\n🔴 위험 — 비밀값이 결과물에 남아 있습니다: " . implode(', ', $leak) . "\n";
    exit(3);
}

if ($errors) {
    echo "\n⚠️ 오류 " . count($errors) . "건:\n";
    foreach ($errors as $e) echo "   - $e\n";
    exit(2);
}
exit(0);
