from datetime import datetime
from pykrx import stock as pykrx_stock
import FinanceDataReader as fdr
from sqlalchemy.orm import Session
from sqlalchemy.dialects.sqlite import insert as sqlite_insert

from app.models.price_history import PriceHistory
from app.models.stock import Stock

# 지수 ticker(내부 표기) -> FinanceDataReader 코드 매핑
INDEX_FDR_CODE = {
    "KOSPI": "KS11",
    "KOSDAQ": "KQ11",
}


def fetch_stock_ohlcv(ticker: str, start: str, end: str):
    """개별 종목 OHLCV 조회 (pykrx). start/end는 'YYYYMMDD' 형식."""
    df = pykrx_stock.get_market_ohlcv(start, end, ticker)
    df = df.rename(columns={
        "시가": "open", "고가": "high", "저가": "low",
        "종가": "close", "거래량": "volume",
    })
    return df[["open", "high", "low", "close", "volume"]]


def fetch_index_ohlcv(index_ticker: str, start: str, end: str):
    """지수 OHLCV 조회 (FinanceDataReader). start/end는 'YYYY-MM-DD' 형식.
    index_ticker는 내부 표기인 'KOSPI' 또는 'KOSDAQ'."""
    fdr_code = INDEX_FDR_CODE[index_ticker]
    df = fdr.DataReader(fdr_code, start, end)
    df = df.rename(columns={
        "Open": "open", "High": "high", "Low": "low",
        "Close": "close", "Volume": "volume",
    })
    return df[["open", "high", "low", "close", "volume"]]


def save_price_history(db: Session, ticker: str, df) -> int:
    """DataFrame을 price_history 테이블에 upsert (중복 날짜는 갱신, 신규는 삽입).
    반환값: 저장된 행 수."""
    count = 0
    for date, row in df.iterrows():
        stmt = sqlite_insert(PriceHistory).values(
            ticker=ticker,
            date=date.date() if hasattr(date, "date") else date,
            open=float(row["open"]),
            high=float(row["high"]),
            low=float(row["low"]),
            close=float(row["close"]),
            volume=int(row["volume"]),
        )
        stmt = stmt.on_conflict_do_update(
            index_elements=["ticker", "date"],
            set_={
                "open": stmt.excluded.open,
                "high": stmt.excluded.high,
                "low": stmt.excluded.low,
                "close": stmt.excluded.close,
                "volume": stmt.excluded.volume,
            },
        )
        db.execute(stmt)
        count += 1
    db.commit()
    return count


def ensure_stock_exists(db: Session, ticker: str, name: str, market: str):
    """stocks 테이블에 없으면 등록 (이미 있으면 그대로 둠)."""
    existing = db.query(Stock).filter(Stock.ticker == ticker).first()
    if not existing:
        db.add(Stock(ticker=ticker, name=name, market=market))
        db.commit()