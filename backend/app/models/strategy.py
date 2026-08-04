from sqlalchemy import Column, Integer, String, JSON
from sqlalchemy.orm import relationship
from app.database import Base


class Strategy(Base):
    __tablename__ = "strategies"

    id = Column(Integer, primary_key=True, autoincrement=True)
    name = Column(String(100), unique=True, nullable=False)  # "MA20", "GOLDEN_CROSS", "RSI"
    params = Column(JSON, nullable=True)  # 기본 파라미터 스펙 (예: {"period": 20})

    backtest_runs = relationship("BacktestRun", back_populates="strategy")