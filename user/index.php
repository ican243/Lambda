<?php
require_once 'func.php';
startUserSession();

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

include 'includes/header.php';
?>

<h2>환영합니다, <?= htmlspecialchars($_SESSION['nickname']) ?>님!</h2>
<p><a href="logout.php" class="btn btn-outline-secondary btn-sm">로그아웃</a>
    <a href="trade.php" class="btn btn-outline-primary btn-sm">모의투자</a>
</p>

<div class="mb-4">
    <label class="form-label">종목 검색</label>
    <input type="text" id="search-input" class="form-control" placeholder="종목명을 입력하세요 (예: 삼성)">
    <div id="search-results" class="list-group mt-2"></div>
</div>

<table class="table table-striped mt-4">
    <thead>
        <tr>
            <th>종목명</th>
            <th>현재가</th>
            <th>전일대비</th>
            <th>등락률</th>
            <th>업데이트 시각</th>
            <th>삭제</th>
        </tr>
    </thead>
    <tbody id="price-table-body">
        <!-- JS가 여기에 데이터를 채워넣음 -->
    </tbody>
</table>

<script>
    // -----------------------------
    // 1. 관심종목 시세 목록 불러오기
    // -----------------------------
    async function loadPrices() {
        const res = await fetch('get_prices.php');
        const data = await res.json();

        const tbody = document.getElementById('price-table-body');
        tbody.innerHTML = '';

        data.forEach(item => {
            const changeClass = item.change_price > 0 ? 'text-danger' : (item.change_price < 0 ? 'text-primary' : '');
            const row = `
    <tr data-code="${item.stock_code}">
        <td><a href="stock_detail.php?code=${item.stock_code}">${item.stock_name}</a></td>
        <td class="price">${item.price ? Number(item.price).toLocaleString() + '원' : '-'}</td>
        <td class="change-price ${changeClass}">${item.change_price ?? '-'}</td>
        <td class="change-rate ${changeClass}">${item.change_rate ?? '-'}%</td>
        <td class="updated-at">${item.created_at ?? '-'}</td>
        <td><button class="btn btn-sm btn-outline-danger" onclick="removeFromWatchlist('${item.stock_code}')">삭제</button></td>
    </tr>
`;
            tbody.innerHTML += row;
        });
    }

    loadPrices();
    setInterval(loadPrices, 5000);

    //  실시간 갱신 

    const realtimeSocket = new WebSocket('ws://localhost:8080');

    realtimeSocket.onopen = () => {
        console.log('실시간 연결 성공');
    };

    realtimeSocket.onmessage = (event) => {
        const data = JSON.parse(event.data);
        updateRowRealtime(data);
    };

    realtimeSocket.onclose = () => {
        console.log('실시간 연결 끊김 (5초 폴링으로 백업 운영)');
    };

    function updateRowRealtime(data) {
        const row = document.querySelector(`tr[data-code="${data.stock_code}"]`);
        if (!row) return; // 내 관심종목에 없는 종목이면 무시

        const changeClass = data.change_price > 0 ? 'text-danger' : (data.change_price < 0 ? 'text-primary' : '');

        row.querySelector('.price').textContent = Number(data.price).toLocaleString() + '원';
        row.querySelector('.change-price').textContent = data.change_price;
        row.querySelector('.change-price').className = 'change-price ' + changeClass;
        row.querySelector('.change-rate').textContent = data.change_rate + '%';
        row.querySelector('.updated-at').textContent = new Date(data.created_at).toLocaleString();
    }

    // -----------------------------
    // 2. 종목 검색 (디바운스 적용)
    // -----------------------------
    const searchInput = document.getElementById('search-input');
    const searchResults = document.getElementById('search-results');
    let searchTimer = null;

    searchInput.addEventListener('input', () => {
        clearTimeout(searchTimer);
        const keyword = searchInput.value.trim();

        if (keyword === '') {
            searchResults.innerHTML = '';
            return;
        }

        searchTimer = setTimeout(async () => {
            const res = await fetch('watchlist_search.php?keyword=' + encodeURIComponent(keyword));
            const stocks = await res.json();

            searchResults.innerHTML = '';
            stocks.forEach(stock => {
                const item = document.createElement('div');
                item.className = 'list-group-item d-flex justify-content-between align-items-center';
                item.innerHTML = `
                <span>${stock.stock_name} (${stock.stock_code}) - ${stock.market}</span>
                <button class="btn btn-sm btn-success">추가</button>
            `;
                item.querySelector('button').addEventListener('click', () => addToWatchlist(stock.stock_code));
                searchResults.appendChild(item);
            });
        }, 300);
    });

    // -----------------------------
    // 3. 관심종목 추가
    // -----------------------------
    async function addToWatchlist(stockCode) {
        await fetch('watchlist_add.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'stock_code=' + encodeURIComponent(stockCode)
        });
        searchInput.value = '';
        searchResults.innerHTML = '';
        loadPrices();
    }

    // -----------------------------
    // 4. 관심종목 삭제
    // -----------------------------
    async function removeFromWatchlist(stockCode) {
        await fetch('watchlist_remove.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'stock_code=' + encodeURIComponent(stockCode)
        });
        loadPrices();
    }
</script>

<?php include 'includes/footer.php'; ?>