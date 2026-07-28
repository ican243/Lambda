import pandas as pd
from datetime import datetime
from sqlalchemy.orm import Session

from app.models.momentum_rank import MomentumRank
from app.models.price_history import PriceHistory

# 랭킹 대상에서 제외할 티커 (개별 종목이 아닌 지수)
INDEX_TICKERS = {"KOSPI", "KOSDAQ"}


class MomentumRankingService:
    def __init__(self, momentum_period: int = 20):
        self.momentum_period = momentum_period

    def _fetch_snapshot_from_db(self, date_str: str, db: Session) -> pd.DataFrame:
        """DB의 PriceHistory에서 해당 날짜의 전 종목(지수 제외) 종가 스냅샷 조회."""
        target_date = datetime.strptime(date_str, "%Y%m%d").date()
        rows = (
            db.query(PriceHistory.ticker, PriceHistory.close)
            .filter(
                PriceHistory.date == target_date,
                ~PriceHistory.ticker.in_(INDEX_TICKERS),
            )
            .all()
        )
        return pd.DataFrame(rows, columns=["ticker", "close"])

    def calculate_for_date(
        self, base_date: str, trading_days: list[str], db: Session
    ) -> pd.DataFrame:
        if base_date not in trading_days:
            raise ValueError(f"{base_date}는 거래일 목록에 없습니다.")

        idx = trading_days.index(base_date)
        if idx < self.momentum_period:
            raise ValueError(
                f"거래일 데이터 부족: {base_date} 기준 과거 {self.momentum_period}일치 없음 "
                f"(현재 {idx}일치만 존재)"
            )
        past_date = trading_days[idx - self.momentum_period]

        current = self._fetch_snapshot_from_db(base_date, db)
        past = self._fetch_snapshot_from_db(past_date, db)

        if current.empty or past.empty:
            return pd.DataFrame()

        merged = current.merge(past, on="ticker", suffixes=("_current", "_past"))
        merged = merged[(merged["close_past"] > 0) & (merged["close_current"] > 0)]

        if merged.empty:
            return pd.DataFrame()

        merged["momentum_pct"] = (
            merged["close_current"] - merged["close_past"]
        ) / merged["close_past"]
        merged["rank_pct"] = merged["momentum_pct"].rank(pct=True)
        merged["date"] = datetime.strptime(base_date, "%Y%m%d").date()

        merged = merged.rename(
            columns={"close_current": "close", "close_past": "close_n_days_ago"}
        )
        return merged[
            ["date", "ticker", "close", "close_n_days_ago", "momentum_pct", "rank_pct"]
        ]

    def save(self, df: pd.DataFrame, db: Session):
        if df.empty:
            return
        target_date = df["date"].iloc[0]
        db.query(MomentumRank).filter(MomentumRank.date == target_date).delete()
        db.bulk_insert_mappings(MomentumRank, df.to_dict(orient="records"))
        db.commit()

    def run(self, base_date: str, trading_days: list[str], db: Session) -> pd.DataFrame:
        df = self.calculate_for_date(base_date, trading_days, db)
        self.save(df, db)
        return df

    def attach_rank_to_price_df(
        self, price_df: pd.DataFrame, ticker: str, db: Session
    ) -> pd.DataFrame:
        df = price_df.copy()
        rows = (
            db.query(MomentumRank.date, MomentumRank.rank_pct)
            .filter(MomentumRank.ticker == ticker)
            .all()
        )
        if not rows:
            df["momentum_rank_pct"] = None
            return df

        rank_df = pd.DataFrame(rows, columns=["date", "rank_pct"]).set_index("date")
        df["momentum_rank_pct"] = df.index.map(rank_df["rank_pct"])
        return df
    
    def attach_market_regime(
        self, price_df: pd.DataFrame, kospi_df: pd.DataFrame
    ) -> pd.DataFrame:
        """price_df에 코스피 MA200 상회 여부(시장 국면)를 붙여서 반환."""
        df = price_df.copy()
        kospi = kospi_df.copy()
        kospi["kospi_ma200"] = kospi["close"].rolling(window=200).mean()
        kospi["market_bullish"] = kospi["close"] > kospi["kospi_ma200"]
        df["market_bullish"] = df.index.map(kospi["market_bullish"])
        return df