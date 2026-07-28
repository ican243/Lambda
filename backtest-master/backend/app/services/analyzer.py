import numpy as np
import pandas as pd
from app.services.simulator import Trade, FEE_RATE, TAX_RATE

INITIAL_CAPITAL = 10_000_000  # 백테스트 시작 자본 (1천만원 가정)


def build_equity_curve(price_df: pd.DataFrame, trades: list[Trade]) -> pd.Series:
    """일별 자산 가치를 계산. 보유 중이면 종가 기준으로 평가(mark-to-market),
    보유 중이 아니면 현금 그대로."""
    equity = pd.Series(index=price_df.index, dtype=float)
    cash = INITIAL_CAPITAL
    shares = 0
    trade_idx = 0

    for date in price_df.index:
        close = price_df.loc[date, "close"]

        # 오늘이 매수일인 거래가 있으면 진입
        if trade_idx < len(trades) and trades[trade_idx].entry_date == date:
            shares = cash // close
            buy_cost = shares * close * FEE_RATE
            cash -= (shares * close) + buy_cost

        # 오늘이 매도일인 거래가 있으면 청산
        if trade_idx < len(trades) and trades[trade_idx].exit_date == date:
            sell_cost = shares * close * (FEE_RATE + TAX_RATE)
            cash += (shares * close) - sell_cost
            shares = 0
            trade_idx += 1

        equity[date] = cash + shares * close

    return equity

def build_buy_hold_curve(price_df: pd.DataFrame) -> pd.Series:
    """첫날 매수해서 끝까지 들고 있을 때의 일별 자산 가치."""
    start_price = price_df.iloc[0]["close"]
    shares = INITIAL_CAPITAL // start_price
    cash = INITIAL_CAPITAL - shares * start_price
    return price_df["close"].apply(lambda p: cash + shares * p)

def calculate_mdd(equity_curve: pd.Series) -> float:
    """자산 곡선에서 최고점 대비 최대 낙폭(%) 계산."""
    running_max = equity_curve.cummax()
    drawdown = (equity_curve - running_max) / running_max
    return drawdown.min() * 100  # %로 변환, 음수로 반환됨


def calculate_sharpe(equity_curve: pd.Series, risk_free_rate: float = 0.0) -> float:
    """일별 수익률 기반 Sharpe Ratio (연환산)."""
    daily_returns = equity_curve.pct_change().dropna()
    if daily_returns.std() == 0:
        return 0.0

    excess_return = daily_returns.mean() - (risk_free_rate / 252)  # 252 = 연간 거래일 수
    sharpe = excess_return / daily_returns.std()
    return sharpe * np.sqrt(252)  # 연환산


def calculate_win_rate(trades: list[Trade]) -> float:
    """수익 거래 수 / 전체 거래 수 (%)."""
    if not trades:
        return 0.0
    wins = sum(1 for t in trades if t.profit is not None and t.profit > 0)
    return (wins / len(trades)) * 100


def calculate_total_return(equity_curve: pd.Series) -> float:
    """시작 자산 대비 최종 자산의 누적 수익률(%)."""
    start = equity_curve.iloc[0]
    end = equity_curve.iloc[-1]
    return ((end - start) / start) * 100


def analyze_backtest(price_df: pd.DataFrame, trades: list[Trade], index_df: pd.DataFrame = None) -> dict:
    equity_curve = build_equity_curve(price_df, trades)
    buy_hold_curve = build_buy_hold_curve(price_df)  # ← 이 줄 추가

    result = {
        "total_return": calculate_total_return(equity_curve),
        "win_rate": calculate_win_rate(trades),
        "mdd": calculate_mdd(equity_curve),
        "sharpe_ratio": calculate_sharpe(equity_curve),
        "equity_curve": equity_curve,
        "buy_hold_curve": buy_hold_curve,          # ← 이 줄 추가
        "buy_hold_return": calculate_buy_hold_return(price_df),
    }

    if index_df is not None:
        result["benchmark_return"] = calculate_index_return(index_df)
        result["excess_return"] = result["total_return"] - result["benchmark_return"]

    return result

def calculate_buy_hold_return(price_df: pd.DataFrame) -> float:
    """같은 기간 동안 첫날 매수해서 마지막날까지 그냥 들고만 있었을 때의 수익률(%)."""
    start_price = price_df.iloc[0]["close"]
    end_price = price_df.iloc[-1]["close"]
    return ((end_price - start_price) / start_price) * 100


def calculate_index_return(index_df: pd.DataFrame) -> float:
    """같은 기간 동안 지수(코스피 등)에 투자했을 때의 수익률(%).
    index_df는 price_history에서 가져온 지수 데이터 (동일한 기간으로 필터링된 상태여야 함)."""
    start_price = index_df.iloc[0]["close"]
    end_price = index_df.iloc[-1]["close"]
    return ((end_price - start_price) / start_price) * 100