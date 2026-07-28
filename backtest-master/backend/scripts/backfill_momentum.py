"""
momentum_rank 테이블 백필 스크립트.

사용법:
    python scripts/backfill_momentum.py                          # 최근 1년 기본 실행
    python scripts/backfill_momentum.py 20250101 20250724        # 기간 직접 지정
"""

import sys
import time
from datetime import datetime, timedelta

sys.path.append(".")

from app.database import SessionLocal
from app.models.price_history import PriceHistory
from app.services.momentum_ranking import MomentumRankingService


def get_trading_days(start_date: str, end_date: str, db, lookback_buffer_days: int = 60) -> list[str]:
    """
    이미 수집된 KOSPI 지수 데이터의 날짜를 거래일 목록으로 사용.
    모멘텀 계산에 필요한 과거 조회(lookback)를 위해 start_date보다
    lookback_buffer_days만큼 더 이전부터 가져옴 (영업일 기준 20일 확보 목적).
    """
    start = datetime.strptime(start_date, "%Y%m%d").date()
    end = datetime.strptime(end_date, "%Y%m%d").date()
    extended_start = start - timedelta(days=lookback_buffer_days)

    rows = (
        db.query(PriceHistory.date)
        .filter(
            PriceHistory.ticker == "KOSPI",
            PriceHistory.date >= extended_start,
            PriceHistory.date <= end,
        )
        .order_by(PriceHistory.date)
        .all()
    )
    return [r.date.strftime("%Y%m%d") for r in rows]


def backfill(start_date: str, end_date: str, market: str = "KOSPI"):
    service = MomentumRankingService(momentum_period=20)

    db = SessionLocal()
    try:
        trading_days = get_trading_days(start_date, end_date, db)
    finally:
        db.close()

    if not trading_days:
        print(f"[백필 중단] {start_date}~{end_date} 구간에 KOSPI 데이터가 없습니다.")
        return

    # 실제 계산 대상은 원래 요청 범위 내 날짜만
    target_days = [d for d in trading_days if start_date <= d <= end_date]

    print(f"[백필 시작] {market} / {start_date} ~ {end_date} / 총 {len(target_days)} 영업일 (조회 버퍼 포함 전체 {len(trading_days)}일)")

    success, skipped, failed = 0, 0, 0

    for i, date in enumerate(target_days, start=1):
        db = SessionLocal()
        try:
            df = service.run(date, trading_days, db)  # trading_days는 버퍼 포함 전체 리스트
            if df.empty:
                skipped += 1
                print(f"  [{i}/{len(target_days)}] {date} - 데이터 없음 (건너뜀)")
            else:
                success += 1
                print(f"  [{i}/{len(target_days)}] {date} - 완료 ({len(df)}개 종목)")
        except ValueError as e:
            skipped += 1
            print(f"  [{i}/{len(target_days)}] {date} - 건너뜀: {e}")
        except Exception as e:
            failed += 1
            print(f"  [{i}/{len(target_days)}] {date} - 실패: {e}")
        finally:
            db.close()

        time.sleep(0.3)

    print(f"\n[백필 완료] 성공 {success} / 건너뜀 {skipped} / 실패 {failed}")


if __name__ == "__main__":
    if len(sys.argv) == 3:
        start_date, end_date = sys.argv[1], sys.argv[2]
    else:
        end = datetime.today()
        start = end - timedelta(days=365)
        start_date, end_date = start.strftime("%Y%m%d"), end.strftime("%Y%m%d")

    backfill(start_date, end_date)