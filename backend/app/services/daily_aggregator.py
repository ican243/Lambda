import pandas as pd
from sqlalchemy.orm import Session
from app.models.candle import Candle1m


def load_daily_price_df(db: Session, ticker: str, start_date, end_date) -> pd.DataFrame:
    """
    stock_candles_1m(1분봉)을 읽어서 일봉 OHLCV DataFrame으로 집계.
    반환 형식: date 인덱스 + open/high/low/close/volume 컬럼 (기존 전략 코드와 호환).
    """
    rows = (
        db.query(Candle1m)
        .filter(
            Candle1m.stock_code == ticker,
            Candle1m.ts >= start_date,
            Candle1m.ts <= end_date,
        )
        .all()
    )
    if not rows:
        return pd.DataFrame()

    df = pd.DataFrame([{
        "ts": r.ts,
        "open": r.open_p,
        "high": r.high_p,
        "low": r.low_p,
        "close": r.close_p,
        "volume": r.vol_delta or 0,
    } for r in rows])

    df["date"] = df["ts"].dt.date

    daily = df.groupby("date").agg(
        open=("open", "first"),
        high=("high", "max"),
        low=("low", "min"),
        close=("close", "last"),
        volume=("volume", "sum"),
    )
    daily.index = pd.to_datetime(daily.index)
    daily = daily.sort_index()

    return daily