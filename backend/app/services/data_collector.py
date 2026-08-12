from app.models.stock_master import StockMaster
from sqlalchemy.orm import Session


def get_stock_master(db: Session, ticker: str) -> StockMaster | None:
    """팀원이 관리하는 stock_master에서 종목 정보 조회 (읽기 전용).
    여기서 직접 종목을 생성하지 않음 - 없으면 None 반환."""
    return db.query(StockMaster).filter(StockMaster.stock_code == ticker).first()