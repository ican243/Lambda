from sqlalchemy import Column, Integer, String, Boolean, Float, JSON, DateTime, func
from app.database import Base


class UserStrategySettings(Base):
    """유저별 자동매매 전략 설정"""
    __tablename__ = "py_user_strategy_settings"

    id = Column(Integer, primary_key=True, autoincrement=True)
    user_id = Column(Integer, nullable=False, unique=True, index=True)

    indicators = Column(JSON, nullable=False)          # ["trend", "momentum", ...]
    weights = Column(JSON, nullable=False)              # {"trend": 40, "momentum": 30, ...}
    buy_threshold = Column(Float, default=60)
    sell_threshold = Column(Float, default=30)
    stop_loss_pct = Column(Float, default=0.08)
    take_profit_pct = Column(Float, default=0.20)
    max_positions = Column(Integer, default=5)
    position_size_pct = Column(Float, default=0.05)

    is_active = Column(Boolean, default=False)          # 자동매매 켜짐/꺼짐

    created_at = Column(DateTime, server_default=func.now())
    updated_at = Column(DateTime, server_default=func.now(), onupdate=func.now())