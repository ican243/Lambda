from sqlalchemy import Column, String, Date, Float, Integer, UniqueConstraint
from app.database import Base


class PriceHistory(Base):
    __tablename__ = "price_history"

    id = Column(Integer, primary_key=True, autoincrement=True)
    # stock_master(팀원 관리 테이블)를 참조하는 종목코드. FK 제약은 걸지 않음
    # (팀원 스키마 변경에 영향받지 않기 위함). 유효성 검사는 애플리케이션 레벨에서 수행.
    ticker = Column(String(10), nullable=False, index=True)
    date = Column(Date, nullable=False, index=True)
    open = Column(Float, nullable=False)
    high = Column(Float, nullable=False)
    low = Column(Float, nullable=False)
    close = Column(Float, nullable=False)
    volume = Column(Integer, nullable=False)

    __table_args__ = (
        UniqueConstraint("ticker", "date", name="uq_ticker_date"),
    )