from sqlalchemy import Column, String
from sqlalchemy.orm import relationship
from app.database import Base


class Stock(Base):
    __tablename__ = "stocks"

    ticker = Column(String, primary_key=True, index=True)  # 종목코드 (예: "005930")
    name = Column(String, nullable=False)
    market = Column(String, nullable=False)  # "KOSPI" / "KOSDAQ" / "INDEX"

    price_history = relationship("PriceHistory", back_populates="stock")
    backtest_runs = relationship("BacktestRun", back_populates="stock")