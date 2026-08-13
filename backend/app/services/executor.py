import os

from datetime import date, datetime, timedelta

import pandas as pd
from sqlalchemy.orm import Session
from sqlalchemy import func
from app.services import kis_client
from app.models.stock_candle_1d import StockCandle1d
from app.models.index_candle_1d import IndexCandle1d
from app.models.order import LiveOrder
from app.models.position_state import PositionState
from app.services.momentum_ranking import MomentumRankingService
from app.models.candle import Candle1m
from app.strategies.base import BaseStrategy

RISK_PER_TRADE_PCT = float(os.getenv("RISK_PER_TRADE_PCT", "0.02"))
STOP_LOSS_PCT = float(os.getenv("STOP_LOSS_PCT", "0.03"))
TAKE_PROFIT_PCT = float(os.getenv("TAKE_PROFIT_PCT", "0.06"))
PARTIAL_TAKE_PROFIT_PCT = float(os.getenv("PARTIAL_TAKE_PROFIT_PCT", "0.06"))
PARTIAL_TAKE_PROFIT_RATIO = float(os.getenv("PARTIAL_TAKE_PROFIT_RATIO", "0.50"))
TRAILING_STOP_ACTIVATION_PCT = float(os.getenv("TRAILING_STOP_ACTIVATION_PCT", "0.08"))
TRAILING_STOP_DRAWDOWN_PCT = float(os.getenv("TRAILING_STOP_DRAWDOWN_PCT", "0.03"))
MAX_HOLDING_TRADING_DAYS = int(os.getenv("MAX_HOLDING_TRADING_DAYS", "20"))
MAX_OPEN_POSITIONS = int(os.getenv("MAX_OPEN_POSITIONS", "5"))


def _load_historical_df(db: Session, ticker: str, lookback_days: int = 30) -> pd.DataFrame:
    """팀원의 stock_candles_1d(일봉)에서 최근 lookback_days만큼의 데이터 로드 (오늘 이전 데이터).
    price_history를 대체 - 팀원이 이미 수집한 데이터를 그대로 사용."""
    rows = (
        db.query(StockCandle1d)
        .filter(StockCandle1d.stock_code == ticker)
        .order_by(StockCandle1d.d.desc())
        .limit(lookback_days)
        .all()
    )
    rows = list(reversed(rows))

    if not rows:
        return pd.DataFrame(columns=["open", "high", "low", "close", "volume"])

    df = pd.DataFrame([{
        "date": r.d,
        "open": r.open_p,
        "high": r.high_p,
        "low": r.low_p,
        "close": r.close_p,
        "volume": r.volume,
    } for r in rows])
    df["date"] = pd.to_datetime(df["date"])
    return df.set_index("date")


def _get_today_ohlc_from_candles(db: Session, ticker: str) -> dict | None:
    """팀원의 stock_candles_1m(실시간 1분봉)을 오늘 날짜 기준으로 집계해 OHLCV 생성.
    데이터가 없으면 None 반환."""
    today = date.today()

    candles = (
        db.query(Candle1m)
        .filter(
            Candle1m.stock_code == ticker,
            func.date(Candle1m.ts) == today,
        )
        .order_by(Candle1m.ts.asc())
        .all()
    )

    if not candles:
        return None

    return {
        "open": candles[0].open_p,
        "high": max(c.high_p for c in candles),
        "low": min(c.low_p for c in candles),
        "close": candles[-1].close_p,
        "volume": sum(c.vol_delta or 0 for c in candles),
    }


def _load_kospi_df(db: Session, lookback_days: int = 260) -> pd.DataFrame:
    """시장 국면 계산용 KOSPI 일봉을 최근 260거래일만큼 로드한다."""
    rows = (
        db.query(IndexCandle1d)
        .filter(IndexCandle1d.index_code == "0001")
        .order_by(IndexCandle1d.d.desc())
        .limit(lookback_days)
        .all()
    )
    if not rows:
        return pd.DataFrame(columns=["open", "high", "low", "close", "volume"])

    rows = list(reversed(rows))
    df = pd.DataFrame([{
        "date": r.d,
        "open": float(r.open_p),
        "high": float(r.high_p),
        "low": float(r.low_p),
        "close": float(r.close_p),
        "volume": r.volume,
    } for r in rows])
    df["date"] = pd.to_datetime(df["date"])
    return df.set_index("date")


def _attach_live_factor_data(
    db: Session,
    price_df: pd.DataFrame,
    ticker: str,
    strategy: BaseStrategy,
) -> pd.DataFrame:
    """실시간 전략이 요구하는 momentum·market_regime 컬럼을 가격 데이터에 병합한다."""
    params = getattr(strategy, "params", {})
    indicators = set(params.get("indicators", []))
    if not indicators.intersection({"momentum", "market_regime"}):
        return price_df

    service = MomentumRankingService()
    enriched = price_df
    if "momentum" in indicators:
        enriched = service.attach_rank_to_price_df(enriched, ticker, db)
    if "market_regime" in indicators:
        kospi_df = _load_kospi_df(db)
        enriched = service.attach_market_regime(enriched, kospi_df)
    return enriched


def _append_today_price(db: Session, historical_df: pd.DataFrame, ticker: str) -> pd.DataFrame:
    """오늘자 실시간 OHLC(팀원의 stock_candles_1m 집계)를 마지막 행으로 추가/교체.
    당일 데이터가 아직 없으면(장 시작 전 등) historical_df를 그대로 반환."""
    today_ohlc = _get_today_ohlc_from_candles(db, ticker)
    if today_ohlc is None:
        return historical_df

    today = pd.Timestamp.now().normalize()
    today_row = pd.DataFrame([today_ohlc], index=[today])

    if today in historical_df.index:
        historical_df = historical_df.drop(index=today)
    return pd.concat([historical_df, today_row])


def _get_position_quantity(db: Session, ticker: str, strategy_name: str) -> int:
    """체결 buy/sell의 순수량으로 현재 포지션을 계산한다."""
    filled_quantity = func.coalesce(func.nullif(LiveOrder.filled_qty, 0), LiveOrder.quantity)
    rows = (
        db.query(LiveOrder.order_type, func.coalesce(func.sum(filled_quantity), 0))
        .filter(
            LiveOrder.ticker == ticker,
            LiveOrder.strategy_name == strategy_name,
            LiveOrder.status == "filled",
        )
        .group_by(LiveOrder.order_type)
        .all()
    )
    quantities = {order_type: int(total or 0) for order_type, total in rows}
    return max(0, quantities.get("buy", 0) - quantities.get("sell", 0))


def _get_avg_buy_price(db: Session, ticker: str, strategy_name: str) -> float | None:
    """잔여 포지션 기준의 체결 매수 평균가를 계산한다."""
    buys = (
        db.query(LiveOrder)
        .filter(LiveOrder.ticker == ticker, LiveOrder.strategy_name == strategy_name,
                LiveOrder.order_type == "buy", LiveOrder.status == "filled")
        .order_by(LiveOrder.created_at.asc()).all()
    )
    sells = (
        db.query(LiveOrder)
        .filter(LiveOrder.ticker == ticker, LiveOrder.strategy_name == strategy_name,
                LiveOrder.order_type == "sell", LiveOrder.status == "filled")
        .order_by(LiveOrder.created_at.asc()).all()
    )
    remaining_sell = sum(int(order.filled_qty or order.quantity or 0) for order in sells)
    weighted_cost = 0.0
    remaining_buy = 0
    for order in buys:
        quantity = int(order.filled_qty or order.quantity or 0)
        consumed = min(quantity, remaining_sell)
        remaining_sell -= consumed
        available = quantity - consumed
        if available and order.filled_price:
            weighted_cost += available * float(order.filled_price)
            remaining_buy += available
    return weighted_cost / remaining_buy if remaining_buy else None


def _get_current_position(db: Session, ticker: str, strategy_name: str) -> int:
    return 1 if _get_position_quantity(db, ticker, strategy_name) > 0 else 0


def _get_all_strategy_tickers(db: Session, strategy_name: str) -> set[str]:
    rows = db.query(LiveOrder.ticker).filter(LiveOrder.strategy_name == strategy_name).distinct().all()
    return {ticker for (ticker,) in rows}


def _count_open_positions(db: Session, strategy_name: str) -> int:
    """순보유 포지션과 pending buy를 합산해 신규 매수 한도를 계산한다."""
    open_tickers = {
        ticker for ticker in _get_all_strategy_tickers(db, strategy_name)
        if _get_position_quantity(db, ticker, strategy_name) > 0
    }
    pending_buy_tickers = {
        ticker for (ticker,) in db.query(LiveOrder.ticker).filter(
            LiveOrder.strategy_name == strategy_name,
            LiveOrder.order_type == "buy",
            LiveOrder.status == "pending",
        ).distinct().all()
    }
    return len(open_tickers | pending_buy_tickers)


def get_all_held_tickers(db: Session, strategy_name: str) -> list[str]:
    return [
        ticker for ticker in _get_all_strategy_tickers(db, strategy_name)
        if _get_position_quantity(db, ticker, strategy_name) > 0
    ]


def _check_risk_exit(current_price: int, avg_buy_price: int) -> str | None:
    """손절/익절 조건 체크. 'stop_loss' | 'take_profit' | None 반환."""
    if avg_buy_price is None or avg_buy_price == 0:
        return None

    change_pct = (current_price - avg_buy_price) / avg_buy_price

    if change_pct <= -STOP_LOSS_PCT:
        return "stop_loss"
    if change_pct >= TAKE_PROFIT_PCT:
        return "take_profit"
    return None

def _calculate_position_size(current_price: int) -> int:
    """계좌 현금 잔고 기준으로 리스크 % 만큼 몇 주 살지 계산.
    리스크 예산으로 1주도 못 사면, 현금으로 1주는 살 수 있는 한 최소 1주 허용.
    계좌 잔고는 팀원 데이터로 대체 불가능한 영역이라 KIS API 그대로 사용."""
    balance = kis_client.get_account_balance()
    risk_amount = balance["cash"] * RISK_PER_TRADE_PCT
    quantity = int(risk_amount // current_price)

    if quantity < 1:
        if balance["cash"] >= current_price:
            return 1
        return 0

    return quantity

def _get_position_state(db: Session, ticker: str, strategy_name: str) -> PositionState:
    state = db.query(PositionState).filter(
        PositionState.ticker == ticker,
        PositionState.strategy_name == strategy_name,
    ).first()
    if state is None:
        state = PositionState(ticker=ticker, strategy_name=strategy_name)
        db.add(state)
        db.flush()
    return state


def _sync_position_state(
    db: Session,
    ticker: str,
    strategy_name: str,
    current_price: float,
) -> PositionState:
    state = _get_position_state(db, ticker, strategy_name)
    quantity = _get_position_quantity(db, ticker, strategy_name)
    avg_price = _get_avg_buy_price(db, ticker, strategy_name)
    if quantity <= 0:
        state.quantity = 0
        state.avg_buy_price = None
        state.highest_price = None
        state.trailing_active = 0
        state.partial_profit_taken = 0
        state.first_buy_at = None
    else:
        first_buy = db.query(LiveOrder).filter(
            LiveOrder.ticker == ticker,
            LiveOrder.strategy_name == strategy_name,
            LiveOrder.order_type == "buy",
            LiveOrder.status == "filled",
        ).order_by(LiveOrder.created_at.asc()).first()
        state.quantity = quantity
        state.avg_buy_price = avg_price
        state.first_buy_at = first_buy.created_at if first_buy else state.first_buy_at
        state.highest_price = max(float(state.highest_price or current_price), float(current_price))
        if avg_price and current_price >= avg_price * (1 + TRAILING_STOP_ACTIVATION_PCT):
            state.trailing_active = 1
    state.updated_at = datetime.utcnow()
    db.flush()
    return state


def _holding_trading_days(db: Session, first_buy_at: datetime | None, ticker: str) -> int:
    if first_buy_at is None:
        return 0
    return int(db.query(func.count(StockCandle1d.d)).filter(
        StockCandle1d.stock_code == ticker,
        StockCandle1d.d > first_buy_at.date(),
        StockCandle1d.d <= date.today(),
    ).scalar() or 0)


def _has_pending_sell(db: Session, ticker: str, strategy_name: str) -> bool:
    return db.query(LiveOrder.id).filter(
        LiveOrder.ticker == ticker,
        LiveOrder.strategy_name == strategy_name,
        LiveOrder.order_type == "sell",
        LiveOrder.status == "pending",
    ).first() is not None


def _place_and_log(db, ticker, order_type, quantity, strategy_name, reason=None):
    """공통 주문 실행 + DB 기록 헬퍼. 주문 실행 자체는 KIS API 그대로 사용."""
    if quantity <= 0:
        return {"action": "skip", "reason": "주문 수량이 0 이하입니다."}
    result = kis_client.place_order(ticker, order_type, quantity, price=0)
    order = LiveOrder(
        ticker=ticker,
        order_type=order_type,
        quantity=quantity,
        price=0,
        strategy_name=strategy_name,
        kis_order_no=result.get("order_no"),
        status="pending" if result["success"] else "failed",
        reason=reason,
    )
    db.add(order)
    db.commit()
    db.refresh(order)
    return {"action": order_type, "order_id": order.id, "kis_response": result, "reason": reason}


def run_strategy_once(
    db: Session,
    ticker: str,
    strategy: BaseStrategy,
    strategy_name: str,
    quantity: int | None = None,
) -> dict:
    historical_df = _load_historical_df(db, ticker, lookback_days=30)
    if len(historical_df) < 20:
        return {"action": "skip", "reason": f"데이터 부족 (보유: {len(historical_df)}일, 최소 20일 필요)", "signal": None, "score": None}

    price_df = _append_today_price(db, historical_df, ticker)
    if len(price_df) == len(historical_df):
        return {"action": "skip", "reason": "오늘자 실시간 데이터 없음 (stock_candles_1m 비어있음)", "signal": None, "score": None}

    current_price = int(price_df["close"].iloc[-1])
    position_qty = _get_position_quantity(db, ticker, strategy_name)
    state = _sync_position_state(db, ticker, strategy_name, current_price)
    avg_buy_price = state.avg_buy_price
    pending_sell = _has_pending_sell(db, ticker, strategy_name)

    if position_qty > 0 and not pending_sell:
        change_pct = ((current_price - avg_buy_price) / avg_buy_price) if avg_buy_price else 0.0

        # 1순위: 손절은 잔여수량 전량 청산
        if change_pct <= -STOP_LOSS_PCT:
            return _place_and_log(db, ticker, "sell", position_qty, strategy_name, reason="stop_loss")

        # 2순위: trailing 활성 후 고점 대비 하락하면 전량 청산
        if state.trailing_active and state.highest_price and current_price <= state.highest_price * (1 - TRAILING_STOP_DRAWDOWN_PCT):
            return _place_and_log(db, ticker, "sell", position_qty, strategy_name, reason="trailing_stop")

        # 3순위: 부분 익절. 1주 보유 시 일반 부분 익절은 보류
        if not state.partial_profit_taken and change_pct >= PARTIAL_TAKE_PROFIT_PCT and position_qty >= 2:
            partial_qty = max(1, int(position_qty * PARTIAL_TAKE_PROFIT_RATIO))
            result = _place_and_log(db, ticker, "sell", min(partial_qty, position_qty), strategy_name, reason="partial_take_profit")
            if result.get("action") == "sell":
                state.partial_profit_taken = 1
                db.commit()
            return result

    # 4순위 이후: 최신 전략 신호를 평가한다.
    price_df = _attach_live_factor_data(db, price_df, ticker, strategy)
    signal_df = strategy.generate_signals(price_df)
    latest_signal = int(signal_df["signal"].iloc[-1])
    latest_score = float(signal_df["score"].iloc[-1]) if "score" in signal_df.columns else None

    if position_qty > 0 and not pending_sell:
        holding_days = _holding_trading_days(db, state.first_buy_at, ticker)
        change_pct = ((current_price - avg_buy_price) / avg_buy_price) if avg_buy_price else 0.0

        # 4순위: 장기 정체 포지션은 전량 청산. 강한 매수 신호와 수익 구간은 유지.
        if holding_days >= MAX_HOLDING_TRADING_DAYS and latest_signal != 1 and change_pct < PARTIAL_TAKE_PROFIT_PCT:
            return _place_and_log(db, ticker, "sell", position_qty, strategy_name, reason="time_exit")

        # 5순위: momentum·market_regime을 포함한 전략 매도 신호
        if latest_signal == -1:
            return _place_and_log(db, ticker, "sell", position_qty, strategy_name, reason="strategy_signal")
        if latest_signal == 1:
            return {"action": "hold", "reason": "이미 보유 중", "score": latest_score, "signal": latest_signal}
        return {"action": "hold", "reason": "신호 없음", "score": latest_score, "signal": latest_signal}

    if latest_signal == -1:
        return {"action": "hold", "reason": "보유 물량 없음", "score": latest_score, "signal": latest_signal}
    if latest_signal == 0:
        return {"action": "hold", "reason": "신호 없음", "score": latest_score, "signal": latest_signal}

    # 신규 매수는 보유 포지션·pending buy를 포함한 종목 수 제한을 최종 확인한다.
    if _count_open_positions(db, strategy_name) >= MAX_OPEN_POSITIONS:
        return {"action": "skip", "reason": f"최대 동시 보유 종목 수 초과 ({MAX_OPEN_POSITIONS})", "score": latest_score, "signal": latest_signal}

    qty = quantity if quantity is not None else _calculate_position_size(current_price)
    if qty < 1:
        return {"action": "skip", "reason": "리스크 기준 매수 가능 수량 부족 (잔고 부족)", "score": latest_score, "signal": latest_signal}

    result = _place_and_log(db, ticker, "buy", qty, strategy_name, reason="strategy_signal")
    result["score"] = latest_score
    result["signal"] = latest_signal
    return result