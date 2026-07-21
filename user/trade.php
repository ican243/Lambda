<?php
require_once 'func.php';
startUserSession();

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$account = getMyAccount($conn, $_SESSION['user_id']);
$holdings = getMyHoldings($conn, $_SESSION['user_id']);

include 'includes/header.php';
?>

<h2>모의투자</h2>
<p><a href="index.php" class="btn btn-outline-secondary btn-sm">대시보드로</a></p>

<?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($_GET['error']) ?></div>
<?php endif; ?>
<?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success"><?= htmlspecialchars($_GET['success']) ?></div>
<?php endif; ?>

<div class="card p-3 mb-4">
    <h5>보유 현금</h5>
    <h3><?= number_format($account['cash_balance']) ?>원</h3>
</div>

<div class="card p-3 mb-4">
    <h5>주문하기</h5>
    <form action="trade_process.php" method="POST" class="row g-2">
        <div class="col-md-3">
            <input type="text" name="stock_code" class="form-control" placeholder="종목코드 (예: 005930)" required>
        </div>
        <div class="col-md-3">
            <input type="number" name="quantity" class="form-control" placeholder="수량" required min="1">
        </div>
        <div class="col-md-3">
            <button type="submit" name="order_type" value="buy" class="btn btn-danger w-100">매수</button>
        </div>
        <div class="col-md-3">
            <button type="submit" name="order_type" value="sell" class="btn btn-primary w-100">매도</button>
        </div>
    </form>
</div>

<h5>보유 종목</h5>
<table class="table table-bordered">
    <thead>
        <tr>
            <th>종목명</th>
            <th>보유수량</th>
            <th>평균단가</th>
            <th>현재가</th>
            <th>평가손익</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($holdings as $h):
            $profit = $h['current_price'] ? ($h['current_price'] - $h['avg_price']) * $h['quantity'] : null;
            $profitClass = $profit > 0 ? 'text-danger' : ($profit < 0 ? 'text-primary' : '');
        ?>
            <tr>
                <td><?= htmlspecialchars($h['stock_name']) ?></td>
                <td><?= number_format($h['quantity']) ?>주</td>
                <td><?= number_format($h['avg_price']) ?>원</td>
                <td><?= $h['current_price'] ? number_format($h['current_price']) . '원' : '-' ?></td>
                <td class="<?= $profitClass ?>"><?= $profit !== null ? number_format($profit) . '원' : '-' ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>


<h5 class="mt-5">주문 내역</h5>
<table class="table table-bordered">
    <thead>
        <tr>
            <th>시각</th>
            <th>종목명</th>
            <th>구분</th>
            <th>수량</th>
            <th>가격</th>
            <th>총금액</th>
        </tr>
    </thead>
    <tbody>
        <?php
        $orders = getMyOrders($conn, $_SESSION['user_id']);
        foreach ($orders as $o):
        ?>
            <tr>
                <td><?= $o['created_at'] ?></td>
                <td><?= htmlspecialchars($o['stock_name']) ?></td>
                <td><?= $o['order_type'] === 'buy' ? '<span class="text-danger">매수</span>' : '<span class="text-primary">매도</span>' ?></td>
                <td><?= number_format($o['quantity']) ?>주</td>
                <td><?= number_format($o['price']) ?>원</td>
                <td><?= number_format($o['total_amount']) ?>원</td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php include 'includes/footer.php'; ?>