from sqlalchemy import Column, String, Float, Date
from app.database import Base  # 실제 Base 위치에 맞게 수정


class MomentumRank(Base):
    """일자별 유니버스 전체 모멘�템 퍼센타일 랭킹"""
    __tablename__ = "momentum_rank"

    date = Column(Date, primary_key=True)
    ticker = Column(String, primary_key=True)
    name = Column(String)
    close = Column(Float)
    close_n_days_ago = Column(Float)
    momentum_pct = Column(Float)   # 수익률
    rank_pct = Column(Float)       # 유니버스 내 퍼센타일 (0~1)