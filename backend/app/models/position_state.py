from datetime import datetime

from sqlalchemy import Column, DateTime, Float, Integer, String, UniqueConstraint

from app.database import Base


class PositionState(Base):
    """전략별 종목 포지션과 trailing/time-exit 상태."""

    __tablename__ = "position_states"
    __table_args__ = (
        UniqueConstraint("ticker", "strategy_name", name="uq_position_state_ticker_strategy"),
    )

    id = Column(Integer, primary_key=True, autoincrement=True)
    ticker = Column(String(10), nullable=False, index=True)
    strategy_name = Column(String(100), nullable=False, index=True)
    quantity = Column(Integer, nullable=False, default=0)
    avg_buy_price = Column(Float, nullable=True)
    highest_price = Column(Float, nullable=True)
    trailing_active = Column(Integer, nullable=False, default=0)
    partial_profit_taken = Column(Integer, nullable=False, default=0)
    first_buy_at = Column(DateTime, nullable=True)
    updated_at = Column(DateTime, default=datetime.utcnow, onupdate=datetime.utcnow)
