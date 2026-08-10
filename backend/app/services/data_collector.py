from datetime import datetime
import FinanceDataReader as fdr
from sqlalchemy.orm import Session
from sqlalchemy.dialects.mysql import insert as mysql_insert

from app.models.stock_master import StockMaster
from app.models.index_price import IndexPrice

# 지수 ticker(내부 표기) -> FinanceDataReader 코드 매핑
INDEX_FDR_CODE = {
    "KOSPI": "KS11",
    "KOSDAQ": "KQ11",
}


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


def save_index_price(db: Session, index_code: str, df) -> int:
    """지수 DataFrame을 py_index_prices 테이블에 upsert (MySQL 방식).
    반환값: 저장된 행 수.
    주의: 팀원이 stock_candles_1d에 지수 데이터를 추가해주면 이 함수는
    더 이상 필요 없어질 수 있음 (요청 완료, 대기 중)."""
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