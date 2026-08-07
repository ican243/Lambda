"""
지표 계산 함수들과 메타데이터를 한곳에 모아둔 레지스트리.
사용자가 프론트엔드에서 지표를 선택하면, 여기 등록된 함수들만 조합해서 점수를 계산한다.

기존에는 각 지표가 0 또는 1(조건 충족 여부)만 반환하는 체크리스트 방식이었음.
지금은 "얼마나 강하게" 조건을 만족하는지를 0~1 사이 연속값으로 표현하도록 개선함.
- 0에 가까움: 조건과 거리가 멈 (약한 신호)
- 1에 가까움: 조건을 강하게 충족 (강한 신호)
이렇게 하면 여러 지표를 합산했을 때 "어느 정도 강도로 조건들이 겹치는지"가
점수에 자연스럽게 반영되어, 계단식으로 뚝뚝 끊기던 신호가 부드러워짐.
"""
import pandas as pd


def compute_trend_score(df: pd.DataFrame, params: dict) -> pd.Series:
    """단기/장기 이평선 이격도를 0~1로 스케일링.
    이격도가 클수록(단기가 장기보다 많이 위에 있을수록) 점수가 1에 가까워짐."""
    ma_short = params.get("ma_short", 20)
    ma_long = params.get("ma_long", 60)
    sensitivity = params.get("trend_sensitivity", 10)  # 이격도를 점수로 변환할 때의 민감도

    s = df["close"].rolling(ma_short).mean()
    l = df["close"].rolling(ma_long).mean()
    gap_pct = (s - l) / l

    # gap_pct=0(이평선 겹침)일 때 0.5, 위로 벌어질수록 1에 가까워지고
    # 아래로 벌어질수록 0에 가까워지는 시그모이드형 스케일링
    score = 0.5 + (gap_pct * sensitivity)
    return score.clip(0, 1).fillna(0)


def compute_momentum_score(df: pd.DataFrame, params: dict) -> pd.Series:
    """모멘텀 랭킹 퍼센타일을 그대로 연속 점수로 사용 (이미 0~1 사이 값이라 추가 변환 불필요).
    상위 몇 %인지가 그대로 점수 강도가 됨 (60% 지점과 99% 지점의 차이가 반영됨)."""
    if "momentum_rank_pct" not in df.columns:
        raise ValueError("momentum_rank_pct 컬럼이 없습니다. 사전 병합이 필요합니다.")
    return df["momentum_rank_pct"].fillna(0).clip(0, 1)


def compute_volume_score(df: pd.DataFrame, params: dict) -> pd.Series:
    """평균 거래량 대비 배율을 0~1로 스케일링.
    평균과 같으면 0, multiplier배 이상이면 1."""
    period = params.get("volume_ma_period", 20)
    multiplier = params.get("volume_multiplier", 1.5)

    volume_ma = df["volume"].rolling(period).mean()
    ratio = df["volume"] / volume_ma
    # ratio=1(평균)일 때 0, ratio=multiplier일 때 1이 되도록 선형 스케일링
    score = (ratio - 1) / (multiplier - 1)
    return score.clip(0, 1).fillna(0)


def compute_volatility_score(df: pd.DataFrame, params: dict) -> pd.Series:
    """ATR%가 적정 구간(low~high) 중앙에 가까울수록 1, 구간 밖으로 벗어날수록 0에 가까운
    연속 점수 (삼각형 형태 스코어링)."""
    period = params.get("atr_period", 14)
    low = params.get("atr_low_pct", 0.01)
    high = params.get("atr_high_pct", 0.06)

    high_low = df["high"] - df["low"]
    high_close = (df["high"] - df["close"].shift()).abs()
    low_close = (df["low"] - df["close"].shift()).abs()
    tr = pd.concat([high_low, high_close, low_close], axis=1).max(axis=1)
    atr_pct = tr.rolling(period).mean() / df["close"]

    mid = (low + high) / 2
    half_range = (high - low) / 2
    distance_from_mid = (atr_pct - mid).abs()
    score = 1 - (distance_from_mid / half_range)
    return score.clip(0, 1).fillna(0)


def compute_market_regime_score(df: pd.DataFrame, params: dict) -> pd.Series:
    """시장 국면은 상승/하락 이분법적 성격이 강해 이진값 유지.
    (개선하려면 지수의 MA200 대비 이격도를 연속값으로 쓸 수 있음 - 추후 과제)"""
    if "market_bullish" not in df.columns:
        raise ValueError("market_bullish 컬럼이 없습니다. 사전 병합이 필요합니다.")
    return df["market_bullish"].fillna(False).astype(float)


FACTOR_REGISTRY = {
    "trend": {
        "label": "추세 (MA20/MA60 이격도)",
        "description": "단기 이동평균이 장기 이동평균 대비 얼마나 위에 있는지 (연속값)",
        "compute": compute_trend_score,
        "default_weight": 35,
        "requires": [],
    },
    "momentum": {
        "label": "모멘텀 (상대 강도 퍼센타일)",
        "description": "관심 종목군 내에서 최근 상승률이 상위 몇 %인지 (연속값)",
        "compute": compute_momentum_score,
        "default_weight": 25,
        "requires": ["momentum_rank_pct"],
    },
    "volume": {
        "label": "거래량 급증 정도",
        "description": "평균 대비 거래량이 얼마나 늘어났는지 (연속값)",
        "compute": compute_volume_score,
        "default_weight": 20,
        "requires": [],
    },
    "volatility": {
        "label": "변동성 적정 구간",
        "description": "적정 변동성 구간 중앙에 얼마나 가까운지 (연속값)",
        "compute": compute_volatility_score,
        "default_weight": 10,
        "requires": [],
    },
    "market_regime": {
        "label": "시장 상황 (코스피 MA200)",
        "description": "코스피 지수 자체가 장기 상승 국면인지",
        "compute": compute_market_regime_score,
        "default_weight": 10,
        "requires": ["market_bullish"],
    },
}