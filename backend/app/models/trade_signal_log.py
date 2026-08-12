from sqlalchemy import Column, Integer, String, Float, DateTime, func
from app.database import Base


class TradeSignalLog(Base):
    """매매 판단 시점의 팩터 스코어 기록.
    실제 매매(buy/sell)가 안 일어난 경우(hold/skip)도 포함해서 남겨야
    나중에 buy_threshold/sell_threshold 튜닝 시 실제 점수 분포를 볼 수 있음."""
    __tablename__ = "py_trade_signal_log"

    id = Column(Integer, primary_key=True, autoincrement=True)
    ticker = Column(String(10), nullable=False, index=True)
    strategy_name = Column(String(50), nullable=False, index=True)
    score = Column(Float, nullable=True)  # 점수 계산 전 단계(데이터 부족 등)면 NULL
    action = Column(String(10), nullable=False)  # buy / sell / hold / skip
    reason = Column(String(50), nullable=True)
    created_at = Column(DateTime, server_default=func.now(), index=True)