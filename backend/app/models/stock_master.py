from sqlalchemy import Column, String, DateTime
from app.database import Base


class StockMaster(Base):
    """팀원의 stock_master 테이블 매핑 (읽기 전용으로 사용, 스키마 변경 없음)"""
    __tablename__ = "stock_master"

    stock_code = Column(String(10), primary_key=True)
    stock_name = Column(String(100), nullable=False)
    market = Column(String(10))
    updated_at = Column(DateTime)