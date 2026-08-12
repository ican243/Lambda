from pydantic import BaseModel
from datetime import date
from typing import Optional


class BacktestRequest(BaseModel):
    ticker: str              # 종목코드 (예: "005930")
    strategy_name: str       # "MA20" / "GOLDEN_CROSS" / "RSI"
    start_date: date
    end_date: date
    params: dict = {}        # 전략별 파라미터 (예: {"period": 20})

class EquityPoint(BaseModel):
    date: str
    strategy: float
    buy_hold: float

class BacktestResponse(BaseModel):
    run_id: int
    total_return: float
    win_rate: float
    mdd: float
    sharpe_ratio: float
    buy_hold_return: float
    benchmark_return: float | None = None
    excess_return: float | None = None
    equity_curve: Optional[list[EquityPoint]] = None # 총 자산 곡선 (date, equity)

class TradeResponse(BaseModel):
    entry_date: date
    entry_price: float
    exit_date: date | None
    exit_price: float | None
    profit: float | None
    cost: float | None

    class Config:
        from_attributes = True  # SQLAlchemy 객체를 바로 변환 가능하게 함


class StockResponse(BaseModel):
    ticker: str
    name: str
    market: str | None = None

    class Config:
        from_attributes = True


class StrategyResponse(BaseModel):
    id: int
    name: str
    params: dict | None

    class Config:
        from_attributes = True

#전략 비교

class CompareRequest(BaseModel):
    ticker: str
    start_date: date
    end_date: date
    strategies: list[str] = ["MA20", "GOLDEN_CROSS", "RSI"]  # 비교할 전략 목록

class StrategyCompareResult(BaseModel):
    strategy_name: str
    total_return: float
    win_rate: float
    mdd: float
    sharpe_ratio: float
    buy_hold_return: float
    benchmark_return: Optional[float] = None
    excess_return: Optional[float] = None
    equity_curve: Optional[list[EquityPoint]] = None

class CompareResponse(BaseModel):
    ticker: str
    start_date: str
    end_date: str
    results: list[StrategyCompareResult]

class GridSearchRequest(BaseModel):
    ticker: str
    strategy_name: str
    start_date: date
    end_date: date
    param_grid: dict  # 예: {"period": [5, 10, 15, 20, 30, 50]}

class GridSearchResult(BaseModel):
    rank: int
    params: dict
    total_return: float
    win_rate: float
    mdd: float
    sharpe_ratio: float
    excess_return: Optional[float] = None

class GridSearchResponse(BaseModel):
    ticker: str
    strategy_name: str
    start_date: str
    end_date: str
    total_combinations: int
    results: list[GridSearchResult]

class BacktestHistoryItem(BaseModel):
    run_id: int
    ticker: str
    strategy_name: str
    start_date: str
    end_date: str
    created_at: str
    total_return: Optional[float] = None
    win_rate: Optional[float] = None
    mdd: Optional[float] = None
    sharpe_ratio: Optional[float] = None

class BacktestHistoryResponse(BaseModel):
    total: int
    items: list[BacktestHistoryItem]