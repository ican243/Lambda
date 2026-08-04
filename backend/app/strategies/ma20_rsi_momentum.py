import pandas as pd
from app.strategies.base import BaseStrategy


class MA20RSIMomentumFilteredStrategy(BaseStrategy):
    """MA20+RSI 조합 전략에 유니버스 모멘텀 랭킹 필터를 추가.
    price_df에 'momentum_rank_pct' 컬럼이 미리 채워져 있어야 함
    (MomentumRankingService.attach_rank_to_price_df 사용)."""

    def generate_signals(self, price_df: pd.DataFrame) -> pd.DataFrame:
        ma_period = self.params.get("ma_period", 20)
        rsi_period = self.params.get("rsi_period", 14)
        rsi_upper = self.params.get("rsi_upper", 70)
        rsi_lower = self.params.get("rsi_lower", 30)
        momentum_threshold = self.params.get("momentum_rank_threshold", 0.7)  # 상위 30%

        df = price_df.copy()

        if "momentum_rank_pct" not in df.columns:
            raise ValueError(
                "momentum_rank_pct 컬럼이 없습니다. "
                "MomentumRankingService.attach_rank_to_price_df로 먼저 병합하세요."
            )

        df["ma"] = df["close"].rolling(window=ma_period).mean()
        above = df["close"] > df["ma"]
        prev_above = above.shift(1, fill_value=False)

        raw_signal = pd.Series(0, index=df.index)
        raw_signal.loc[above & ~prev_above] = 1
        raw_signal.loc[~above & prev_above] = -1

        df["rsi"] = self._calculate_rsi(df["close"], rsi_period)

        filtered_signal = raw_signal.copy()
        filtered_signal.loc[
            (raw_signal == 1)
            & ((df["rsi"] >= rsi_upper) | (df["momentum_rank_pct"] < momentum_threshold))
        ] = 0
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