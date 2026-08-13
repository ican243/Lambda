from sqlalchemy import Column, Integer, String, Float, DateTime, func
from app.database import Base


class TradeSignalLog(Base):
    """의미 있는 매매 판단 이벤트를 보존하는 로그."""

    __tablename__ = "py_trade_signal_log"

    id = Column(Integer, primary_key=True, autoincrement=True)
    ticker = Column(String(10), nullable=False, index=True)
    strategy_name = Column(String(50), nullable=False, index=True)
    score = Column(Float, nullable=True)
    signal = Column(Integer, nullable=True)  # -1 / 0 / 1
    action = Column(String(10), nullable=False)  # buy / sell / hold / skip
    reason = Column(String(50), nullable=True)
    event_type = Column(String(30), nullable=True, index=True)
    source = Column(String(20), nullable=False, default="scheduler", index=True)
    evaluated_at = Column(DateTime, nullable=True)
    created_at = Column(DateTime, server_default=func.now(), index=True)
