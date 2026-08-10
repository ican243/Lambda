from sqlalchemy import Column, String, Date, Integer, BigInteger
from app.database import Base


class StockCandle1d(Base):
    """팀원의 stock_candles_1d 테이블 매핑 (읽기 전용으로 사용, 스키마 변경 없음).
    개별 종목 일봉 데이터 - price_history를 완전히 대체."""
    __tablename__ = "stock_candles_1d"

    stock_code = Column(String(10), primary_key=True)
    d = Column(Date, primary_key=True)
    open_p = Column(Integer, nullable=False)
    high_p = Column(Integer, nullable=False)
    low_p = Column(Integer, nullable=False)
    close_p = Column(Integer, nullable=False)
    volume = Column(BigInteger, nullable=True)
    trade_value = Column(BigInteger, nullable=True)