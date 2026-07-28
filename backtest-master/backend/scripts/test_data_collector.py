from app.database import SessionLocal
from app.services.data_collector import (
    fetch_stock_ohlcv, fetch_index_ohlcv,
    save_price_history, ensure_stock_exists,
)

db = SessionLocal()

# 수집할 종목 목록 (시가총액 상위 종목으로 한정)
STOCKS = [
    ("005930", "삼성전자", "KOSPI"),
    ("000660", "SK하이닉스", "KOSPI"),
    ("035420", "NAVER", "KOSPI"),
    ("005380", "현대차", "KOSPI"),
    ("051910", "LG화학", "KOSPI"),
    ("006400", "삼성SDI", "KOSPI"),
    ("035720", "카카오", "KOSPI"),
    ("207940", "삼성바이오로직스", "KOSPI"),
    ("068270", "셀트리온", "KOSPI"),
    ("105560", "KB금융", "KOSPI"),
]

START = "20240101"
END   = "20260624"

print("=== 종목 데이터 수집 시작 ===")
for ticker, name, market in STOCKS:
    ensure_stock_exists(db, ticker, name, market)
    try:
        df = fetch_stock_ohlcv(ticker, START, END)
        n = save_price_history(db, ticker, df)
        print(f"[{ticker}] {name}: {n}건 저장")
    except Exception as e:
        print(f"[{ticker}] {name}: 오류 — {e}")

print("\n=== 지수 데이터 수집 ===")
ensure_stock_exists(db, "KOSPI", "코스피", "INDEX")
ensure_stock_exists(db, "KOSDAQ", "코스닥", "INDEX")

try:
    df_kospi = fetch_index_ohlcv("KOSPI", "2024-01-01", "2026-06-24")
    n = save_price_history(db, "KOSPI", df_kospi)
    print(f"KOSPI 지수: {n}건 저장")
except Exception as e:
    print(f"KOSPI 지수: 오류 — {e}")

try:
    df_kosdaq = fetch_index_ohlcv("KOSDAQ", "2024-01-01", "2026-06-24")
    n = save_price_history(db, "KOSDAQ", df_kosdaq)
    print(f"KOSDAQ 지수: {n}건 저장")
except Exception as e:
    print(f"KOSDAQ 지수: 오류 — {e}")

db.close()
print("\n완료!")