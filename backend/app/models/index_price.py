from sqlalchemy import Column, String, Date, Float
from app.database import Base


class IndexPrice(Base):
    """벤치마크 비교용 지수(KOSPI/KOSDAQ 등) 일별 시세.
    팀원 스키마와 무관한 독립 테이블이라 안전하게 관리 가능."""
    __tablename__ = "py_index_prices"

    index_code = Column(String(10), primary_key=True)  # "KOSPI", "KOSDAQ" 등
    date = Column(Date, primary_key=True)
    open = Column(Float, nullable=False)
    high = Column(Float, nullable=False)
    low = Column(Float, nullable=False)
    close = Column(Float, nullable=False)
    volume = Column(Float, nullable=True)