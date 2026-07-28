import os
import httpx
from dotenv import load_dotenv

load_dotenv()

APP_KEY = os.getenv("KIS_APP_KEY")
APP_SECRET = os.getenv("KIS_APP_SECRET")
IS_MOCK = os.getenv("KIS_IS_MOCK", "true").lower() == "true"

BASE_URL = "https://openapivts.koreainvestment.com:29443" if IS_MOCK else "https://openapi.koreainvestment.com:9443"


def get_access_token() -> str:
    """액세스 토큰 발급."""
    url = f"{BASE_URL}/oauth2/tokenP"
    body = {
        "grant_type": "client_credentials",
        "appkey": APP_KEY,
        "appsecret": APP_SECRET,
    }
    res = httpx.post(url, json=body)
    res.raise_for_status()
    data = res.json()
    print("토큰 발급 성공!")
    print("access_token:", data["access_token"][:20], "...")
    print("만료:", data["access_token_token_expired"])
    return data["access_token"]


def get_current_price(token: str, ticker: str) -> dict:
    """현재가 조회 (삼성전자: 005930)."""
    url = f"{BASE_URL}/uapi/domestic-stock/v1/quotations/inquire-price"
    headers = {
        "content-type": "application/json",
        "authorization": f"Bearer {token}",
        "appkey": APP_KEY,
        "appsecret": APP_SECRET,
        "tr_id": "FHKST01010100",
    }
    params = {
        "FID_COND_MRKT_DIV_CODE": "J",
        "FID_INPUT_ISCD": ticker,
    }
    res = httpx.get(url, headers=headers, params=params)
    res.raise_for_status()
    data = res.json()

    output = data.get("output", {})
    print(f"\n[{ticker}] 현재가 조회")
    print(f"종목명: {output.get('hts_kor_isnm')}")
    print(f"현재가: {output.get('stck_prpr')}원")
    print(f"등락률: {output.get('prdy_ctrt')}%")
    print(f"거래량: {output.get('acml_vol')}")
    return output


if __name__ == "__main__":
    token = get_access_token()
    get_current_price(token, "005930")  # 삼성전자
    get_current_price(token, "000660")  # SK하이닉스