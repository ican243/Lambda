from sqlalchemy import Column, Integer, String, Date, DateTime, ForeignKey, JSON
from sqlalchemy.orm import relationship
from sqlalchemy.sql import func
from app.database import Base


class BacktestRun(Base):
    __tablename__ = "backtest_runs"

    id = Column(Integer, primary_key=True, autoincrement=True)
    ticker = Column(String(10), ForeignKey("stocks.ticker"), nullable=False)
    strategy_id = Column(Integer, ForeignKey("strategies.id"), nullable=False)
    params = Column(JSON, nullable=True)  # 이 실행에서 사용한 실제 파라미터 (전략 기본값을 덮어쓸 수 있음)
    start_date = Column(Date, nullable=False)
    end_date = Column(Date, nullable=False)
    created_at = Column(DateTime, server_default=func.now())

    stock = relationship("Stock", back_populates="backtest_runs")
    strategy = relationship("Strategy", back_populates="backtest_runs")
    result = relationship("BacktestResult", back_populates="run", uselist=False)
    trades = relationship("Trade", back_populates="run")