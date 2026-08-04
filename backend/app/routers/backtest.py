from fastapi import APIRouter, Depends, HTTPException
from fastapi.responses import Response
from sqlalchemy.orm import Session
import pandas as pd

from app.database import get_db
from app.models.price_history import PriceHistory
from app.models.backtest_run import BacktestRun
from app.models.backtest_result import BacktestResult
from app.models.strategy import Strategy
from app.models.trade import Trade as TradeModel
from app.schemas.backtest import BacktestRequest, BacktestResponse, TradeResponse, EquityPoint, CompareRequest, CompareResponse, StrategyCompareResult, GridSearchRequest, GridSearchResponse, GridSearchResult, BacktestHistoryItem, BacktestHistoryResponse
from app.strategies.ma20 import MA20Strategy
from app.strategies.golden_cross import GoldenCrossStrategy
from app.strategies.rsi import RSIStrategy
from app.services.simulator import simulate_trades
from app.services.analyzer import analyze_backtest
from app.services.report_generator import generate_backtest_report
from app.strategies.ma20_rsi_momentum import MA20RSIMomentumFilteredStrategy
from app.services.momentum_ranking import MomentumRankingService
from app.services.grid_search import run_grid_search
from app.strategies.configurable_factor import ConfigurableFactorStrategy
from app.strategies.factors import FACTOR_REGISTRY

router = APIRouter(prefix="/backtest", tags=["backtest"])

STRATEGY_MAP = {
    "MA20": MA20Strategy,
    "GOLDEN_CROSS": GoldenCrossStrategy,
    "RSI": RSIStrategy,
    "MA20_RSI_MOMENTUM": MA20RSIMomentumFilteredStrategy,
    "CONFIGURABLE_FACTOR": ConfigurableFactorStrategy,
}

STRATEGIES_REQUIRING_MOMENTUM = {"MA20_RSI_MOMENTUM", "CONFIGURABLE_FACTOR"}
STRATEGIES_REQUIRING_MARKET_REGIME = {"CONFIGURABLE_FACTOR"}

def _load_price_df(db: Session, ticker: str, start_date, end_date) -> pd.DataFrame:
    rows = (
        db.query(PriceHistory)
        .filter(
            PriceHistory.ticker == ticker,
            PriceHistory.date >= start_date,
            PriceHistory.date <= end_date,
        )
        .all()
    )
    if not rows:
        raise HTTPException(status_code=404, detail=f"{ticker}의 가격 데이터가 없습니다.")

    df = pd.DataFrame([{
        "date": r.date, "open": r.open, "high": r.high,
        "low": r.low, "close": r.close, "volume": r.volume,
    } for r in rows]).set_index("date").sort_index()
    return df

def _attach_momentum_if_needed(
    price_df: pd.DataFrame, ticker: str, strategy_name: str, db: Session
) -> pd.DataFrame:
    """전략이 모멘텀 랭킹을 필요로 할 때만 컬럼을 붙여서 반환."""
    if strategy_name not in STRATEGIES_REQUIRING_MOMENTUM:
        return price_df
    service = MomentumRankingService()
    return service.attach_rank_to_price_df(price_df, ticker, db)

def _attach_market_regime_if_needed(price_df, strategy_name, index_df):
    if strategy_name not in STRATEGIES_REQUIRING_MARKET_REGIME or index_df is None:
        return price_df
    service = MomentumRankingService()
    return service.attach_market_regime(price_df, index_df)

def _build_equity_curve_data(analysis: dict) -> list:
    """API 응답 및 PDF용 equity_curve 데이터 변환."""
    strategy_curve = analysis["equity_curve"]
    buy_hold_curve = analysis["buy_hold_curve"]
    return [
        {
            "date": str(date),
            "strategy": round(float(strategy_curve[date]), 2),
            "buy_hold": round(float(buy_hold_curve[date]), 2),
        }
        for date in strategy_curve.index
    ]

@router.post("/compare", response_model=CompareResponse)
def compare_strategies(request: CompareRequest, db: Session = Depends(get_db)):
    """동일 종목/기간에 여러 전략을 동시 실행해서 비교."""

    # 1. 데이터 로드
    price_df = _load_price_df(db, request.ticker, request.start_date, request.end_date)
    try:
        index_df = _load_price_df(db, "KOSPI", request.start_date, request.end_date)
    except HTTPException:
        index_df = None

    results = []

    for strategy_name in request.strategies:
        strategy_cls = STRATEGY_MAP.get(strategy_name)
        if strategy_cls is None:
            continue

        # 2. 전략별 실행
        strategy_price_df = _attach_momentum_if_needed(price_df, request.ticker, strategy_name, db)
        strategy = strategy_cls()
        signal_df = strategy.generate_signals(strategy_price_df)
        trades = simulate_trades(signal_df)
        analysis = analyze_backtest(price_df, trades, index_df=index_df)

        # 3. equity_curve 변환
        equity_curve_data = _build_equity_curve_data(analysis)

        results.append(StrategyCompareResult(
            strategy_name=strategy_name,
            total_return=analysis["total_return"],
            win_rate=analysis["win_rate"],
            mdd=analysis["mdd"],
            sharpe_ratio=analysis["sharpe_ratio"],
            buy_hold_return=analysis["buy_hold_return"],
            benchmark_return=analysis.get("benchmark_return"),
            excess_return=analysis.get("excess_return"),
            equity_curve=equity_curve_data,
        ))

    return CompareResponse(
        ticker=request.ticker,
        start_date=str(request.start_date),
        end_date=str(request.end_date),
        results=results,
    )

@router.post("/grid-search", response_model=GridSearchResponse)
def grid_search(request: GridSearchRequest, db: Session = Depends(get_db)):
    """파라미터 그리드서치 — 최적 파라미터 자동 탐색."""

    price_df = _load_price_df(db, request.ticker, request.start_date, request.end_date)
    try:
        index_df = _load_price_df(db, "KOSPI", request.start_date, request.end_date)
    except HTTPException:
        index_df = None

    raw_results = run_grid_search(
        strategy_name=request.strategy_name,
        param_grid=request.param_grid,
        price_df=price_df,
        index_df=index_df,
    )

    results = [
        GridSearchResult(
            rank=i + 1,
            params=r["params"],
            total_return=r["total_return"],
            win_rate=r["win_rate"],
            mdd=r["mdd"],
            sharpe_ratio=r["sharpe_ratio"],
            excess_return=r.get("excess_return"),
        )
        for i, r in enumerate(raw_results)
    ]

    return GridSearchResponse(
        ticker=request.ticker,
        strategy_name=request.strategy_name,
        start_date=str(request.start_date),
        end_date=str(request.end_date),
        total_combinations=len(raw_results),
        results=results,
    )

@router.post("/run", response_model=BacktestResponse)
def run_backtest(request: BacktestRequest, db: Session = Depends(get_db)):
    # 1. 전략 클래스 찾기
    strategy_cls = STRATEGY_MAP.get(request.strategy_name)
    if strategy_cls is None:
        raise HTTPException(status_code=400, detail=f"알 수 없는 전략: {request.strategy_name}")

    # 2. 종목 + 지수(KOSPI) 데이터 로드
    price_df = _load_price_df(db, request.ticker, request.start_date, request.end_date)
    try:
        index_df = _load_price_df(db, "KOSPI", request.start_date, request.end_date)
    except HTTPException:
        index_df = None

    # 3. 전략 실행 -> 시뮬레이션 -> 분석
    price_df = _attach_momentum_if_needed(price_df, request.ticker, request.strategy_name, db)
    price_df = _attach_market_regime_if_needed(price_df, request.strategy_name, index_df)
    strategy = strategy_cls(params=request.params)
    signal_df = strategy.generate_signals(price_df)
    trades = simulate_trades(
        signal_df,
        stop_loss_pct=request.params.get("stop_loss_pct"),
        take_profit_pct=request.params.get("take_profit_pct"),
    )
    analysis = analyze_backtest(price_df, trades, index_df=index_df)

    # 4. DB에 실행 기록 저장
    strategy_row = db.query(Strategy).filter(Strategy.name == request.strategy_name).first()
    if strategy_row is None:
        strategy_row = Strategy(name=request.strategy_name, params=request.params)
        db.add(strategy_row)
        db.commit()

    run = BacktestRun(
        ticker=request.ticker,
        strategy_id=strategy_row.id,
        params=request.params,
        start_date=request.start_date,
        end_date=request.end_date,
    )
    db.add(run)
    db.commit()

    result = BacktestResult(
        run_id=run.id,
        total_return=analysis["total_return"],
        win_rate=analysis["win_rate"],
        mdd=analysis["mdd"],
        sharpe_ratio=analysis["sharpe_ratio"],
        benchmark_return=analysis.get("benchmark_return"),
        buy_hold_return=analysis["buy_hold_return"],
    )
    db.add(result)
    db.commit()

    # 5. 개별 매매 내역 저장
    for t in trades:
        db.add(TradeModel(
            run_id=run.id,
            entry_date=t.entry_date,
            entry_price=t.entry_price,
            exit_date=t.exit_date,
            exit_price=t.exit_price,
            profit=t.profit,
            cost=t.cost,
        ))
    db.commit()

    # 6. 응답 반환 (equity_curve는 매번 재계산해서 반환)
    equity_curve_data = _build_equity_curve_data(analysis)

    return BacktestResponse(
        run_id=run.id,
        total_return=analysis["total_return"],
        win_rate=analysis["win_rate"],
        mdd=analysis["mdd"],
        sharpe_ratio=analysis["sharpe_ratio"],
        buy_hold_return=analysis["buy_hold_return"],
        benchmark_return=analysis.get("benchmark_return"),
        excess_return=analysis.get("excess_return"),
        equity_curve=equity_curve_data,
    )

@router.get("/factors")
def get_available_factors():
    """프론트엔드에서 지표 선택 UI를 그릴 때 쓰는 지표 메타데이터 목록."""
    return {
        key: {
            "label": factor["label"],
            "description": factor["description"],
            "default_weight": factor["default_weight"],
        }
        for key, factor in FACTOR_REGISTRY.items()
    }

@router.get("/history", response_model=BacktestHistoryResponse)
def get_backtest_history(
    skip: int = 0,
    limit: int = 20,
    db: Session = Depends(get_db)
):
    """백테스트 실행 히스토리 목록 조회 (최신순)."""
    runs = (
        db.query(BacktestRun)
        .order_by(BacktestRun.created_at.desc())
        .offset(skip)
        .limit(limit)
        .all()
    )

    total = db.query(BacktestRun).count()

    items = []
    for run in runs:
        strategy = db.query(Strategy).filter(
            Strategy.id == run.strategy_id
        ).first()
        result = db.query(BacktestResult).filter(
            BacktestResult.run_id == run.id
        ).first()

        items.append(BacktestHistoryItem(
            run_id=run.id,
            ticker=run.ticker,
            strategy_name=strategy.name if strategy else "-",
            start_date=str(run.start_date),
            end_date=str(run.end_date),
            created_at=str(run.created_at),
            total_return=result.total_return if result else None,
            win_rate=result.win_rate if result else None,
            mdd=result.mdd if result else None,
            sharpe_ratio=result.sharpe_ratio if result else None,
        ))

    return BacktestHistoryResponse(total=total, items=items)

@router.get("/{run_id}", response_model=BacktestResponse)
def get_backtest_result(run_id: int, db: Session = Depends(get_db)):
    run = db.query(BacktestRun).filter(BacktestRun.id == run_id).first()
    if run is None:
        raise HTTPException(status_code=404, detail="해당 run_id를 찾을 수 없습니다.")

    result = db.query(BacktestResult).filter(BacktestResult.run_id == run_id).first()
    if result is None:
        raise HTTPException(status_code=404, detail="결과 데이터가 없습니다.")

    excess_return = None
    if result.benchmark_return is not None:
        excess_return = result.total_return - result.benchmark_return

    return BacktestResponse(
        run_id=run.id,
        total_return=result.total_return,
        win_rate=result.win_rate,
        mdd=result.mdd,
        sharpe_ratio=result.sharpe_ratio,
        buy_hold_return=result.buy_hold_return,
        benchmark_return=result.benchmark_return,
        excess_return=excess_return,
        equity_curve=None,  # 재조회 시엔 차트 없음 (실행 시에만 제공)
    )


@router.get("/{run_id}/trades", response_model=list[TradeResponse])
def get_backtest_trades(run_id: int, db: Session = Depends(get_db)):
    trades = db.query(TradeModel).filter(TradeModel.run_id == run_id).all()
    return trades


@router.get("/{run_id}/report")
def download_report(run_id: int, db: Session = Depends(get_db)):
    run = db.query(BacktestRun).filter(BacktestRun.id == run_id).first()
    if run is None:
        raise HTTPException(status_code=404, detail="해당 run_id를 찾을 수 없습니다.")

    result_row = db.query(BacktestResult).filter(BacktestResult.run_id == run_id).first()
    if result_row is None:
        raise HTTPException(status_code=404, detail="결과 데이터가 없습니다.")

    trades = db.query(TradeModel).filter(TradeModel.run_id == run_id).all()

    # equity_curve를 PDF용으로 재계산
    price_df = _load_price_df(db, run.ticker, run.start_date, run.end_date)
    strategy_row = db.query(Strategy).filter(Strategy.id == run.strategy_id).first()
    strategy_cls = STRATEGY_MAP.get(strategy_row.name)
    price_df = _attach_momentum_if_needed(price_df, run.ticker, strategy_row.name, db)
    signal_df = strategy_cls(params=run.params or {}).generate_signals(price_df)
    sim_trades = simulate_trades(signal_df)
    analysis = analyze_backtest(price_df, sim_trades)
    equity_curve_data = _build_equity_curve_data(analysis)

    result_dict = {
        "total_return": result_row.total_return,
        "win_rate": result_row.win_rate,
        "mdd": result_row.mdd,
        "sharpe_ratio": result_row.sharpe_ratio,
        "buy_hold_return": result_row.buy_hold_return,
        "benchmark_return": result_row.benchmark_return,
        "excess_return": (
            result_row.total_return - result_row.benchmark_return
            if result_row.benchmark_return is not None else None
        ),
    }

    pdf_bytes = generate_backtest_report(
        run_id=run_id,
        ticker=run.ticker,
        strategy_name=strategy_row.name,
        start_date=str(run.start_date),
        end_date=str(run.end_date),
        result=result_dict,
        trades=trades,
        equity_curve=equity_curve_data,
    )

    return Response(
        content=pdf_bytes,
        media_type="application/pdf",
        headers={"Content-Disposition": f"attachment; filename=backtest_report_{run_id}.pdf"},
    )

