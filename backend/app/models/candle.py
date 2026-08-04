from sqlalchemy import Column, String, DateTime, Integer, BigInteger
from app.database import Base


class Candle1m(Base):
    """팀원의 stock_candles_1m 테이블 매핑 (읽기 전용으로 사용, 스키마 변경 없음)"""
    __tablename__ = "stock_candles_1m"

    stock_code = Column(String(10), primary_key=True)
    ts = Column(DateTime, primary_key=True)
    open_p = Column(Integer, nullable=False)
    high_p = Column(Integer, nullable=False)
    low_p = Column(Integer, nullable=False)
    close_p = Column(Integer, nullable=False)
    volume = Column(BigInteger)
    vol_delta = Column(BigInteger)
    trade_value = Column(BigInteger)
    ticks = Column(Integer)