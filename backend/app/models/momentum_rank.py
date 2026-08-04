from sqlalchemy import Column, String, Float, Date
from app.database import Base


class MomentumRank(Base):
    """일자별 유니버스 전체 모멘텀 퍼센타일 랭킹"""
    __tablename__ = "py_momentum_rank"  # 기존: "momentum_rank"

    date = Column(Date, primary_key=True)
    ticker = Column(String(10), primary_key=True)
    close = Column(Float)
    close_n_days_ago = Column(Float)
    momentum_pct = Column(Float)
    rank_pct = Column(Float)