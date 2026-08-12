from sqlalchemy import Column, String, Date, DECIMAL, BigInteger
from app.database import Base


class IndexCandle1d(Base):
    """팀원의 index_candles_1d 테이블 매핑 (읽기 전용으로 사용, 스키마 변경 없음).
    지수 일봉 데이터 - py_index_prices를 완전히 대체.
    index_code: '0001'=코스피, '1001'=코스닥"""
    __tablename__ = "index_candles_1d"

    index_code = Column(String(10), primary_key=True)
    index_name = Column(String(20), nullable=False)
    d = Column(Date, primary_key=True)
    open_p = Column(DECIMAL(10, 2), nullable=False)
    high_p = Column(DECIMAL(10, 2), nullable=False)
    low_p = Column(DECIMAL(10, 2), nullable=False)
    close_p = Column(DECIMAL(10, 2), nullable=False)
    volume = Column(BigInteger, nullable=True)
    trade_value = Column(BigInteger, nullable=True)