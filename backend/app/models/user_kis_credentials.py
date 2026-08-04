from sqlalchemy import Column, Integer, String, Boolean, DateTime, func
from app.database import Base


class UserKisCredentials(Base):
    """유저별 KIS 계좌 연동 정보. user_id는 팀원 users 테이블의 id를 참조하지만,
    실제 FK 제약은 걸지 않음 (팀원 스키마를 건드리지 않기 위함)."""
    __tablename__ = "py_user_kis_credentials"

    id = Column(Integer, primary_key=True, autoincrement=True)
    user_id = Column(Integer, nullable=False, unique=True, index=True)

    kis_app_key_encrypted = Column(String(500), nullable=False)
    kis_app_secret_encrypted = Column(String(500), nullable=False)
    kis_account_no = Column(String(20), nullable=False)
    kis_account_product_cd = Column(String(5), default="01")
    is_mock = Column(Boolean, default=True)

    created_at = Column(DateTime, server_default=func.now())
    updated_at = Column(DateTime, server_default=func.now(), onupdate=func.now())