from sqlalchemy import Column, Integer, String, DateTime
from datetime import datetime
from app.database import Base


class LiveOrder(Base):
    __tablename__ = "live_orders"

    id = Column(Integer, primary_key=True, index=True)
    ticker = Column(String(10), nullable=False)
    order_type = Column(String(10), nullable=False)      # buy / sell
    quantity = Column(Integer, nullable=False)
    price = Column(Integer, nullable=False)               # 0이면 시장가
    strategy_name = Column(String(100), nullable=True)     # 어떤 전략이 낸 신호인지

    kis_order_no = Column(String(50), nullable=True)
    status = Column(String(20), default="pending")         # pending/filled/partial/failed
    filled_qty = Column(Integer, default=0)
    filled_price = Column(Integer, nullable=True)

    created_at = Column(DateTime, default=datetime.utcnow)
    updated_at = Column(DateTime, default=datetime.utcnow, onupdate=datetime.utcnow)
    reason = Column(String(50), nullable=True)  # strategy_signal / stop_loss / take_profit