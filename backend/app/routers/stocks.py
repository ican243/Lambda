from fastapi import APIRouter, Depends, HTTPException, Query
from sqlalchemy.orm import Session
from app.database import get_db
from app.models.stock_master import StockMaster
from app.models.stock_candle_1d import StockCandle1d
from app.models.strategy import Strategy
from app.schemas.backtest import StockResponse, StrategyResponse
from app.services.data_collector import get_stock_master

router = APIRouter()


@router.get("/stocks", response_model=list[StockResponse])
def list_stocks(db: Session = Depends(get_db)):
    stocks = db.query(StockMaster).all()
    return [
        StockResponse(ticker=s.stock_code, name=s.stock_name, market=s.market)
        for s in stocks
    ]


@router.get("/strategies", response_model=list[StrategyResponse])
def list_strategies(db: Session = Depends(get_db)):
    return db.query(Strategy).all()


@router.get("/stocks/search")
def search_stock(ticker: str = Query(..., description="종목 코드 (예: 005930)"),
                 db: Session = Depends(get_db)):
    """
    종목 코드로 검색 - stock_master(팀원 관리)에서 조회.
    없으면 404. (종목 등록/일봉 수집은 팀원 쪽 책임이라 여기서 직접 만들지 않음)
    """
    existing = get_stock_master(db, ticker)
    if not existing:
        raise HTTPException(
            status_code=404,
            detail="stock_master에 등록되지 않은 종목입니다. 팀원 쪽 등록이 필요합니다."
        )

    has_history = (
        db.query(StockCandle1d)
        .filter(StockCandle1d.stock_code == ticker)
        .first()
        is not None
    )

    return {
        "ticker": existing.stock_code,
        "name": existing.stock_name,
        "market": existing.market,
        "has_history": has_history,
    }