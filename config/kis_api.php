<?php
require_once __DIR__ . '/kis_config.php';
require_once __DIR__ . '/db.php';

// -----------------------------
// 접근 토큰 발급 (캐싱 포함)
// -----------------------------
function getKisAccessToken()
{
    $cacheFile = __DIR__ . '/token_cache.json';

    // 1. 캐시 파일이 있으면 먼저 확인
    if (file_exists($cacheFile)) {
        $cached = json_decode(file_get_contents($cacheFile), true);

        // 저장된 만료시간이 아직 안 지났으면 재사용
        if ($cached && isset($cached['expire_at']) && $cached['expire_at'] > time()) {
            return $cached['access_token'];
        }
    }

    // 2. 캐시가 없거나 만료됐으면 새로 발급 요청
    $url = "https://openapivts.koreainvestment.com:29443/oauth2/tokenP";

    $data = [
        "grant_type" => "client_credentials",
        "appkey"     => KIS_APP_KEY,
        "appsecret"  => KIS_APP_SECRET,
    ];

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ["content-type: application/json"],
        CURLOPT_POSTFIELDS => json_encode($data),
    ]);

    $response = curl_exec($curl);
    curl_close($curl);

    $result = json_decode($response, true);

    // 3. 발급 실패 시 에러 처리
    if (!isset($result['access_token'])) {
        throw new Exception("토큰 발급 실패: " . $response);
    }

    // 4. 캐시 파일에 저장 (23시간 뒤 만료로 잡아둠, 24시간보다 여유 있게)
    $cacheData = [
        'access_token' => $result['access_token'],
        'expire_at' => time() + (23 * 60 * 60),
    ];
    file_put_contents($cacheFile, json_encode($cacheData));

    return $result['access_token'];
}


function getStockPrice($stockCode)
{
    $token = getKisAccessToken();

    $url = "https://openapivts.koreainvestment.com:29443/uapi/domestic-stock/v1/quotations/inquire-price"
        . "?FID_COND_MRKT_DIV_CODE=J&FID_INPUT_ISCD=" . $stockCode;

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "content-type: application/json",
            "authorization: Bearer " . $token,
            "appkey: " . KIS_APP_KEY,
            "appsecret: " . KIS_APP_SECRET,
            "tr_id: FHKST01010100",
        ],
    ]);

    $response = curl_exec($curl);
    curl_close($curl);

    $result = json_decode($response, true);

    if (!isset($result['output'])) {
        throw new Exception("시세 조회 실패: " . $response);
    }

    return [
        'stock_code' => $stockCode,
        'price' => (int) $result['output']['stck_prpr'],
        'change_price' => (int) $result['output']['prdy_vrss'],
        'change_rate' => (float) $result['output']['prdy_ctrt'],
        'volume' => (int) $result['output']['acml_vol'],
        'stock_name' => $result['output']['hts_kor_isnm'] ?? null,
    ];
}



function upsertStockMaster($conn, $stockCode, $stockName)
{
    $stmt = mysqli_prepare($conn, "SELECT stock_code FROM stock_master WHERE stock_code = ?");
    mysqli_stmt_bind_param($stmt, "s", $stockCode);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if (mysqli_num_rows($result) === 0) {
        $stmt2 = mysqli_prepare($conn, "INSERT INTO stock_master (stock_code, stock_name) VALUES (?, ?)");
        mysqli_stmt_bind_param($stmt2, "ss", $stockCode, $stockName);
        mysqli_stmt_execute($stmt2);
    }
}


// 시세 로그 저장
function saveStockLog($conn, $priceData)
{
    $stmt = mysqli_prepare($conn, "
        INSERT INTO stock_logs (stock_code, price, change_price, change_rate, volume)
        VALUES (?, ?, ?, ?, ?)
    ");
    mysqli_stmt_bind_param(
        $stmt,
        "siidi",
        $priceData['stock_code'],
        $priceData['price'],
        $priceData['change_price'],
        $priceData['change_rate'],
        $priceData['volume']
    );
    return mysqli_stmt_execute($stmt);
}


// 시세 조회 + DB 저장을 한 번에

function fetchAndSaveStockPrice($conn, $stockCode, $stockName = null)
{
    $priceData = getStockPrice($stockCode);

    // API가 종목명을 안 주면, 넘겨받은 이름 사용 (그것도 없으면 종목코드로 대체)
    $nameToSave = $priceData['stock_name'] ?? $stockName ?? $stockCode;

    upsertStockMaster($conn, $stockCode, $nameToSave);
    saveStockLog($conn, $priceData);

    return $priceData;
}

// -----------------------------
// 해시키 발급 (주문 데이터 위변조 방지용)
// -----------------------------
function getHashKey($data)
{
    $token = getKisAccessToken();
    $url = "https://openapivts.koreainvestment.com:29443/uapi/hashkey";

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "content-type: application/json",
            "appkey: " . KIS_APP_KEY,
            "appsecret: " . KIS_APP_SECRET,
        ],
        CURLOPT_POSTFIELDS => json_encode($data),
    ]);

    $response = curl_exec($curl);
    curl_close($curl);

    $result = json_decode($response, true);
    return $result['HASH'] ?? null;
}

// -----------------------------
// 매수/매도 공통 주문 함수
// -----------------------------
function placeStockOrder($stockCode, $quantity, $orderType)
{
    // $orderType: 'buy' 또는 'sell'
    $token = getKisAccessToken();
    $trId = ($orderType === 'buy') ? 'VTTC0802U' : 'VTTC0801U';

    $orderData = [
        "CANO" => KIS_ACCOUNT_NO,
        "ACNT_PRDT_CD" => KIS_ACCOUNT_PRDT_CD,
        "PDNO" => $stockCode,
        "ORD_DVSN" => "01",          // 01 = 시장가
        "ORD_QTY" => (string) $quantity,
        "ORD_UNPR" => "0",           // 시장가라 0
    ];

    $hashKey = getHashKey($orderData);

    $url = "https://openapivts.koreainvestment.com:29443/uapi/domestic-stock/v1/trading/order-cash";

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "content-type: application/json",
            "authorization: Bearer " . $token,
            "appkey: " . KIS_APP_KEY,
            "appsecret: " . KIS_APP_SECRET,
            "tr_id: " . $trId,
            "hashkey: " . $hashKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($orderData),
    ]);

    $response = curl_exec($curl);
    curl_close($curl);

    $result = json_decode($response, true);

    if ($result['rt_cd'] !== '0') {
        throw new Exception("주문 실패: " . ($result['msg1'] ?? '알 수 없는 오류'));
    }

    return $result['output'];   // 주문번호(ODNO) 등이 들어있음
}
