from fastapi import APIRouter, Depends, HTTPException, Query
from sqlalchemy.orm import Session
from app.database import get_db
from app.models.stock_master import StockMaster
from app.models.strategy import Strategy
from app.schemas.backtest import StockResponse, StrategyResponse
from app.services.data_collector import (
    fetch_stock_ohlcv, save_price_history, get_stock_master
)
from datetime import date, timedelta

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
    종목 코드로 검색 — stock_master(팀원 관리)에서 조회.
    없으면 404. (종목 등록은 팀원 쪽 책임이라 여기서 직접 만들지 않음)
    """
    # 1. stock_master에 있는지 확인
    existing = get_stock_master(db, ticker)
    if not existing:
        raise HTTPException(
            status_code=404,
            detail="stock_master에 등록되지 않은 종목입니다. 팀원 쪽 등록이 필요합니다."
        )

    # 2. price_history에 백테스팅용 데이터가 있는지 확인, 없으면 수집
    from app.models.price_history import PriceHistory
    has_history = db.query(PriceHistory).filter(PriceHistory.ticker == ticker).first()

    is_new = False
    if not has_history:
        end = date.today().strftime("%Y%m%d")
        start = (date.today() - timedelta(days=730)).strftime("%Y%m%d")
        try:
            df = fetch_stock_ohlcv(ticker, start, end)
            save_price_history(db, ticker, df)
            is_new = True
        except Exception as e:
            raise HTTPException(status_code=500, detail=f"데이터 수집 실패: {str(e)}")

    return {
        "ticker": existing.stock_code,
        "name": existing.stock_name,
        "market": existing.market,
        "is_new": is_new,
    }