from itertools import product
from app.strategies.ma20 import MA20Strategy
from app.strategies.golden_cross import GoldenCrossStrategy
from app.strategies.rsi import RSIStrategy
from app.strategies.configurable_factor import ConfigurableFactorStrategy
from app.services.simulator import simulate_trades
from app.services.analyzer import analyze_backtest
import pandas as pd

STRATEGY_MAP = {
    "MA20": MA20Strategy,
    "GOLDEN_CROSS": GoldenCrossStrategy,
    "RSI": RSIStrategy,
    "CONFIGURABLE_FACTOR": ConfigurableFactorStrategy,
}


def generate_param_combinations(param_grid: dict) -> list[dict]:
    """파라미터 그리드에서 모든 조합을 생성.
    예: {"period": [5, 10, 20], "upper": [70, 80]}
    → [{"period":5,"upper":70}, {"period":5,"upper":80}, ...]
    """
    keys = list(param_grid.keys())
    values = list(param_grid.values())
    combinations = []
    for combo in product(*values):
        combinations.append(dict(zip(keys, combo)))
    return combinations


def run_grid_search(
    strategy_name: str,
    param_grid: dict,
    price_df: pd.DataFrame,
    index_df: pd.DataFrame | None = None,
    sort_by: str = "sharpe_ratio",
) -> list[dict]:
    """
    파라미터 그리드 전체를 순회하며 백테스트 실행.
    sort_by: "sharpe_ratio" | "total_return" | "mdd" | "win_rate"
    """
    strategy_cls = STRATEGY_MAP.get(strategy_name)
    if strategy_cls is None:
        raise ValueError(f"알 수 없는 전략: {strategy_name}")

    combinations = generate_param_combinations(param_grid)
    results = []

    for params in combinations:
        try:
            strategy = strategy_cls(params=params)
            signal_df = strategy.generate_signals(price_df)
            trades = simulate_trades(signal_df)

            # 거래가 한 건도 없으면 skip
            if not trades:
                continue

            analysis = analyze_backtest(price_df, trades, index_df=index_df)

            results.append({
                "params": params,
                "total_return": analysis["total_return"],
                "win_rate": analysis["win_rate"],
                "mdd": analysis["mdd"],
                "sharpe_ratio": analysis["sharpe_ratio"],
                "excess_return": analysis.get("excess_return"),
            })
        except Exception:
            continue  # 파라미터 조합이 계산 불가한 경우 skip

    # 정렬: MDD는 낮을수록(0에 가까울수록) 좋으니 역순 정렬
    reverse = sort_by != "mdd"
    results.sort(key=lambda x: x[sort_by], reverse=reverse)

    return results