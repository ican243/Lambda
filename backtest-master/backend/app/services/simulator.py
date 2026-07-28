from dataclasses import dataclass, field
import pandas as pd


@dataclass
class Trade:
    entry_date: object
    entry_price: float
    exit_date: object = None
    exit_price: float = None
    profit: float = None
    cost: float = None
    exit_reason: str = None  # "signal", "stop_loss", "take_profit", "force_close"


TAX_RATE = 0.0018   # 매도 시 거래세 (코스피/코스닥 공통 가정)
FEE_RATE = 0.00015  # 매수/매도 시 수수료 (가정값, 증권사별로 다름)


def simulate_trades(
    signal_df: pd.DataFrame,
    stop_loss_pct: float | None = None,
    take_profit_pct: float | None = None,
) -> list[Trade]:
    """signal 컬럼(1=매수, -1=매도, 0=유지)을 보고 실제 매매를 시뮬레이션.
    한 번에 1개 포지션만 보유하는 것으로 가정 (추가매수 없음).

    stop_loss_pct: 진입가 대비 이 비율만큼 하락하면 신호와 무관하게 강제 청산 (예: 0.08 = -8%)
    take_profit_pct: 진입가 대비 이 비율만큼 상승하면 신호와 무관하게 강제 청산 (예: 0.20 = +20%)
    둘 다 None이면 기존과 동일하게 signal 컬럼에만 의존."""

    trades: list[Trade] = []
    holding = False
    current_trade: Trade | None = None

    for date, row in signal_df.iterrows():
        signal = row["signal"]
        price = row["close"]

        # ── 보유 중이면 손절/익절 라인부터 체크 (signal보다 우선) ──
        if holding and current_trade is not None:
            entry_price = current_trade.entry_price
            change_pct = (price - entry_price) / entry_price

            forced_exit_reason = None
            if stop_loss_pct is not None and change_pct <= -stop_loss_pct:
                forced_exit_reason = "stop_loss"
            elif take_profit_pct is not None and change_pct >= take_profit_pct:
                forced_exit_reason = "take_profit"

            if forced_exit_reason is not None:
                current_trade.exit_date = date
                current_trade.exit_price = price
                current_trade.exit_reason = forced_exit_reason
                current_trade.profit, current_trade.cost = _calculate_profit(
                    entry_price, price
                )
                trades.append(current_trade)
                current_trade = None
                holding = False
                continue  # 같은 날 재진입은 하지 않음

        if signal == 1 and not holding:
            current_trade = Trade(entry_date=date, entry_price=price)
            holding = True

        elif signal == -1 and holding:
            current_trade.exit_date = date
            current_trade.exit_price = price
            current_trade.exit_reason = "signal"
            current_trade.profit, current_trade.cost = _calculate_profit(
                current_trade.entry_price, price
            )
            trades.append(current_trade)
            current_trade = None
            holding = False

    # 기간 종료 시점까지 미청산 포지션이 있으면 마지막 종가로 강제 청산
    if holding and current_trade is not None:
        last_date = signal_df.index[-1]
        last_price = signal_df.iloc[-1]["close"]
        current_trade.exit_date = last_date
        current_trade.exit_price = last_price
        current_trade.exit_reason = "force_close"
        current_trade.profit, current_trade.cost = _calculate_profit(
            current_trade.entry_price, last_price
        )
        trades.append(current_trade)

    return trades


def _calculate_profit(entry_price: float, exit_price: float) -> tuple[float, float]:
    """거래비용(매수 수수료 + 매도 수수료 + 매도세금) 반영한 실현 손익 계산."""
    buy_cost = entry_price * FEE_RATE
    sell_cost = exit_price * (FEE_RATE + TAX_RATE)
    total_cost = buy_cost + sell_cost

    gross_profit = exit_price - entry_price
    net_profit = gross_profit - total_cost

    return net_profit, total_cost