from sqlalchemy.orm import Session
from sqlalchemy import desc
from app.models.stock_latest import StockLatest


def get_top_candidates_by_volume(db: Session, limit: int = 100) -> list[str]:
    """장중 거래량 기준 상위 N개 종목코드 반환 (1차 스크리닝, 가벼운 쿼리).
    팀원의 stock_latest 실시간 데이터를 그대로 활용."""
    rows = (
        db.query(StockLatest.stock_code)
        .order_by(desc(StockLatest.volume))
        .limit(limit)
        .all()
    )
    return [r[0] for r in rows]


def get_top_candidates_by_trade_value(db: Session, limit: int = 100) -> list[str]:
    """거래대금(가격×거래량) 기준 상위 N개 종목코드 반환.
    거래량만 볼 때보다 '실제로 돈이 많이 오간' 종목 위주로 걸러짐."""
    rows = (
        db.query(StockLatest.stock_code)
        .order_by(desc(StockLatest.trade_value))
        .limit(limit)
        .all()
    )
    return [r[0] for r in rows]