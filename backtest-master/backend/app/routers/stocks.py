from fastapi import APIRouter, Depends, HTTPException, Query
from sqlalchemy.orm import Session
from pykrx import stock as pykrx_stock
from app.database import get_db
from app.models.stock import Stock
from app.models.strategy import Strategy
from app.schemas.backtest import StockResponse, StrategyResponse
from app.services.data_collector import (
    fetch_stock_ohlcv, save_price_history, ensure_stock_exists
)
from datetime import date, timedelta

router = APIRouter()


@router.get("/stocks", response_model=list[StockResponse])
def list_stocks(db: Session = Depends(get_db)):
    return db.query(Stock).filter(Stock.market != "INDEX").all()


@router.get("/strategies", response_model=list[StrategyResponse])
def list_strategies(db: Session = Depends(get_db)):
    return db.query(Strategy).all()


@router.get("/stocks/search")
def search_stock(ticker: str = Query(..., description="종목 코드 (예: 005930)"),
                 db: Session = Depends(get_db)):
    """
    종목 코드로 검색 — DB에 없으면 pykrx로 조회해서 추가.
    """
    # 1. DB에 이미 있는지 확인
    existing = db.query(Stock).filter(Stock.ticker == ticker).first()
    if existing:
        return {"ticker": existing.ticker, "name": existing.name,
                "market": existing.market, "is_new": False}

    # 2. pykrx로 종목명 조회
    try:
        name = pykrx_stock.get_market_ticker_name(ticker)
        if not name:
            raise HTTPException(status_code=404, detail="종목을 찾을 수 없습니다.")
    except Exception:
        raise HTTPException(status_code=404, detail="종목을 찾을 수 없습니다.")

    # 3. 시장 구분 (코스피/코스닥)
    kospi_tickers = pykrx_stock.get_market_ticker_list(market="KOSPI")
    market = "KOSPI" if ticker in kospi_tickers else "KOSDAQ"

    # 4. DB에 종목 등록
    ensure_stock_exists(db, ticker, name, market)

    # 5. 최근 2년치 데이터 수집
    end = date.today().strftime("%Y%m%d")
    start = (date.today() - timedelta(days=730)).strftime("%Y%m%d")
    try:
        df = fetch_stock_ohlcv(ticker, start, end)
        save_price_history(db, ticker, df)
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"데이터 수집 실패: {str(e)}")

    return {"ticker": ticker, "name": name,
            "market": market, "is_new": True}