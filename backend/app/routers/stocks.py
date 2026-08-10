from fastapi import APIRouter, Depends, HTTPException, Query
from sqlalchemy.orm import Session
from app.database import get_db
from app.models.stock_master import StockMaster
from app.models.stock_candle_1d import StockCandle1d
from app.models.strategy import Strategy
from app.schemas.backtest import StockResponse, StrategyResponse
from app.services.data_collector import get_stock_master, fetch_index_ohlcv, save_index_price, INDEX_FDR_CODE
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
    없으면 404. (종목 등록/일봉 수집은 팀원 쪽 책임이라 여기서 직접 만들지 않음)
    """
    existing = get_stock_master(db, ticker)
    if not existing:
        raise HTTPException(
            status_code=404,
            detail="stock_master에 등록되지 않은 종목입니다. 팀원 쪽 등록이 필요합니다."
        )

    # 백테스팅용 일봉 데이터가 stock_candles_1d(팀원 관리)에 있는지 확인만 함 (직접 수집하지 않음)
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


@router.post("/stocks/index/{index_code}/sync")
def sync_index_price(index_code: str, db: Session = Depends(get_db)):
    """
    벤치마크용 지수(KOSPI/KOSDAQ) 과거 2년치 데이터를 py_index_prices에 수집.
    최초 1회 실행하거나, 데이터를 최신화하고 싶을 때 다시 호출하면 됨(upsert).
    주의: 팀원이 stock_candles_1d에 지수 데이터를 추가해주면 이 엔드포인트는
    더 이상 필요 없어질 수 있음 (요청 완료, 대기 중).
    """
    if index_code not in INDEX_FDR_CODE:
        raise HTTPException(
            status_code=400,
            detail=f"지원하지 않는 지수입니다. 사용 가능: {list(INDEX_FDR_CODE.keys())}"
        )

    end = date.today().strftime("%Y-%m-%d")
    start = (date.today() - timedelta(days=730)).strftime("%Y-%m-%d")

    try:
        df = fetch_index_ohlcv(index_code, start, end)
        count = save_index_price(db, index_code, df)
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"지수 데이터 수집 실패: {str(e)}")

    return {"index_code": index_code, "rows_saved": count}