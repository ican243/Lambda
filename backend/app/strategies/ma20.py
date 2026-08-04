import pandas as pd
from app.strategies.base import BaseStrategy


class MA20Strategy(BaseStrategy):
    """종가가 N일 이동평균선을 상향 돌파하면 매수, 하향 돌파하면 매도."""

    def generate_signals(self, price_df: pd.DataFrame) -> pd.DataFrame:
        period = self.params.get("period", 20)
        df = price_df.copy()

        df["ma"] = df["close"].rolling(window=period).mean()

        # 전일 종가/MA 대비 오늘 종가/MA 위치가 바뀌는 지점(돌파)을 시그널로 잡음
        above = df["close"] > df["ma"]
        prev_above = above.shift(1)

        df["signal"] = 0
        prev_above = above.shift(1, fill_value=False)

        df.loc[above & ~prev_above, "signal"] = 1   # 상향 돌파 -> 매수
        df.loc[~above & prev_above, "signal"] = -1  # 하향 돌파 -> 매도

        return df