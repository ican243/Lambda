import pandas as pd
from app.strategies.base import BaseStrategy


class GoldenCrossStrategy(BaseStrategy):
    """단기 이동평균이 장기 이동평균을 상향 돌파하면 매수(골든크로스),
    하향 돌파하면 매도(데드크로스)."""

    def generate_signals(self, price_df: pd.DataFrame) -> pd.DataFrame:
        short_period = self.params.get("short_period", 5)
        long_period = self.params.get("long_period", 20)
        df = price_df.copy()

        df["ma_short"] = df["close"].rolling(window=short_period).mean()
        df["ma_long"] = df["close"].rolling(window=long_period).mean()

        above = df["ma_short"] > df["ma_long"]
        prev_above = above.shift(1, fill_value=False)

        df["signal"] = 0
        df.loc[above & ~prev_above, "signal"] = 1    # 골든크로스 -> 매수
        df.loc[~above & prev_above, "signal"] = -1   # 데드크로스 -> 매도

        return df