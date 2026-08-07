from datetime import datetime
import FinanceDataReader as fdr
from sqlalchemy.orm import Session
from sqlalchemy.dialects.mysql import insert as mysql_insert

from app.models.price_history import PriceHistory
from app.models.stock_master import StockMaster
from app.models.index_price import IndexPrice

# 지수 ticker(내부 표기) -> FinanceDataReader 코드 매핑
INDEX_FDR_CODE = {
    "KOSPI": "KS11",
    "KOSDAQ": "KQ11",
}


def fetch_stock_ohlcv(ticker: str, start: str, end: str):
    """개별 종목 OHLCV 조회 (FinanceDataReader). start/end는 'YYYYMMDD' 형식.
    KRX 로그인이 필요 없는 fdr로 통일 (pykrx 의존성 제거)."""
    start_fmt = f"{start[:4]}-{start[4:6]}-{start[6:]}"
    end_fmt = f"{end[:4]}-{end[4:6]}-{end[6:]}"
    df = fdr.DataReader(ticker, start_fmt, end_fmt)
    df = df.rename(columns={
        "Open": "open", "High": "high", "Low": "low",
        "Close": "close", "Volume": "volume",
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
    """DataFrame을 price_history 테이블에 upsert (MySQL 방식).
    반환값: 저장된 행 수."""
    count = 0
    for dt, row in df.iterrows():
        row_date = dt.date() if hasattr(dt, "date") else dt
        stmt = mysql_insert(PriceHistory).values(
            ticker=ticker,
            date=row_date,
            open=float(row["open"]),
            high=float(row["high"]),
            low=float(row["low"]),
            close=float(row["close"]),
            volume=int(row["volume"]),
        )
        stmt = stmt.on_duplicate_key_update(
            open=stmt.inserted.open,
            high=stmt.inserted.high,
            low=stmt.inserted.low,
            close=stmt.inserted.close,
            volume=stmt.inserted.volume,
        )
        db.execute(stmt)
        count += 1
    db.commit()
    return count


def save_index_price(db: Session, index_code: str, df) -> int:
    """지수 DataFrame을 py_index_prices 테이블에 upsert (MySQL 방식).
    반환값: 저장된 행 수."""
    count = 0
    for dt, row in df.iterrows():
        row_date = dt.date() if hasattr(dt, "date") else dt
        stmt = mysql_insert(IndexPrice).values(
            index_code=index_code,
            date=row_date,
            open=float(row["open"]),
            high=float(row["high"]),
            low=float(row["low"]),
            close=float(row["close"]),
            volume=float(row["volume"]) if row.get("volume") is not None else None,
        )
        stmt = stmt.on_duplicate_key_update(
            open=stmt.inserted.open,
            high=stmt.inserted.high,
            low=stmt.inserted.low,
            close=stmt.inserted.close,
            volume=stmt.inserted.volume,
        )
        db.execute(stmt)
        count += 1
    db.commit()
    return count


def get_stock_master(db: Session, ticker: str) -> StockMaster | None:
    """팀원이 관리하는 stock_master에서 종목 정보 조회 (읽기 전용).
    여기서 직접 종목을 생성하지 않음 — 없으면 None 반환."""
    return db.query(StockMaster).filter(StockMaster.stock_code == ticker).first()