import pandas as pd
from dataclasses import dataclass

TAX_RATE = 0.0018
FEE_RATE = 0.00015


@dataclass
class Position:
    ticker: str
    entry_date: object
    entry_price: float
    shares: float
    invested_amount: float


@dataclass
class ClosedTrade:
    ticker: str
    entry_date: object
    entry_price: float
    exit_date: object
    exit_price: float
    shares: float
    profit: float
    exit_reason: str  # "signal", "stop_loss", "take_profit", "force_close"


class PortfolioSimulator:
    def __init__(
        self,
        total_capital: float,
        max_positions: int = 5,
        position_size_pct: float = 0.05,   # 종목당 투입 비율 (총자본 대비)
        stop_loss_pct: float = 0.08,
        take_profit_pct: float = 0.20,
    ):
        self.total_capital = total_capital
        self.max_positions = max_positions
        self.position_size_pct = position_size_pct
        self.stop_loss_pct = stop_loss_pct
        self.take_profit_pct = take_profit_pct

        self.cash = total_capital
        self.positions: dict[str, Position] = {}
        self.closed_trades: list[ClosedTrade] = []
        self.equity_history: list[dict] = []

    def _current_total_value(self, score_data: dict[str, pd.DataFrame], date) -> float:
        holdings_value = sum(
            pos.shares * score_data[t].loc[date, "close"]
            for t, pos in self.positions.items()
            if date in score_data[t].index
        )
        return self.cash + holdings_value

    def _check_exit(self, ticker: str, price: float, score: float, sell_threshold: float) -> str | None:
        pos = self.positions[ticker]
        change_pct = (price - pos.entry_price) / pos.entry_price

        if change_pct <= -self.stop_loss_pct:
            return "stop_loss"
        if change_pct >= self.take_profit_pct:
            return "take_profit"
        if score <= sell_threshold:
            return "signal"
        return None

    def _close_position(self, ticker: str, date, price: float, reason: str):
        pos = self.positions.pop(ticker)
        gross = pos.shares * price
        sell_cost = gross * (FEE_RATE + TAX_RATE)
        buy_side_cost = pos.invested_amount * FEE_RATE  # 매수 시 이미 낸 수수료는 invested_amount에 반영 안 했으므로 여기서 근사
        proceeds = gross - sell_cost
        profit = proceeds - pos.invested_amount

        self.cash += proceeds
        self.closed_trades.append(ClosedTrade(
            ticker=ticker, entry_date=pos.entry_date, entry_price=pos.entry_price,
            exit_date=date, exit_price=price, shares=pos.shares,
            profit=profit, exit_reason=reason,
        ))

    def _open_position(self, ticker: str, date, price: float, current_total_value: float):
        target_amount = current_total_value * self.position_size_pct
        amount = min(target_amount, self.cash)
        if amount <= 0:
            return
        buy_cost = amount * FEE_RATE
        shares = (amount - buy_cost) / price

        self.cash -= amount
        self.positions[ticker] = Position(
            ticker=ticker, entry_date=date, entry_price=price,
            shares=shares, invested_amount=amount,
        )

    def run(
        self,
        score_data: dict[str, pd.DataFrame],
        buy_threshold: float,
        sell_threshold: float,
    ):
        all_dates = sorted(set().union(*[df.index for df in score_data.values()]))

        for date in all_dates:
            # 1. 보유 포지션 청산 체크
            for ticker in list(self.positions.keys()):
                df = score_data.get(ticker)
                if df is None or date not in df.index:
                    continue
                row = df.loc[date]
                reason = self._check_exit(ticker, row["close"], row["score"], sell_threshold)
                if reason:
                    self._close_position(ticker, date, row["close"], reason)

            # 2. 빈 자리만큼 신규 후보 탐색 (현재 총자본 기준으로 투입 금액 계산)
            free_slots = self.max_positions - len(self.positions)
            if free_slots > 0:
                current_total_value = self._current_total_value(score_data, date)

                candidates = []
                for ticker, df in score_data.items():
                    if ticker in self.positions or date not in df.index:
                        continue
                    row = df.loc[date]
                    if row["score"] >= buy_threshold:
                        candidates.append((ticker, row["score"], row["close"]))

                candidates.sort(key=lambda x: x[1], reverse=True)
                for ticker, score, price in candidates[:free_slots]:
                    self._open_position(ticker, date, price, current_total_value)

            # 3. 자산 가치 기록
            total_value = self._current_total_value(score_data, date)
            self.equity_history.append({
                "date": date,
                "total_value": total_value,
                "cash": self.cash,
                "position_count": len(self.positions),
            })

        return {
            "closed_trades": self.closed_trades,
            "open_positions": list(self.positions.values()),
            "equity_history": self.equity_history,
        }