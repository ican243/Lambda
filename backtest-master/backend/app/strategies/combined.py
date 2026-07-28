import pandas as pd
from app.strategies.base import BaseStrategy


class MA20RSIFilteredStrategy(BaseStrategy):
    """MA20 돌파를 매매 신호로 쓰되, RSI로 과열/과냉각 구간을 걸러내는 조합 전략.

    - MA20 상향 돌파 + RSI가 이미 과매수(>= upper)면 매수 신호 무시 (추격매수 방지)
    - MA20 하향 돌파 + RSI가 이미 과매도(<= lower)면 매도 신호 무시 (패닉매도 방지)
    """

    def generate_signals(self, price_df: pd.DataFrame) -> pd.DataFrame:
        ma_period = self.params.get("ma_period", 20)
        rsi_period = self.params.get("rsi_period", 14)
        rsi_upper = self.params.get("rsi_upper", 70)
        rsi_lower = self.params.get("rsi_lower", 30)

        df = price_df.copy()

        # ── MA20 원 신호 계산 ──
        df["ma"] = df["close"].rolling(window=ma_period).mean()
        above = df["close"] > df["ma"]
        prev_above = above.shift(1, fill_value=False)

        raw_signal = pd.Series(0, index=df.index)
        raw_signal.loc[above & ~prev_above] = 1    # 상향 돌파
        raw_signal.loc[~above & prev_above] = -1   # 하향 돌파

        # ── RSI 계산 ──
        df["rsi"] = self._calculate_rsi(df["close"], rsi_period)

        # ── 필터링: 과열/과냉각 구간의 신호는 무효화 ──
        filtered_signal = raw_signal.copy()
        filtered_signal.loc[(raw_signal == 1) & (df["rsi"] >= rsi_upper)] = 0
        filtered_signal.loc[(raw_signal == -1) & (df["rsi"] <= rsi_lower)] = 0

        df["signal"] = filtered_signal
        return df

    @staticmethod
    def _calculate_rsi(close: pd.Series, period: int) -> pd.Series:
        delta = close.diff()
        gain = delta.clip(lower=0)
        loss = -delta.clip(upper=0)

        avg_gain = gain.rolling(window=period).mean()
        avg_loss = loss.rolling(window=period).mean()

        rs = avg_gain / avg_loss
        return 100 - (100 / (1 + rs))