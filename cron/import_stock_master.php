<?php
require_once __DIR__ . '/../config/db.php';

// -----------------------------
// 1. 마스터 파일 다운로드 + 압축 해제
// -----------------------------
function downloadAndExtract($url, $zipPath, $extractDir)
{
    file_put_contents($zipPath, file_get_contents($url));

    $zip = new ZipArchive();
    if ($zip->open($zipPath) === true) {
        $zip->extractTo($extractDir);
        $zip->close();
    } else {
        throw new Exception("압축 해제 실패: " . $zipPath);
    }
}

// -----------------------------
// 2. mst 파일 한 줄씩 파싱해서 DB에 저장
// -----------------------------
function importMstFile($conn, $mstFilePath, $marketName)
{
    $lines = file($mstFilePath, FILE_IGNORE_NEW_LINES);
    $count = 0;

    $stmt = mysqli_prepare($conn, "
        INSERT INTO stock_master (stock_code, stock_name, market)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE stock_name = VALUES(stock_name), market = VALUES(market)
    ");

    foreach ($lines as $line) {
        // 마지막 228바이트는 각종 플래그 정보라 제외하고, 앞부분만 사용
        $part1 = substr($line, 0, strlen($line) - 228);

        $shortCode = substr($part1, 0, 9);           // 단축코드 (=우리가 쓰는 종목코드)
        $nameBytes = substr($part1, 21);              // 한글 종목명 (EUC-KR 상태)

        $stockCode = trim($shortCode);
        $stockName = trim(iconv('EUC-KR', 'UTF-8//IGNORE', $nameBytes));

        if ($stockCode === '' || $stockName === '') continue;   // 빈 줄 방어

        mysqli_stmt_bind_param($stmt, "sss", $stockCode, $stockName, $marketName);
        mysqli_stmt_execute($stmt);
        $count++;
    }

    return $count;
}

// -----------------------------
// 실행부
// -----------------------------
$tempDir = __DIR__ . '/temp_master';
if (!is_dir($tempDir)) mkdir($tempDir);

downloadAndExtract(
    "https://new.real.download.dws.co.kr/common/master/kospi_code.mst.zip",
    $tempDir . '/kospi.zip',
    $tempDir
);
downloadAndExtract(
    "https://new.real.download.dws.co.kr/common/master/kosdaq_code.mst.zip",
    $tempDir . '/kosdaq.zip',
    $tempDir
);

$kospiCount = importMstFile($conn, $tempDir . '/kospi_code.mst', 'KOSPI');
$kosdaqCount = importMstFile($conn, $tempDir . '/kosdaq_code.mst', 'KOSDAQ');

echo "코스피 {$kospiCount}개, 코스닥 {$kosdaqCount}개 저장 완료\n";
