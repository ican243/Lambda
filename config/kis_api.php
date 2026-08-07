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


function getStockPrice($stockCode, $retryCount = 0)
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

    // 초당 거래건수 초과(EGW00201)면 잠깐 쉬었다 재시도 (최대 3회)
    if (($result['msg_cd'] ?? '') === 'EGW00201' && $retryCount < 3) {
        usleep(700000);   // 0.7초 대기 후
        return getStockPrice($stockCode, $retryCount + 1);
    }

    if (!isset($result['output'])) {
        throw new Exception("시세 조회 실패: " . $response);
    }

    return [
        'stock_code' => $stockCode,
        'price' => (int) $result['output']['stck_prpr'],
        'change_price' => (int) $result['output']['prdy_vrss'],
        'change_rate' => (float) $result['output']['prdy_ctrt'],
        'volume' => (int) $result['output']['acml_vol'],
        // 누적거래대금(원) — 토스/한투 순위 기준. price×volume 근사가 아니라 실제 값.
        'trade_value' => (int) ($result['output']['acml_tr_pbmn'] ?? 0),
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
    $tradeValue = $priceData['trade_value'] ?? 0;
    $stmt = mysqli_prepare($conn, "
        INSERT INTO stock_logs (stock_code, price, change_price, change_rate, volume, trade_value)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    mysqli_stmt_bind_param(
        $stmt,
        "siidii",
        $priceData['stock_code'],
        $priceData['price'],
        $priceData['change_price'],
        $priceData['change_rate'],
        $priceData['volume'],
        $tradeValue
    );
    return mysqli_stmt_execute($stmt);
}

function upsertStockLatest($conn, $priceData)
{
    $tradeValue = $priceData['trade_value'] ?? 0;
    $stmt = mysqli_prepare($conn, "
        INSERT INTO stock_latest (stock_code, stock_name, price, change_price, change_rate, volume, trade_value)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            price = VALUES(price),
            change_price = VALUES(change_price),
            change_rate = VALUES(change_rate),
            volume = VALUES(volume),
            trade_value = VALUES(trade_value)
    ");
    mysqli_stmt_bind_param(
        $stmt,
        "ssiidii",
        $priceData['stock_code'],
        $priceData['stock_name'],
        $priceData['price'],
        $priceData['change_price'],
        $priceData['change_rate'],
        $priceData['volume'],
        $tradeValue
    );
    return mysqli_stmt_execute($stmt);
}


// 시세 조회 + DB 저장을 한 번에

function fetchAndSaveStockPrice($conn, $stockCode, $stockName = null)
{
    $priceData = getStockPrice($stockCode);

    // API가 종목명을 안 주면, 넘겨받은 이름 사용 (그것도 없으면 종목코드로 대체)
    // ※ 모의투자 API는 일부 종목의 hts_kor_isnm을 비워서 준다 → 폴백 필수.
    $nameToSave = $priceData['stock_name'] ?? $stockName ?? $stockCode;
    // 이후 saveStockLog/upsertStockLatest가 이 값을 그대로 쓰므로 폴백을 반영해준다.
    $priceData['stock_name'] = $nameToSave;

    upsertStockMaster($conn, $stockCode, $nameToSave);
    saveStockLog($conn, $priceData);
    upsertStockLatest($conn, $priceData);

    return $priceData;
}

// =============================================================
// [상세페이지 위젯용] 시세조회 계열 공통 GET 헬퍼
//   - 모두 모의투자(VTS) 도메인에서 정상 동작 확인됨(2026-07-27).
//   - EGW00201(초당제한)이면 0.7초 후 재시도(최대 3회).
// =============================================================
function kisQuotationGet($path, $trId, $params, $retryCount = 0)
{
    $token = getKisAccessToken();
    $url = "https://openapivts.koreainvestment.com:29443" . $path . "?" . http_build_query($params);

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            "content-type: application/json",
            "authorization: Bearer " . $token,
            "appkey: " . KIS_APP_KEY,
            "appsecret: " . KIS_APP_SECRET,
            "tr_id: " . $trId,
            "custtype: P",
        ],
    ]);
    $response = curl_exec($curl);
    curl_close($curl);
    $result = json_decode($response, true);

    if (($result['msg_cd'] ?? '') === 'EGW00201' && $retryCount < 3) {
        usleep(700000);
        return kisQuotationGet($path, $trId, $params, $retryCount + 1);
    }
    return $result;
}

// 종목 전체 시세(지표 스트립용): 당일/52주 고저, 거래대금, PER/PBR, 외국인 등
function getFullQuote($stockCode)
{
    $r = kisQuotationGet(
        "/uapi/domestic-stock/v1/quotations/inquire-price",
        "FHKST01010100",
        ["FID_COND_MRKT_DIV_CODE" => "J", "FID_INPUT_ISCD" => $stockCode]
    );
    $o = $r['output'] ?? null;
    if (!$o) return null;
    return [
        'price'        => (int) $o['stck_prpr'],
        'change_price' => (int) $o['prdy_vrss'],
        'change_rate'  => (float) $o['prdy_ctrt'],
        'sign'         => (int) $o['prdy_vrss_sign'],   // 1상한 2상승 3보합 4하한 5하락
        'open'         => (int) $o['stck_oprc'],
        'high'         => (int) $o['stck_hgpr'],
        'low'          => (int) $o['stck_lwpr'],
        'prev_close'   => (int) $o['stck_sdpr'],
        'upper_limit'  => (int) $o['stck_mxpr'],
        'lower_limit'  => (int) $o['stck_llam'],
        'volume'       => (int) $o['acml_vol'],
        'trade_value'  => (int) $o['acml_tr_pbmn'],
        'w52_high'     => (int) $o['w52_hgpr'],
        'w52_low'      => (int) $o['w52_lwpr'],
        'w52_high_date' => $o['w52_hgpr_date'] ?? '',
        'w52_low_date'  => $o['w52_lwpr_date'] ?? '',
        'per'          => (float) $o['per'],
        'pbr'          => (float) $o['pbr'],
        'eps'          => (float) $o['eps'],
        'bps'          => (float) $o['bps'],
        'market_cap'   => (int) $o['hts_avls'],          // 억 단위
        'shares'       => (int) $o['lstn_stcn'],
        'frgn_ehrt'    => (float) $o['hts_frgn_ehrt'],    // 외국인 소진율
        'frgn_ntby'    => (int) $o['frgn_ntby_qty'],      // 외국인 순매수(당일)
        'sector'       => $o['bstp_kor_isnm'] ?? '',
        'market'       => $o['rprs_mrkt_kor_name'] ?? '',
    ];
}

// 호가 10단계 (매도/매수 잔량)
function getOrderbook($stockCode)
{
    $r = kisQuotationGet(
        "/uapi/domestic-stock/v1/quotations/inquire-asking-price-exp-ccn",
        "FHKST01010200",
        ["FID_COND_MRKT_DIV_CODE" => "J", "FID_INPUT_ISCD" => $stockCode]
    );
    $o1 = $r['output1'] ?? null;
    if (!$o1) return null;
    $asks = [];
    $bids = [];
    for ($i = 1; $i <= 10; $i++) {
        $asks[] = ['price' => (int) $o1["askp$i"], 'qty' => (int) $o1["askp_rsqn$i"]];
        $bids[] = ['price' => (int) $o1["bidp$i"], 'qty' => (int) $o1["bidp_rsqn$i"]];
    }
    $o2 = $r['output2'] ?? [];
    return [
        'asks'      => $asks,   // index0 = 최우선 매도호가(가장 낮은 매도가)
        'bids'      => $bids,   // index0 = 최우선 매수호가(가장 높은 매수가)
        'total_ask' => (int) ($o1['total_askp_rsqn'] ?? 0),
        'total_bid' => (int) ($o1['total_bidp_rsqn'] ?? 0),
        'expected'  => [
            'price' => (int) ($o2['antc_cnpr'] ?? 0),
            'qty'   => (int) ($o2['antc_vol'] ?? 0),
            'rate'  => (float) ($o2['antc_cntg_prdy_ctrt'] ?? 0),
            'sign'  => (int) ($o2['antc_cntg_vrss_sign'] ?? 3),
        ],
        'prev_close' => (int) ($o2['stck_sdpr'] ?? 0),
    ];
}

// 최근 체결 내역 (기본 30건) + 체결강도
function getRecentTrades($stockCode)
{
    $r = kisQuotationGet(
        "/uapi/domestic-stock/v1/quotations/inquire-ccnl",
        "FHKST01010300",
        ["FID_COND_MRKT_DIV_CODE" => "J", "FID_INPUT_ISCD" => $stockCode]
    );
    $rows = $r['output'] ?? [];
    $trades = [];
    foreach ($rows as $o) {
        if (($o['stck_cntg_hour'] ?? '') === '') continue;
        $trades[] = [
            'time'     => $o['stck_cntg_hour'],       // HHMMSS
            'price'    => (int) $o['stck_prpr'],
            'volume'   => (int) $o['cntg_vol'],
            'rate'     => (float) $o['prdy_ctrt'],
            'sign'     => (int) $o['prdy_vrss_sign'],
            'strength' => (float) $o['tday_rltv'],     // 체결강도
        ];
    }
    return [
        'trades'   => $trades,
        'strength' => $trades[0]['strength'] ?? 0,     // 지표 스트립용 대표 체결강도
    ];
}

// 개인/외국인/기관 순매수 추이 (오늘 행은 장중 집계전이라 빈값 → 제외)
function getInvestorTrend($stockCode)
{
    $r = kisQuotationGet(
        "/uapi/domestic-stock/v1/quotations/inquire-investor",
        "FHKST01010900",
        ["FID_COND_MRKT_DIV_CODE" => "J", "FID_INPUT_ISCD" => $stockCode]
    );
    $rows = $r['output'] ?? [];
    $out = [];
    foreach ($rows as $o) {
        if (trim($o['prsn_ntby_qty'] ?? '') === '') continue;   // 집계 전(오늘)
        $out[] = [
            'date'        => $o['stck_bsop_date'],
            'close'       => (int) $o['stck_clpr'],
            'sign'        => (int) $o['prdy_vrss_sign'],
            'individual'  => (int) $o['prsn_ntby_qty'],   // 개인 순매수(주)
            'foreign'     => (int) $o['frgn_ntby_qty'],   // 외국인
            'institution' => (int) $o['orgn_ntby_qty'],   // 기관
        ];
    }
    return $out;
}

// 거래대금 상위 순위 (volume-rank, FID_BLNG_CLS_CODE=3=거래대금순). VTS 정상 동작 확인.
function getVolumeRank($limit = 30)
{
    $r = kisQuotationGet(
        "/uapi/domestic-stock/v1/quotations/volume-rank",
        "FHPST01710000",
        [
            'FID_COND_MRKT_DIV_CODE' => 'J',
            'FID_COND_SCR_DIV_CODE'  => '20171',
            'FID_INPUT_ISCD'         => '0000',        // 전체
            'FID_DIV_CLS_CODE'       => '0',           // 전체
            'FID_BLNG_CLS_CODE'      => '3',           // 3 = 거래대금순
            'FID_TRGT_CLS_CODE'      => '111111111',
            'FID_TRGT_EXLS_CLS_CODE' => '0000000000',
            'FID_INPUT_PRICE_1'      => '',
            'FID_INPUT_PRICE_2'      => '',
            'FID_VOL_CNT'            => '',
            'FID_INPUT_DATE_1'       => '',
        ]
    );
    $rows = $r['output'] ?? [];
    $out = [];
    foreach ($rows as $o) {
        $code = $o['mksc_shrn_iscd'] ?? '';
        if ($code === '') continue;
        $out[] = [
            'rank'         => (int) ($o['data_rank'] ?? 0),
            'stock_code'   => $code,
            'stock_name'   => $o['hts_kor_isnm'] ?? $code,
            'price'        => (int) ($o['stck_prpr'] ?? 0),
            'change_price' => (int) ($o['prdy_vrss'] ?? 0),
            'change_rate'  => (float) ($o['prdy_ctrt'] ?? 0),
            'volume'       => (int) ($o['acml_vol'] ?? 0),
            'trade_value'  => (int) ($o['acml_tr_pbmn'] ?? 0),
        ];
        if (count($out) >= $limit) break;
    }
    return $out;
}

// =============================================================
// 일봉 조회 (기간별 시세) — 차트 3개월/1년/5년 탭의 재료
// =============================================================
// 우리가 직접 수집한 데이터는 2026-07-20부터뿐이다(그 전엔 수집기가 없었다).
// 그래서 '3개월/1년' 탭을 진짜로 채우려면 과거 시세를 어디선가 받아와야 하는데,
// KIS가 일봉을 통째로 준다. 모의투자(VTS) 도메인에서도 정상 동작함을 실호출로 확인했다.
//
// ⚠️ 한 번 호출에 output2가 최대 100행(=약 5개월)이다.
//    더 긴 기간이 필요하면 날짜 구간을 100영업일씩 잘라 여러 번 불러야 한다
//    → cron/backfill_daily.php 가 그 반복을 담당한다.
//
// $from / $to 는 'YYYYMMDD' 문자열.
function getDailyChart($stockCode, $from, $to)
{
    $r = kisQuotationGet(
        "/uapi/domestic-stock/v1/quotations/inquire-daily-itemchartprice",
        "FHKST03010100",
        [
            'FID_COND_MRKT_DIV_CODE' => 'J',        // J = 주식/ETF
            'FID_INPUT_ISCD'         => $stockCode,
            'FID_INPUT_DATE_1'       => $from,
            'FID_INPUT_DATE_2'       => $to,
            'FID_PERIOD_DIV_CODE'    => 'D',        // D=일 W=주 M=월 Y=년
            'FID_ORG_ADJ_PRC'        => '0',        // 0 = 수정주가 반영(액면분할 등으로 과거 가격이 튀지 않게)
        ]
    );
    if (($r['rt_cd'] ?? '1') !== '0') return [];

    $out = [];
    foreach ($r['output2'] ?? [] as $o) {
        $d = $o['stck_bsop_date'] ?? '';                 // 'YYYYMMDD'
        if (strlen($d) !== 8) continue;                  // 휴장일은 빈 행으로 오기도 한다
        $close = (int) ($o['stck_clpr'] ?? 0);
        if ($close <= 0) continue;                       // 값이 안 채워진 행 방어
        $out[] = [
            'date'        => substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2),
            'open'        => (int) ($o['stck_oprc'] ?? 0),
            'high'        => (int) ($o['stck_hgpr'] ?? 0),
            'low'         => (int) ($o['stck_lwpr'] ?? 0),
            'close'       => $close,
            'volume'      => (int) ($o['acml_vol'] ?? 0),
            'trade_value' => (int) ($o['acml_tr_pbmn'] ?? 0),
        ];
    }
    // KIS는 최신 날짜부터 역순으로 준다. 차트는 시간 오름차순이어야 하므로 뒤집는다.
    usort($out, fn($a, $b) => strcmp($a['date'], $b['date']));
    return $out;
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
function placeStockOrder($stockCode, $quantity, $orderType, $retryCount = 0)
{
    $token = getKisAccessToken();
    $trId = ($orderType === 'buy') ? 'VTTC0802U' : 'VTTC0801U';

    $orderData = [
        "CANO" => KIS_ACCOUNT_NO,
        "ACNT_PRDT_CD" => KIS_ACCOUNT_PRDT_CD,
        "PDNO" => $stockCode,
        "ORD_DVSN" => "01",
        "ORD_QTY" => (string) $quantity,
        "ORD_UNPR" => "0",
    ];

    $hashKey = getHashKey($orderData);

    $url = "https://openapivts.koreainvestment.com:29443/uapi/domestic-stock/v1/trading/order-cash";

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 10,   // 10초 이상 응답 없으면 포기하고 재시도 판단
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
    $curlError = curl_error($curl);
    curl_close($curl);

    $result = json_decode($response, true);

    // 재시도 대상 에러인지 판단 (초당 제한, 타임아웃)
    $isRetryable = $curlError !== ''
        || ($result['msg_cd'] ?? '') === 'EGW00201'   // 초당 거래건수 초과
        || $result === null;

    if ($isRetryable && $retryCount < 2) {
        sleep(2);   // 2초 쉬었다가
        return placeStockOrder($stockCode, $quantity, $orderType, $retryCount + 1);   // 재시도 (최대 2번)
    }

    if (!$result || $result['rt_cd'] !== '0') {
        throw new Exception("주문 실패: " . ($result['msg1'] ?? $curlError ?: '알 수 없는 오류'));
    }

    return $result['output'];
}
