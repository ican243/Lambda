from app.database import SessionLocal
from app.models.price_history import PriceHistory
import pandas as pd
from app.strategies.ma20 import MA20Strategy
from app.strategies.golden_cross import GoldenCrossStrategy
from app.strategies.rsi import RSIStrategy

# 1. 먼저 DB에서 데이터 가져와서 df 만들기
db = SessionLocal()
rows = db.query(PriceHistory).filter(PriceHistory.ticker == "005930").all()
df = pd.DataFrame([{
    "date": r.date, "open": r.open, "high": r.high,
    "low": r.low, "close": r.close, "volume": r.volume,
} for r in rows]).set_index("date").sort_index()

strategy = MA20Strategy(params={"period": 5})  # 데이터가 17건뿐이니 테스트는 5일로
result = strategy.generate_signals(df)
print(result[["close", "ma", "signal"]])

gc = GoldenCrossStrategy(params={"short_period": 3, "long_period": 5})
print(gc.generate_signals(df)[["close", "ma_short", "ma_long", "signal"]])

rsi = RSIStrategy(params={"period": 5})
print(rsi.generate_signals(df)[["close", "rsi", "signal"]])


db.close()