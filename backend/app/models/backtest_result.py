from sqlalchemy import Column, Integer, Float, ForeignKey
from sqlalchemy.orm import relationship
from app.database import Base

class BacktestResult(Base):
    __tablename__ = "backtest_results"

    id = Column(Integer, primary_key=True, autoincrement=True)
    run_id = Column(Integer, ForeignKey("backtest_runs.id"), unique=True, nullable=False)
    total_return = Column(Float, nullable=False)
    win_rate = Column(Float, nullable=False)
    mdd = Column(Float, nullable=False)
    sharpe_ratio = Column(Float, nullable=False)
    benchmark_return = Column(Float, nullable=True)   # 지수 대비
    buy_hold_return = Column(Float, nullable=True)    # 단순 매수후보유 대비

    run = relationship("BacktestRun", back_populates="result")