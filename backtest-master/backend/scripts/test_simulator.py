from app.database import SessionLocal
from app.models.price_history import PriceHistory
import pandas as pd
from app.strategies.ma20 import MA20Strategy
from app.services.simulator import simulate_trades

db = SessionLocal()
rows = db.query(PriceHistory).filter(PriceHistory.ticker == "005930").all()
df = pd.DataFrame([{
    "date": r.date, "open": r.open, "high": r.high,
    "low": r.low, "close": r.close, "volume": r.volume,
} for r in rows]).set_index("date").sort_index()

strategy = MA20Strategy(params={"period": 5})
result = strategy.generate_signals(df)

trades = simulate_trades(result)
for t in trades:
    print(t)

db.close()