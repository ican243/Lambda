<?php
require_once 'func.php';
startUserSession();

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$stockCode = trim($_GET['code'] ?? '');
if ($stockCode === '') {
    header('Location: index.php');
    exit;
}

$stock = getSingleStockPrice($conn, $stockCode);
if (!$stock) {
    die('존재하지 않는 종목입니다.');
}

include 'includes/header.php';
?>

<script src="https://unpkg.com/lightweight-charts@4.1.3/dist/lightweight-charts.standalone.production.js"></script>

<a href="index.php" class="btn btn-outline-secondary btn-sm mb-3">← 목록으로</a>

<div class="mb-3">
    <h4 class="text-muted mb-1"><?= htmlspecialchars($stock['stock_name']) ?></h4>
    <h1 id="detail-price" class="fw-bold">
        <?= $stock['price'] ? number_format($stock['price']) . '원' : '-' ?>
    </h1>
    <p id="detail-change" class="<?= $stock['change_price'] > 0 ? 'text-danger' : 'text-primary' ?> fs-5">
        어제보다 <?= $stock['change_price'] > 0 ? '+' : '' ?><?= number_format($stock['change_price'] ?? 0) ?>원
        (<?= $stock['change_rate'] ?? 0 ?>%)
    </p>
</div>

<div class="mb-2">
    <button id="btn-candle" class="btn btn-sm btn-dark" onclick="switchChartType('candle')">캔들</button>
    <button id="btn-line" class="btn btn-sm btn-outline-dark" onclick="switchChartType('line')">라인</button>
</div>

<div id="chart-container" style="width:100%; height:400px;"></div>

<div class="row mt-4">
    <div class="col-6">
        <button class="btn btn-danger w-100 py-3 fs-5" onclick="showOrderForm('buy')">구매하기</button>
    </div>
    <div class="col-6">
        <button class="btn btn-primary w-100 py-3 fs-5" onclick="showOrderForm('sell')">판매하기</button>
    </div>
</div>

<div id="order-form" class="card p-3 mt-3" style="display:none;">
    <form id="trade-form-detail" action="trade_process.php" method="POST" class="row g-2 align-items-end">
        <input type="hidden" name="stock_code" value="<?= htmlspecialchars($stockCode) ?>">
        <div class="col-8">
            <label class="form-label">수량</label>
            <input type="number" name="quantity" class="form-control" min="1" required>
        </div>
        <div class="col-4">
            <button type="submit" name="order_type" id="order-type-btn" value="buy" class="btn btn-success w-100">주문</button>
        </div>
    </form>
</div>

<script>
    const stockCode = '<?= $stockCode ?>';
    let candleData = [];
    let currentType = 'candle';
    let series;

    // -----------------------------
    // 차트 초기 세팅
    // -----------------------------
    const chart = LightweightCharts.createChart(document.getElementById('chart-container'), {
        layout: {
            background: {
                color: '#ffffff'
            },
            textColor: '#333'
        },
        grid: {
            vertLines: {
                color: '#eee'
            },
            horzLines: {
                color: '#eee'
            }
        },
        timeScale: {
            timeVisible: true,
            secondsVisible: false
        },
    });

    function createCandleSeries() {
        return chart.addCandlestickSeries({
            upColor: '#d24f45',
            downColor: '#1261c4',
            borderVisible: false,
            wickUpColor: '#d24f45',
            wickDownColor: '#1261c4',
        });
    }

    function createLineSeries() {
        return chart.addLineSeries({
            color: '#d24f45',
            lineWidth: 2
        });
    }

    series = createCandleSeries();

    // -----------------------------
    // 데이터 로드 + 그리기
    // -----------------------------
    async function loadChart() {
        const res = await fetch('get_stock_chart.php?stock_code=' + stockCode);
        candleData = await res.json();
        renderChart();
    }

    function renderChart() {
        if (currentType === 'candle') {
            series.setData(candleData);
        } else {
            // 라인차트는 종가(close)만 사용
            const lineData = candleData.map(c => ({
                time: c.time,
                value: c.close
            }));
            series.setData(lineData);
        }
    }

    function switchChartType(type) {
        if (type === currentType) return;
        currentType = type;

        chart.removeSeries(series);
        series = (type === 'candle') ? createCandleSeries() : createLineSeries();
        renderChart();

        document.getElementById('btn-candle').className = type === 'candle' ? 'btn btn-sm btn-dark' : 'btn btn-sm btn-outline-dark';
        document.getElementById('btn-line').className = type === 'line' ? 'btn btn-sm btn-dark' : 'btn btn-sm btn-outline-dark';
    }

    // -----------------------------
    // 주문 폼 토글
    // -----------------------------
    function showOrderForm(type) {
        document.getElementById('order-form').style.display = 'block';
        document.getElementById('order-type-btn').value = type;
        document.getElementById('order-type-btn').textContent = (type === 'buy') ? '매수 확정' : '매도 확정';
        document.getElementById('order-type-btn').className = 'btn w-100 ' + (type === 'buy' ? 'btn-danger' : 'btn-primary');
    }
    document.getElementById('trade-form-detail')?.addEventListener('submit', function() {
        this.querySelectorAll('button').forEach(btn => btn.disabled = true);
    });

    // -----------------------------
    // 실시간 갱신 (가격 + 캔들 동시 업데이트)
    // -----------------------------
    const detailSocket = new WebSocket('ws://localhost:8080');

    detailSocket.onmessage = (event) => {
        const d = JSON.parse(event.data);
        if (d.stock_code !== stockCode) return;

        // 1. 상단 가격 갱신
        document.getElementById('detail-price').textContent = Number(d.price).toLocaleString() + '원';
        const changeEl = document.getElementById('detail-change');
        changeEl.textContent = `어제보다 ${d.change_price > 0 ? '+' : ''}${d.change_price}원 (${d.change_rate}%)`;
        changeEl.className = (d.change_price > 0 ? 'text-danger' : 'text-primary') + ' fs-5';

        // 2. 캔들 즉시 업데이트 (전체 재조회 없이)
        updateLastCandle(d.price, Math.floor(new Date(d.created_at).getTime() / 1000));
    };

    function updateLastCandle(price, timestamp) {
        if (candleData.length === 0) return;

        // 현재 분(minute)을 구해서, 마지막 캔들이 같은 분인지 확인
        const currentMinute = Math.floor(timestamp / 60) * 60;
        const lastCandle = candleData[candleData.length - 1];

        if (lastCandle.time === currentMinute) {
            // 같은 분 안이면: 기존 캔들의 고가/저가/종가만 갱신
            lastCandle.high = Math.max(lastCandle.high, price);
            lastCandle.low = Math.min(lastCandle.low, price);
            lastCandle.close = price;
        } else {
            // 새로운 분으로 넘어갔으면: 새 캔들 추가
            candleData.push({
                time: currentMinute,
                open: price,
                high: price,
                low: price,
                close: price,
            });
        }

        // 화면에 반영 (전체가 아니라 마지막 캔들 하나만 갱신, 훨씬 가벼움)
        if (currentType === 'candle') {
            series.update(lastCandle.time === currentMinute ? lastCandle : candleData[candleData.length - 1]);
        } else {
            series.update({
                time: currentMinute,
                value: price
            });
        }
    }
    loadChart();
</script>

<?php include 'includes/footer.php'; ?>