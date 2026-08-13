import os
from datetime import datetime, timedelta

from sqlalchemy import delete, select
from sqlalchemy.orm import Session

from app.models.trade_signal_log import TradeSignalLog


MIN_RELOG_SECONDS = int(os.getenv("SIGNAL_LOG_MIN_RELOG_SECONDS", "60"))
DEFAULT_RETENTION_DAYS = 3
DEFAULT_CLEANUP_BATCH_SIZE = 500


def _reason_code(reason: str | None) -> str:
    if not reason:
        return "none"
    if "데이터 부족" in reason:
        return "insufficient_data"
    if "실시간 데이터" in reason:
        return "missing_realtime_data"
    if "이미 보유" in reason:
        return "already_held"
    if "보유 물량" in reason:
        return "no_position"
    if "신호 없음" in reason:
        return "no_signal"
    if "잔고 부족" in reason:
        return "insufficient_balance"
    if reason in {"stop_loss", "take_profit", "strategy_signal"}:
        return reason
    return reason[:50]


def _event_type(result: dict, previous: TradeSignalLog | None, strategy_params: dict | None) -> str | None:
    action = result.get("action")
    reason = result.get("reason")
    if action in {"buy", "sell"}:
        return "order"
    if action == "error":
        return "error"
    if action == "skip":
        return "data_issue"

    current_score = result.get("score")
    current_signal = result.get("signal")
    if previous is None:
        return "initial_state"

    previous_reason = _reason_code(previous.reason)
    current_reason = _reason_code(reason)
    if current_signal is not None and previous.signal is not None and current_signal != previous.signal:
        return "signal_change"
    if previous_reason != current_reason:
        return "state_change"

    if current_score is not None and previous.score is not None and strategy_params:
        buy_threshold = strategy_params.get("buy_threshold")
        sell_threshold = strategy_params.get("sell_threshold")
        if buy_threshold is not None:
            if previous.score < buy_threshold <= current_score:
                return "buy_threshold_cross"
            if previous.score >= buy_threshold > current_score:
                return "buy_threshold_exit"
        if sell_threshold is not None:
            if previous.score > sell_threshold >= current_score:
                return "sell_threshold_cross"
            if previous.score <= sell_threshold < current_score:
                return "sell_threshold_exit"

    return None


def record_decision_log(
    db: Session,
    *,
    ticker: str,
    strategy_name: str,
    result: dict,
    source: str,
    strategy_params: dict | None = None,
) -> tuple[bool, str | None]:
    """공통 로그 정책을 평가하고 저장 대상으로 판정된 결과만 세션에 추가한다."""
    if source == "manual_test":
        return False, None

    previous = db.execute(
        select(TradeSignalLog)
        .where(
            TradeSignalLog.ticker == ticker,
            TradeSignalLog.strategy_name == strategy_name,
        )
        .order_by(TradeSignalLog.created_at.desc(), TradeSignalLog.id.desc())
        .limit(1)
    ).scalar_one_or_none()

    event_type = _event_type(result, previous, strategy_params)
    if event_type is None:
        return False, None

    now = datetime.utcnow()
    if (
        previous is not None
        and previous.event_type == event_type
        and previous.created_at is not None
        and now - previous.created_at < timedelta(seconds=MIN_RELOG_SECONDS)
        and result.get("action") not in {"buy", "sell"}
    ):
        return False, None

    db.add(
        TradeSignalLog(
            ticker=ticker,
            strategy_name=strategy_name,
            score=result.get("score"),
            signal=result.get("signal"),
            action=result.get("action", "unknown"),
            reason=result.get("reason"),
            event_type=event_type,
            source=source,
            evaluated_at=now,
        )
    )
    return True, event_type


def cleanup_old_logs(
    db: Session,
    *,
    retention_days: int = DEFAULT_RETENTION_DAYS,
    batch_size: int = DEFAULT_CLEANUP_BATCH_SIZE,
) -> int:
    """3일 초과 로그를 500건 단위로 삭제해 장시간 DB 잠금을 줄인다."""
    cutoff = datetime.utcnow() - timedelta(days=retention_days)
    total_deleted = 0

    while True:
        ids = db.execute(
            select(TradeSignalLog.id)
            .where(TradeSignalLog.created_at < cutoff)
            .order_by(TradeSignalLog.created_at.asc(), TradeSignalLog.id.asc())
            .limit(batch_size)
        ).scalars().all()
        if not ids:
            break

        db.execute(delete(TradeSignalLog).where(TradeSignalLog.id.in_(ids)))
        db.commit()
        total_deleted += len(ids)

    return total_deleted
