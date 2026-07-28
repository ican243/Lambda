"""
여러 종목에 대해 동일 기간/전략으로 백테스트를 돌려서 결과를 비교하는 스크립트.

사용법:
    python scripts/multi_ticker_backtest.py
"""

import sys
sys.path.append(".")

import pandas as pd
from app.database import SessionLocal
from app.models.price_history import PriceHistory
from app.strategies.ma20_rsi_momentum import MA20RSIMomentumFilteredStrategy
from app.services.momentum_ranking import MomentumRankingService
from app.services.simulator import simulate_trades
from app.services.analyzer import analyze_backtest

TICKERS = [
    "000660", "005380", "005930", "006400", "035420",
    "035720", "051910", "068270", "105560", "207940",
]

START_DATE = "2026-03-01"
END_DATE = "2026-07-24"


def load_price_df(db, ticker, start_date, end_date):
    rows = (
        db.query(PriceHistory)
        .filter(
            PriceHistory.ticker == ticker,
            PriceHistory.date >= start_date,
            PriceHistory.date <= end_date,
        )
        .all()
    )
    if not rows:
        return None
    df = pd.DataFrame([{
        "date": r.date, "open": r.open, "high": r.high,
        "low": r.low, "close": r.close, "volume": r.volume,
    } for r in rows]).set_index("date").sort_index()
    return df


def run_for_ticker(ticker: str, db, index_df) -> dict:
    price_df = load_price_df(db, ticker, START_DATE, END_DATE)
    if price_df is None:
        return {"ticker": ticker, "error": "데이터 없음"}

    service = MomentumRankingService()
    price_df_with_momentum = service.attach_rank_to_price_df(price_df, ticker, db)

    strategy = MA20RSIMomentumFilteredStrategy()
    signal_df = strategy.generate_signals(price_df_with_momentum)
    trades = simulate_trades(signal_df)
    analysis = analyze_backtest(price_df, trades, index_df=index_df)

    return {
        "ticker": ticker,
        "trade_count": len(trades),
        "total_return": round(analysis["total_return"], 2),
        "buy_hold_return": round(analysis["buy_hold_return"], 2),
        "excess_return": round(analysis.get("excess_return", 0) or 0, 2),
        "win_rate": round(analysis["win_rate"], 2),
        "mdd": round(analysis["mdd"], 2),
        "sharpe_ratio": round(analysis["sharpe_ratio"], 2),
    }


def main():
    db = SessionLocal()
    try:
        index_df = load_price_df(db, "KOSPI", START_DATE, END_DATE)

        results = []
        for ticker in TICKERS:
            try:
                result = run_for_ticker(ticker, db, index_df)
            except Exception as e:
                result = {"ticker": ticker, "error": str(e)}
            results.append(result)
            print(f"완료: {ticker}")

    finally:
        db.close()

    print("\n" + "=" * 90)
    df = pd.DataFrame(results)
    print(df.to_string(index=False))
    print("=" * 90)

    if "excess_return" in df.columns:
        avg_excess = df["excess_return"].mean()
        win_count = (df["excess_return"] > 0).sum()
        print(f"\n평균 초과수익률: {avg_excess:.2f}%")
        print(f"벤치마크 초과 종목 수: {win_count} / {len(df)}")


if __name__ == "__main__":
    main()