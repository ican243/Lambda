from sqlalchemy import Column, String
from sqlalchemy.orm import relationship
from app.database import Base


class Stock(Base):
    __tablename__ = "stocks"

    ticker = Column(String(10), primary_key=True, index=True)
    name = Column(String(100), nullable=False)
    market = Column(String(10), nullable=False)  # "KOSPI" / "KOSDAQ" / "INDEX"

    price_history = relationship("PriceHistory", back_populates="stock")
    backtest_runs = relationship("BacktestRun", back_populates="stock")