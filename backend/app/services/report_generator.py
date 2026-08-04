from io import BytesIO
import os
from statistics import mean

import matplotlib
matplotlib.use("Agg")
import matplotlib.pyplot as plt
import matplotlib.ticker as mticker

from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER, TA_LEFT, TA_RIGHT
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import cm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import (
    SimpleDocTemplate,
    Paragraph,
    Spacer,
    Table,
    TableStyle,
    LongTable,
    HRFlowable,
    Image,
    KeepTogether,
)
from reportlab.pdfgen import canvas

# ===========================
# Font
# ===========================

FONT_PATH = "C:/Windows/Fonts/malgun.ttf"
FONT_BOLD_PATH = "C:/Windows/Fonts/malgunbd.ttf"

if os.path.exists(FONT_PATH):
    pdfmetrics.registerFont(TTFont("Malgun", FONT_PATH))
    pdfmetrics.registerFont(TTFont("MalgunBold", FONT_BOLD_PATH))
    FONT = "Malgun"
    FONT_BOLD = "MalgunBold"
else:
    FONT = "Helvetica"
    FONT_BOLD = "Helvetica-Bold"

plt.rcParams["font.family"] = "Malgun Gothic"
plt.rcParams["axes.unicode_minus"] = False

# ===========================
# Color Theme
# ===========================

PRIMARY     = colors.HexColor("#0F4C81")
ACCENT      = colors.HexColor("#C9A84C")   # 골드 포인트
SUCCESS     = colors.HexColor("#1565C0")
DANGER      = colors.HexColor("#C62828")
HEADER_BG   = colors.HexColor("#F3F6FA")
ROW1        = colors.white
ROW2        = colors.HexColor("#F8FAFC")
BORDER      = colors.HexColor("#D6DCE5")
SUBTEXT     = colors.HexColor("#666666")
DARK_BG     = colors.HexColor("#0F1F3D")

# ===========================
# Styles
# ===========================

styles = getSampleStyleSheet()

TITLE_STYLE = ParagraphStyle(
    "Title",
    fontName=FONT_BOLD,
    fontSize=26,
    alignment=TA_CENTER,
    textColor=PRIMARY,
    spaceAfter=6,
)

SUBTITLE_STYLE = ParagraphStyle(
    "Subtitle",
    fontName=FONT,
    fontSize=10,
    alignment=TA_CENTER,
    textColor=SUBTEXT,
    spaceAfter=16,
    leading=16,
)

SECTION_STYLE = ParagraphStyle(
    "Section",
    fontName=FONT_BOLD,
    fontSize=14,
    textColor=PRIMARY,
    spaceBefore=14,
    spaceAfter=8,
    borderPad=(0, 0, 0, 6),
)

BODY_STYLE = ParagraphStyle(
    "Body",
    fontName=FONT,
    fontSize=10,
    leading=18,
    textColor=colors.HexColor("#222222"),
)

SMALL_STYLE = ParagraphStyle(
    "Small",
    fontName=FONT,
    fontSize=8,
    textColor=SUBTEXT,
    leading=13,
)

# ===========================
# Numbered Canvas
# ===========================

class NumberedCanvas(canvas.Canvas):

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self._saved_page_states = []

    def showPage(self):
        self._saved_page_states.append(dict(self.__dict__))
        self._startPage()

    def save(self):
        page_count = len(self._saved_page_states)
        for state in self._saved_page_states:
            self.__dict__.update(state)
            self.draw_footer(page_count)
            super().showPage()
        super().save()

    def draw_footer(self, page_count):
        # 하단 구분선
        self.setStrokeColor(colors.HexColor("#D6DCE5"))
        self.setLineWidth(0.5)
        self.line(1.8 * cm, 1.6 * cm, 19.5 * cm, 1.6 * cm)
        # 페이지 번호
        self.setFont(FONT, 8)
        self.setFillColor(SUBTEXT)
        self.drawRightString(
            19.5 * cm, 1.0 * cm,
            f"{self._pageNumber} / {page_count}",
        )
        # 좌측 브랜드 워터마크
        self.drawString(
            1.8 * cm, 1.0 * cm,
            "Backtest Platform  ·  본 리포트는 투자를 권유하지 않습니다.",
        )

# ===========================
# Logo
# ===========================

def get_logo():
    candidates = [
        "assets/logo.png",
        "app/assets/logo.png",
        "backend/assets/logo.png",
    ]
    for path in candidates:
        if os.path.exists(path):
            return Image(path, width=2.2 * cm, height=2.2 * cm)
    return None

# ===========================
# Utils
# ===========================

def money(value):
    if value is None:
        return "-"
    return f"{value:,.0f}원"

def percent(value, sign=False):
    if value is None:
        return "-"
    prefix = "+" if sign and value > 0 else ""
    return f"{prefix}{value:.2f}%"

def number(value):
    if value is None:
        return "-"
    return f"{value:.2f}"

def colored_profit(value):
    if value is None:
        return Paragraph("-", BODY_STYLE)
    color = "#1565C0" if value >= 0 else "#C62828"
    return Paragraph(
        f'<font color="{color}">{value:+,.0f}원</font>',
        BODY_STYLE,
    )

def divider():
    return HRFlowable(
        width="100%",
        thickness=0.8,
        color=BORDER,
        spaceBefore=5,
        spaceAfter=10,
    )

def section_divider():
    """섹션 헤더 앞 골드 포인트 막대 + 제목."""
    return HRFlowable(
        width="100%",
        thickness=2,
        color=ACCENT,
        spaceBefore=14,
        spaceAfter=8,
    )

# ===========================
# KPI Cards
# ===========================

def create_kpi_card(title, value, sub=None, value_color=None):
    vc = value_color or PRIMARY
    rows = [
        [Paragraph(title, ParagraphStyle(
            "kt", fontName=FONT, fontSize=9,
            textColor=SUBTEXT, alignment=TA_CENTER,
        ))],
        [Paragraph(value, ParagraphStyle(
            "kv", fontName=FONT_BOLD, fontSize=17,
            textColor=vc, alignment=TA_CENTER,
        ))],
    ]
    if sub:
        rows.append([Paragraph(sub, ParagraphStyle(
            "ks", fontName=FONT, fontSize=8,
            textColor=SUBTEXT, alignment=TA_CENTER,
        ))])

    heights = [0.65*cm, 0.95*cm, 0.45*cm] if sub else [0.65*cm, 0.95*cm]
    t = Table(rows, colWidths=[4.0*cm], rowHeights=heights)
    t.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, -1), HEADER_BG),
        ("BOX", (0, 0), (-1, -1), 1.2, PRIMARY),
        ("TOPPADDING", (0, 0), (-1, -1), 6),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 6),
        ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
    ]))
    return t


def create_kpi_section(result):
    total_return = result.get("total_return")
    mdd = result.get("mdd")
    sharpe = result.get("sharpe_ratio")
    win_rate = result.get("win_rate")

    def ret_color(v):
        if v is None: return PRIMARY
        return SUCCESS if v >= 0 else DANGER

    cards = [
        create_kpi_card(
            "전략 수익률",
            percent(total_return, sign=True),
            "Strategy Return",
            ret_color(total_return),
        ),
        create_kpi_card(
            "승률",
            percent(win_rate),
            "Win Rate",
        ),
        create_kpi_card(
            "MDD",
            percent(mdd),
            "Max Drawdown",
            DANGER if mdd and mdd < 0 else PRIMARY,
        ),
        create_kpi_card(
            "Sharpe Ratio",
            number(sharpe),
            "Risk-Adjusted",
            SUCCESS if sharpe and sharpe >= 1 else PRIMARY,
        ),
    ]

    t = Table([cards], colWidths=[4.2*cm, 4.2*cm, 4.2*cm, 4.2*cm])
    t.setStyle(TableStyle([
        ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
        ("LEFTPADDING", (0, 0), (-1, -1), 4),
        ("RIGHTPADDING", (0, 0), (-1, -1), 4),
    ]))
    return t

# ===========================
# Benchmark Table
# ===========================

def create_benchmark_table(result):
    """전략 vs 매수후보유 vs 코스피 비교 표."""
    total_return = result.get("total_return")
    buy_hold = result.get("buy_hold_return")
    benchmark = result.get("benchmark_return")
    excess = result.get("excess_return")

    def sign_p(v):
        if v is None: return "-"
        color = "#1565C0" if v >= 0 else "#C62828"
        prefix = "+" if v > 0 else ""
        return Paragraph(f'<font color="{color}">{prefix}{v:.2f}%</font>', BODY_STYLE)

    data = [
        ["구분", "수익률", "비고"],
        ["전략", sign_p(total_return), "거래비용 반영"],
        ["매수후보유", sign_p(buy_hold), "동일 종목 단순 보유"],
        ["코스피 지수", sign_p(benchmark), "벤치마크"],
        ["초과수익 (전략-지수)", sign_p(excess), "양수면 시장 초과"],
    ]

    t = Table(data, colWidths=[5.5*cm, 4*cm, 6.5*cm])
    t.setStyle(TableStyle([
        ("FONTNAME", (0, 0), (-1, -1), FONT),
        ("FONTNAME", (0, 0), (-1, 0), FONT_BOLD),
        ("FONTSIZE", (0, 0), (-1, -1), 10),
        ("BACKGROUND", (0, 0), (-1, 0), PRIMARY),
        ("TEXTCOLOR", (0, 0), (-1, 0), colors.white),
        ("ROWBACKGROUNDS", (0, 1), (-1, -1), [ROW1, ROW2]),
        ("GRID", (0, 0), (-1, -1), 0.5, BORDER),
        ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
        ("TOPPADDING", (0, 0), (-1, -1), 7),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 7),
    ]))
    return t

# ===========================
# Trade Statistics
# ===========================

def calculate_trade_statistics(trades):
    if not trades:
        return {"count": 0, "win": 0, "lose": 0, "avg_profit": 0,
                "max_profit": 0, "min_profit": 0,
                "total_profit": 0, "avg_cost": 0}

    profits = [t.profit for t in trades if t.profit is not None]
    costs = [t.cost for t in trades if t.cost is not None]
    wins = [p for p in profits if p > 0]
    loses = [p for p in profits if p <= 0]

    return {
        "count": len(trades),
        "win": len(wins),
        "lose": len(loses),
        "avg_profit": mean(profits) if profits else 0,
        "max_profit": max(profits) if profits else 0,
        "min_profit": min(profits) if profits else 0,
        "total_profit": sum(profits) if profits else 0,
        "avg_cost": mean(costs) if costs else 0,
    }


def create_statistics_table(stats):
    data = [
        ["항목", "값"],
        ["총 거래 횟수", f"{stats['count']}회"],
        ["수익 거래 / 손실 거래", f"{stats['win']}회 / {stats['lose']}회"],
        ["총 실현 손익", money(stats["total_profit"])],
        ["평균 손익 (거래당)", money(stats["avg_profit"])],
        ["최대 수익 거래", money(stats["max_profit"])],
        ["최대 손실 거래", money(stats["min_profit"])],
        ["평균 거래 비용", money(stats["avg_cost"])],
    ]

    t = Table(data, colWidths=[6*cm, 5*cm])
    t.setStyle(TableStyle([
        ("FONTNAME", (0, 0), (-1, -1), FONT),
        ("FONTNAME", (0, 0), (-1, 0), FONT_BOLD),
        ("FONTSIZE", (0, 0), (-1, -1), 10),
        ("BACKGROUND", (0, 0), (-1, 0), PRIMARY),
        ("TEXTCOLOR", (0, 0), (-1, 0), colors.white),
        ("ROWBACKGROUNDS", (0, 1), (-1, -1), [ROW1, ROW2]),
        ("GRID", (0, 0), (-1, -1), 0.5, BORDER),
        ("TOPPADDING", (0, 0), (-1, -1), 7),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 7),
    ]))
    return t

# ===========================
# Auto Analysis Comment
# ===========================

def create_analysis_comment(result, stats):
    comments = []

    total_return = result.get("total_return")
    buy_hold = result.get("buy_hold_return")
    benchmark = result.get("benchmark_return")
    mdd = result.get("mdd")
    sharpe = result.get("sharpe_ratio")

    if total_return is not None:
        direction = "수익" if total_return >= 0 else "손실"
        comments.append(
            f"전략은 백테스트 기간 동안 {total_return:.2f}%의 {direction}을 기록했습니다."
        )

    if buy_hold is not None and total_return is not None:
        diff = total_return - buy_hold
        if diff > 0:
            comments.append(
                f"단순 매수후보유({buy_hold:.2f}%) 대비 {diff:.2f}%p 초과 수익을 달성했습니다."
            )
        else:
            comments.append(
                f"단순 매수후보유({buy_hold:.2f}%) 대비 {abs(diff):.2f}%p 낮은 성과를 보였습니다. "
                "파라미터 조정 또는 다른 전략 검토를 권장합니다."
            )

    if benchmark is not None and total_return is not None:
        diff = total_return - benchmark
        if diff > 0:
            comments.append(
                f"코스피 지수({benchmark:.2f}%) 대비 {diff:.2f}%p 초과 수익으로, "
                "시장 평균을 상회했습니다."
            )
        else:
            comments.append(
                f"코스피 지수({benchmark:.2f}%) 대비 {abs(diff):.2f}%p 낮은 성과로, "
                "시장 평균을 하회했습니다."
            )

    if mdd is not None:
        if mdd > -10:
            comments.append(f"MDD {mdd:.2f}%로 비교적 안정적인 자산 방어를 보였습니다.")
        elif mdd > -20:
            comments.append(f"MDD {mdd:.2f}%로 중간 수준의 낙폭이 발생했습니다.")
        else:
            comments.append(
                f"MDD {mdd:.2f}%로 상당한 낙폭이 발생했습니다. "
                "손절 조건 추가 등 리스크 관리 보완이 필요합니다."
            )

    if sharpe is not None:
        if sharpe >= 2:
            comments.append(f"Sharpe Ratio {sharpe:.2f}로 우수한 위험 조정 수익률을 보여줍니다.")
        elif sharpe >= 1:
            comments.append(f"Sharpe Ratio {sharpe:.2f}로 양호한 위험 대비 수익성을 보입니다.")
        else:
            comments.append(
                f"Sharpe Ratio {sharpe:.2f}로 변동성 대비 수익이 낮습니다. "
                "전략 개선이 권장됩니다."
            )

    comments.append(f"총 {stats['count']}회 거래 중 수익 {stats['win']}회, 손실 {stats['lose']}회가 발생했습니다.")

    return "\n".join(["• " + c for c in comments])

# ===========================
# Charts
# ===========================

def create_equity_curve_chart(equity_curve):
    """자산 곡선 차트 (전략 vs 매수후보유)."""
    if not equity_curve:
        return None

    dates = [x["date"] for x in equity_curve]
    strategy = [x["strategy"] for x in equity_curve]
    buy_hold = [x["buy_hold"] for x in equity_curve]
    idx = range(len(dates))
    step = max(1, len(dates) // 8)

    fig, ax = plt.subplots(figsize=(7.5, 3.2))

    ax.plot(idx, strategy, color="#0F4C81", linewidth=1.8, label="전략", zorder=3)
    ax.plot(idx, buy_hold, color="#C9A84C", linewidth=1.5,
            linestyle="--", label="매수후보유", zorder=2)
    ax.axhline(y=10_000_000, color="#94a3b8", linewidth=0.8,
               linestyle=":", label="초기자본", zorder=1)

    ax.fill_between(
        idx, strategy, 10_000_000,
        where=[s >= 10_000_000 for s in strategy],
        alpha=0.08, color="#0F4C81",
    )
    ax.fill_between(
        idx, strategy, 10_000_000,
        where=[s < 10_000_000 for s in strategy],
        alpha=0.08, color="#C62828",
    )

    ax.set_xticks(range(0, len(dates), step))
    ax.set_xticklabels(
        [dates[i][2:].replace("-", "/") for i in range(0, len(dates), step)],
        fontsize=8,
    )
    ax.yaxis.set_major_formatter(
        mticker.FuncFormatter(lambda v, _: f"{int(v/10000)}만")
    )
    ax.tick_params(axis="y", labelsize=8)
    ax.set_title("자산 곡선", fontsize=11, fontweight="bold", pad=10)
    ax.legend(fontsize=8, loc="upper left")
    ax.grid(axis="y", linestyle="--", alpha=0.35)
    ax.set_facecolor("#FAFBFC")
    fig.patch.set_facecolor("white")
    plt.tight_layout()

    buf = BytesIO()
    fig.savefig(buf, format="png", dpi=150, bbox_inches="tight")
    plt.close(fig)
    buf.seek(0)
    return Image(buf, width=16 * cm, height=6.8 * cm)


def create_drawdown_chart(equity_curve):
    """Drawdown 차트."""
    if not equity_curve:
        return None

    values = [x["strategy"] for x in equity_curve]
    dates = [x["date"] for x in equity_curve]
    idx = range(len(dates))
    step = max(1, len(dates) // 8)

    peak = values[0]
    drawdown = []
    for v in values:
        if v > peak:
            peak = v
        drawdown.append((v - peak) / peak * 100)

    fig, ax = plt.subplots(figsize=(7.5, 2.5))
    ax.fill_between(idx, drawdown, 0, alpha=0.35, color="#C62828")
    ax.plot(idx, drawdown, color="#C62828", linewidth=1.2)

    ax.set_xticks(range(0, len(dates), step))
    ax.set_xticklabels(
        [dates[i][2:].replace("-", "/") for i in range(0, len(dates), step)],
        fontsize=8,
    )
    ax.yaxis.set_major_formatter(
        mticker.FuncFormatter(lambda v, _: f"{v:.1f}%")
    )
    ax.tick_params(axis="y", labelsize=8)
    ax.set_title("Drawdown", fontsize=11, fontweight="bold", pad=10)
    ax.grid(axis="y", linestyle="--", alpha=0.35)
    ax.set_facecolor("#FAFBFC")
    fig.patch.set_facecolor("white")
    plt.tight_layout()

    buf = BytesIO()
    fig.savefig(buf, format="png", dpi=150, bbox_inches="tight")
    plt.close(fig)
    buf.seek(0)
    return Image(buf, width=16 * cm, height=5.4 * cm)

# ===========================
# Trade Table (LongTable)
# ===========================

def calculate_trade_return(trade):
    if (trade.entry_price is None or trade.exit_price is None
            or trade.entry_price == 0):
        return None
    return (trade.exit_price - trade.entry_price) / trade.entry_price * 100


def create_trade_table(trades):
    header = ["No", "매수일", "매수가", "매도일", "매도가", "손익", "수익률", "비용"]
    data = [header]

    for idx, trade in enumerate(trades, start=1):
        trade_return = calculate_trade_return(trade)
        data.append([
            str(idx),
            str(trade.entry_date) if trade.entry_date else "-",
            money(trade.entry_price),
            str(trade.exit_date) if trade.exit_date else "-",
            money(trade.exit_price),
            colored_profit(trade.profit),
            Paragraph(percent(trade_return, sign=True), BODY_STYLE),
            money(trade.cost),
        ])

    t = LongTable(
        data,
        repeatRows=1,
        colWidths=[0.8*cm, 2.2*cm, 2.4*cm, 2.2*cm, 2.4*cm, 2.4*cm, 1.8*cm, 2.0*cm],
    )
    t.setStyle(TableStyle([
        ("FONTNAME", (0, 0), (-1, -1), FONT),
        ("FONTNAME", (0, 0), (-1, 0), FONT_BOLD),
        ("FONTSIZE", (0, 0), (-1, -1), 9),
        ("BACKGROUND", (0, 0), (-1, 0), PRIMARY),
        ("TEXTCOLOR", (0, 0), (-1, 0), colors.white),
        ("GRID", (0, 0), (-1, -1), 0.4, BORDER),
        ("ROWBACKGROUNDS", (0, 1), (-1, -1), [ROW1, ROW2]),
        ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
        ("ALIGN", (0, 0), (0, -1), "CENTER"),
        ("ALIGN", (2, 1), (-1, -1), "RIGHT"),
        ("TOPPADDING", (0, 0), (-1, -1), 6),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 6),
    ]))
    return t

# ===========================
# Report Story Builder
# ===========================

def create_report_story(
    run_id,
    ticker,
    strategy_name,
    start_date,
    end_date,
    result,
    trades,
    equity_curve=None,
):
    story = []

    # ---------------------------
    # Header
    # ---------------------------
    logo = get_logo()
    if logo:
        header_table = Table(
            [[logo, Paragraph("백테스트 분석 리포트", TITLE_STYLE)]],
            colWidths=[3*cm, 13*cm],
        )
        header_table.setStyle(TableStyle([
            ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
        ]))
        story.append(header_table)
    else:
        story.append(Paragraph("백테스트 분석 리포트", TITLE_STYLE))

    story.append(Paragraph(
        f"종목 : {ticker}&nbsp;&nbsp;|&nbsp;&nbsp;"
        f"전략 : {strategy_name}&nbsp;&nbsp;|&nbsp;&nbsp;"
        f"기간 : {start_date} ~ {end_date}&nbsp;&nbsp;|&nbsp;&nbsp;"
        f"Run ID : {run_id}",
        SUBTITLE_STYLE,
    ))
    story.append(divider())

    # ---------------------------
    # KPI Cards
    # ---------------------------
    story.append(Paragraph("핵심 성과 지표", SECTION_STYLE))
    story.append(create_kpi_section(result))
    story.append(Spacer(1, 18))

    # ---------------------------
    # Benchmark Comparison
    # ---------------------------
    story.append(Paragraph("벤치마크 비교", SECTION_STYLE))
    story.append(create_benchmark_table(result))
    story.append(Spacer(1, 18))

    # ---------------------------
    # Trade Statistics
    # ---------------------------
    stats = calculate_trade_statistics(trades)
    story.append(Paragraph("거래 통계", SECTION_STYLE))
    story.append(create_statistics_table(stats))
    story.append(Spacer(1, 18))

    # ---------------------------
    # Performance Analysis
    # ---------------------------
    story.append(Paragraph("성과 분석", SECTION_STYLE))
    analysis_text = create_analysis_comment(result, stats)
    story.append(Paragraph(analysis_text.replace("\n", "<br/>"), BODY_STYLE))
    story.append(Spacer(1, 18))

    # ---------------------------
    # Equity Curve Chart
    # ---------------------------
    if equity_curve:
        equity_chart = create_equity_curve_chart(equity_curve)
        drawdown_chart = create_drawdown_chart(equity_curve)

        if equity_chart:
            story.append(KeepTogether([
                Paragraph("자산 변화 그래프", SECTION_STYLE),
                equity_chart,
            ]))
            story.append(Spacer(1, 14))

        if drawdown_chart:
            story.append(KeepTogether([
                Paragraph("Drawdown 분석", SECTION_STYLE),
                drawdown_chart,
            ]))
            story.append(Spacer(1, 18))

    # ---------------------------
    # Trade History
    # ---------------------------
    story.append(Paragraph("거래 내역", SECTION_STYLE))
    if trades:
        story.append(create_trade_table(trades))
    else:
        story.append(Paragraph("매매 내역이 없습니다.", BODY_STYLE))

    story.append(Spacer(1, 30))

    # ---------------------------
    # Disclaimer
    # ---------------------------
    story.append(HRFlowable(width="100%", thickness=0.5, color=BORDER))
    story.append(Paragraph(
        "※ 본 리포트는 과거 데이터 기반의 백테스트 결과입니다. "
        "실제 투자 결과를 보장하지 않으며, 시장 상황 및 슬리피지에 따라 결과는 달라질 수 있습니다. "
        "거래비용(세금·수수료)은 반영되었으나 슬리피지는 미반영입니다.",
        SMALL_STYLE,
    ))

    return story

# ===========================
# PDF Generator
# ===========================

def generate_backtest_report(
    run_id: int,
    ticker: str,
    strategy_name: str,
    start_date: str,
    end_date: str,
    result: dict,
    trades: list,
    equity_curve: list | None = None,
) -> bytes:
    """
    백테스트 결과 PDF 생성
    FastAPI Response에서 바로 사용할 수 있도록 bytes 반환
    """
    buffer = BytesIO()

    doc = SimpleDocTemplate(
        buffer,
        pagesize=A4,
        rightMargin=1.8 * cm,
        leftMargin=1.8 * cm,
        topMargin=1.8 * cm,
        bottomMargin=2.2 * cm,
        title=f"Backtest Report - {ticker} {strategy_name}",
        author="Backtest Platform",
    )

    story = create_report_story(
        run_id=run_id,
        ticker=ticker,
        strategy_name=strategy_name,
        start_date=start_date,
        end_date=end_date,
        result=result,
        trades=trades,
        equity_curve=equity_curve,
    )

    doc.build(story, canvasmaker=NumberedCanvas)

    pdf_bytes = buffer.getvalue()
    buffer.close()
    return pdf_bytes