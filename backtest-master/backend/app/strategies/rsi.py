import pandas as pd
from app.strategies.base import BaseStrategy


class RSIStrategy(BaseStrategy):
    """RSI가 과매도 구간에서 빠져나오면 매수, 과매수 구간에서 빠져나오면 매도."""

    def generate_signals(self, price_df: pd.DataFrame) -> pd.DataFrame:
        period = self.params.get("period", 14)
        lower = self.params.get("lower", 30)   # 과매도 기준
        upper = self.params.get("upper", 70)   # 과매수 기준
        df = price_df.copy()

        df["rsi"] = self._calculate_rsi(df["close"], period)

        # 과매도(30 미만)였다가 다시 30 위로 올라오는 순간 매수
        # 과매수(70 초과)였다가 다시 70 아래로 내려오는 순간 매도
        was_oversold = (df["rsi"].shift(1) < lower)
        was_overbought = (df["rsi"].shift(1) > upper)

        df["signal"] = 0
        df.loc[was_oversold & (df["rsi"] >= lower), "signal"] = 1
        df.loc[was_overbought & (df["rsi"] <= upper), "signal"] = -1

        return df

    @staticmethod
    def _calculate_rsi(close: pd.Series, period: int) -> pd.Series:
        delta = close.diff()
        gain = delta.clip(lower=0)
        loss = -delta.clip(upper=0)

        avg_gain = gain.rolling(window=period).mean()
        avg_loss = loss.rolling(window=period).mean()

        rs = avg_gain / avg_loss
        rsi = 100 - (100 / (1 + rs))
        return rsi