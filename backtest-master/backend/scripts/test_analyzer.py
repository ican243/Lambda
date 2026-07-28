from app.database import SessionLocal
from app.models.price_history import PriceHistory
import pandas as pd
from app.strategies.ma20 import MA20Strategy
from app.services.simulator import simulate_trades
from app.services.analyzer import analyze_backtest

db = SessionLocal()

# 종목 데이터
rows = db.query(PriceHistory).filter(PriceHistory.ticker == "005930").all()
df = pd.DataFrame([{
    "date": r.date, "open": r.open, "high": r.high,
    "low": r.low, "close": r.close, "volume": r.volume,
} for r in rows]).set_index("date").sort_index()

# 코스피 지수 데이터 (이전에 수집해둔 것)
index_rows = db.query(PriceHistory).filter(PriceHistory.ticker == "KOSPI").all()
index_df = pd.DataFrame([{
    "date": r.date, "close": r.close,
} for r in index_rows]).set_index("date").sort_index()

strategy = MA20Strategy(params={"period": 5})
result = strategy.generate_signals(df)
trades = simulate_trades(result)

analysis = analyze_backtest(df, trades, index_df=index_df)

print("총 수익률(전략):", round(analysis["total_return"], 2), "%")
print("매수후보유 수익률:", round(analysis["buy_hold_return"], 2), "%")
print("코스피 지수 수익률:", round(analysis["benchmark_return"], 2), "%")
print("초과수익(전략-지수):", round(analysis["excess_return"], 2), "%p")
print("승률:", analysis["win_rate"], "%")
print("MDD:", round(analysis["mdd"], 2), "%")
print("Sharpe:", round(analysis["sharpe_ratio"], 2))

db.close()