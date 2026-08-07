from sqlalchemy import Column, String, Integer, BigInteger, DECIMAL, DateTime
from app.database import Base


class StockLatest(Base):
    """팀원의 stock_latest 테이블 매핑 (읽기 전용으로 사용, 스키마 변경 없음).
    장중 실시간으로 갱신되는 현재가/거래량 스냅샷."""
    __tablename__ = "stock_latest"

    stock_code = Column(String(10), primary_key=True)
    stock_name = Column(String(100), nullable=False)
    price = Column(Integer, nullable=False)
    change_price = Column(Integer, nullable=True)
    change_rate = Column(DECIMAL(5, 2), nullable=True)
    volume = Column(BigInteger, nullable=True)
    updated_at = Column(DateTime, nullable=True)
    trade_value = Column(BigInteger, nullable=True)