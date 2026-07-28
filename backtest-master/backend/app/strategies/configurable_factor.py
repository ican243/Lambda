import pandas as pd
from app.strategies.base import BaseStrategy
from app.strategies.factors import FACTOR_REGISTRY


class ConfigurableFactorStrategy(BaseStrategy):
    """사용자가 선택한 지표들만 조합해서 점수를 계산하는 전략.

    params 예시:
    {
        "indicators": ["trend", "momentum", "volume"],
        "weights": {"trend": 40, "momentum": 40, "volume": 20},
        "buy_threshold": 60,
        "sell_threshold": 30,
        "stop_loss_pct": 0.08,     # 라우터에서 simulate_trades로 전달, 여기선 안 씀
        "take_profit_pct": 0.20,   # 동일
        ... (각 지표별 세부 파라미터도 함께 전달 가능, 예: "ma_short": 20)
    }
    """

    def generate_signals(self, price_df: pd.DataFrame) -> pd.DataFrame:
        indicators = self.params.get("indicators", ["trend", "momentum"])
        weights = self.params.get("weights", {})
        buy_threshold = self.params.get("buy_threshold", 60)
        sell_threshold = self.params.get("sell_threshold", 30)

        if not indicators:
            raise ValueError("최소 1개 이상의 지표를 선택해야 합니다.")

        unknown = [k for k in indicators if k not in FACTOR_REGISTRY]
        if unknown:
            raise ValueError(f"알 수 없는 지표: {unknown}")

        df = price_df.copy()
        total_score = pd.Series(0.0, index=df.index)

        for key in indicators:
            factor = FACTOR_REGISTRY[key]
            weight = weights.get(key, factor["default_weight"])
            factor_score = factor["compute"](df, self.params)
            total_score = total_score.add(factor_score * weight, fill_value=0)

        df["score"] = total_score
        df["signal"] = 0
        df.loc[df["score"] >= buy_threshold, "signal"] = 1
        df.loc[df["score"] <= sell_threshold, "signal"] = -1

        return df