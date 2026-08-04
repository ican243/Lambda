from sqlalchemy import Column, Integer, Date, Float, ForeignKey
from sqlalchemy.orm import relationship
from app.database import Base


class Trade(Base):
    __tablename__ = "trades"

    id = Column(Integer, primary_key=True, autoincrement=True)
    run_id = Column(Integer, ForeignKey("backtest_runs.id"), nullable=False, index=True)
    entry_date = Column(Date, nullable=False)
    entry_price = Column(Float, nullable=False)
    exit_date = Column(Date, nullable=True)   # 미청산 시 null 가능 (단, 시뮬레이션 종료 시 강제청산 로직으로 처리)
    exit_price = Column(Float, nullable=True)
    profit = Column(Float, nullable=True)     # 거래비용 반영된 실현 손익
    cost = Column(Float, nullable=True)       # 거래비용(세금+수수료) 합계

    run = relationship("BacktestRun", back_populates="trades")
    