import os
import time
import httpx
from dotenv import load_dotenv

load_dotenv()

APP_KEY    = os.getenv("KIS_APP_KEY")
APP_SECRET = os.getenv("KIS_APP_SECRET")
ACCOUNT_NO = os.getenv("KIS_ACCOUNT_NO")
ACCOUNT_PRODUCT_CD = os.getenv("KIS_ACCOUNT_PRODUCT_CD", "01")
IS_MOCK    = os.getenv("KIS_IS_MOCK", "true").lower() == "true"

BASE_URL = (
    "https://openapivts.koreainvestment.com:29443"
    if IS_MOCK
    else "https://openapi.koreainvestment.com:9443"
)

# 토큰 캐시 (프로세스 내 메모리)
_token_cache: dict = {"token": None, "expires_at": 0}


def get_access_token() -> str:
    """액세스 토큰 반환 (캐시 유효하면 재사용, 만료되면 재발급)."""
    now = time.time()
    if _token_cache["token"] and _token_cache["expires_at"] > now + 60:
        return _token_cache["token"]

    res = httpx.post(
        f"{BASE_URL}/oauth2/tokenP",
        json={
            "grant_type": "client_credentials",
            "appkey": APP_KEY,
            "appsecret": APP_SECRET,
        },
    )
    res.raise_for_status()
    data = res.json()

    _token_cache["token"] = data["access_token"]
    # 만료 시각을 Unix timestamp로 변환
    from datetime import datetime
    expires_str = data["access_token_token_expired"]
    expires_dt = datetime.strptime(expires_str, "%Y-%m-%d %H:%M:%S")
    _token_cache["expires_at"] = expires_dt.timestamp()

    return _token_cache["token"]


def _headers(tr_id: str) -> dict:
    """공통 헤더 생성."""
    return {
        "content-type": "application/json",
        "authorization": f"Bearer {get_access_token()}",
        "appkey": APP_KEY,
        "appsecret": APP_SECRET,
        "tr_id": tr_id,
    }


def get_current_price(ticker: str) -> dict:
    """현재가 조회."""
    res = httpx.get(
        f"{BASE_URL}/uapi/domestic-stock/v1/quotations/inquire-price",
        headers=_headers("FHKST01010100"),
        params={
            "FID_COND_MRKT_DIV_CODE": "J",
            "FID_INPUT_ISCD": ticker,
        },
    )
    res.raise_for_status()
    output = res.json().get("output", {})

    return {
        "ticker": ticker,
        "current_price": int(output.get("stck_prpr", 0)),
        "change_rate": float(output.get("prdy_ctrt", 0)),
        "change_price": int(output.get("prdy_vrss", 0)),
        "volume": int(output.get("acml_vol", 0)),
        "open": int(output.get("stck_oprc", 0)),
        "high": int(output.get("stck_hgpr", 0)),
        "low": int(output.get("stck_lwpr", 0)),
    }


def get_minute_ohlcv(ticker: str, time_div: str = "30") -> list[dict]:
    """분봉 데이터 조회 (time_div: 1, 5, 10, 15, 30, 60분)."""
    res = httpx.get(
        f"{BASE_URL}/uapi/domestic-stock/v1/quotations/inquire-time-itemchartprice",
        headers=_headers("FHKST03010200"),
        params={
            "FID_ETC_CLS_CODE": "",
            "FID_COND_MRKT_DIV_CODE": "J",
            "FID_INPUT_ISCD": ticker,
            "FID_INPUT_HOUR_1": time_div,
            "FID_PW_DATA_INCU_YN": "N",
        },
    )
    res.raise_for_status()
    output2 = res.json().get("output2", [])

    return [
        {
            "time": row.get("stck_bsop_date") + row.get("stck_cntg_hour", ""),
            "open": int(row.get("stck_oprc", 0)),
            "high": int(row.get("stck_hgpr", 0)),
            "low": int(row.get("stck_lwpr", 0)),
            "close": int(row.get("stck_prpr", 0)),
            "volume": int(row.get("cntg_vol", 0)),
        }
        for row in output2
    ]

# ============================================================
# 주문 실행 (kis_client.py 하단에 추가)
# ============================================================

# 모의투자/실전 tr_id 매핑
_ORDER_TR_ID = {
    True:  {"buy": "VTTC0802U", "sell": "VTTC0801U"},   # 모의투자
    False: {"buy": "TTTC0802U", "sell": "TTTC0801U"},   # 실전
}


def place_order(
    ticker: str,
    order_type: str,        # "buy" | "sell"
    quantity: int,
    price: int = 0,         # 0이면 시장가
) -> dict:
    """
    주식 매수/매도 주문.
    성공 시: {"success": True, "order_no": "...", "order_time": "..."}
    실패 시: {"success": False, "message": "..."}
    """
    if order_type not in ("buy", "sell"):
        raise ValueError("order_type은 'buy' 또는 'sell'이어야 합니다.")

    tr_id = _ORDER_TR_ID[IS_MOCK][order_type]
    is_market = price == 0

    body = {
    "CANO": ACCOUNT_NO,
    "ACNT_PRDT_CD": ACCOUNT_PRODUCT_CD,
    "PDNO": ticker,
    "ORD_DVSN": "01" if is_market else "00",
    "ORD_QTY": str(quantity),
    "ORD_UNPR": str(price),
    }

    res = httpx.post(
        f"{BASE_URL}/uapi/domestic-stock/v1/trading/order-cash",
        headers=_headers(tr_id),
        json=body,
    )
    res.raise_for_status()
    data = res.json()

    if data.get("rt_cd") != "0":
        return {"success": False, "message": data.get("msg1", "주문 실패")}

    output = data.get("output", {})
    return {
        "success": True,
        "order_no": output.get("ODNO"),
        "order_time": output.get("ORD_TMD"),
    }


def get_order_status(order_no: str, order_date: str, ticker: str = "") -> dict:
    """
    당일 주문 체결 조회.
    order_date: "YYYYMMDD" 형식
    ticker: 특정 종목으로 필터링하고 싶을 때 (선택)
    """
    tr_id = "VTTC0081R" if IS_MOCK else "TTTC0081R"

    res = httpx.get(
        f"{BASE_URL}/uapi/domestic-stock/v1/trading/inquire-daily-ccld",
        headers=_headers(tr_id),
        params={
            "CANO": ACCOUNT_NO,
            "ACNT_PRDT_CD": ACCOUNT_PRODUCT_CD,
            "INQR_STRT_DT": order_date,
            "INQR_END_DT": order_date,
            "SLL_BUY_DVSN_CD": "00",
            "ODNO": order_no,
            "CCLD_DVSN": "00",
            "PDNO": ticker,
            "ORD_GNO_BRNO": "",
            "INQR_DVSN": "00",
            "INQR_DVSN_1": "",
            "INQR_DVSN_3": "00",
            "EXCG_ID_DVSN_CD": "KRX",
            "CTX_AREA_FK100": "",
            "CTX_AREA_NK100": "",
        },
    )
    res.raise_for_status()
    data = res.json()
    rows = data.get("output1", [])

    matched = next((r for r in rows if r.get("odno") == order_no), None)
    if not matched:
        return {"status": "not_found"}

    filled_qty = int(matched.get("tot_ccld_qty", 0))
    order_qty = int(matched.get("ord_qty", 0))

    return {
        "status": "filled" if filled_qty >= order_qty else (
            "partial" if filled_qty > 0 else "pending"
        ),
        "filled_qty": filled_qty,
        "filled_price": int(matched.get("avg_prvs", 0) or 0),
    }

def get_account_balance() -> dict:
    """계좌 잔고(예수금 + 보유종목 평가) 조회."""
    tr_id = "VTTC8434R" if IS_MOCK else "TTTC8434R"

    res = httpx.get(
        f"{BASE_URL}/uapi/domestic-stock/v1/trading/inquire-balance",
        headers=_headers(tr_id),
        params={
            "CANO": ACCOUNT_NO,
            "ACNT_PRDT_CD": ACCOUNT_PRODUCT_CD,
            "AFHR_FLPR_YN": "N",
            "OFL_YN": "",
            "INQR_DVSN": "02",
            "UNPR_DVSN": "01",
            "FUND_STTL_ICLD_YN": "N",
            "FNCG_AMT_AUTO_RDPT_YN": "N",
            "PRCS_DVSN": "01",
            "CTX_AREA_FK100": "",
            "CTX_AREA_NK100": "",
        },
    )
    res.raise_for_status()
    data = res.json()
    output2 = data.get("output2", [{}])
    summary = output2[0] if output2 else {}

    return {
        "cash": int(summary.get("dnca_tot_amt", 0)),        # 예수금 총액
        "total_eval_amt": int(summary.get("tot_evlu_amt", 0)),  # 총평가금액(현금+주식)
    }