<?php
require_once 'func.php';
startAdminSession();

if (!isAdminLoggedIn()) {
    header('Location: login.php');
    exit;
}

$totalUsers = getTotalUserCount($conn);
$todaySignups = getTodaySignupCount($conn);
$topStocks = getTopWatchedStocks($conn);
$totalLogs = getTotalLogCount($conn);

include 'includes/header.php';
?>

<h2>관리자 대시보드</h2>
<p><?= htmlspecialchars($_SESSION['admin_name']) ?>님 반갑습니다.
    <a href="logout.php" class="btn btn-outline-secondary btn-sm">로그아웃</a>
</p>

<div class="row mt-4">
    <div class="col-md-4">
        <div class="card text-center p-3">
            <h6>전체 회원 수</h6>
            <h3><?= number_format($totalUsers) ?>명</h3>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-center p-3">
            <h6>오늘 가입자 수</h6>
            <h3><?= number_format($todaySignups) ?>명</h3>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-center p-3">
            <h6>누적 시세 데이터</h6>
            <h3><?= number_format($totalLogs) ?>건</h3>
        </div>
    </div>
</div>

<h4 class="mt-5">인기 관심종목 TOP 5</h4>
<table class="table table-bordered mt-2">
    <thead>
        <tr>
            <th>순위</th>
            <th>종목명</th>
            <th>종목코드</th>
            <th>등록 수</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($topStocks as $i => $stock): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= htmlspecialchars($stock['stock_name']) ?></td>
                <td><?= htmlspecialchars($stock['stock_code']) ?></td>
                <td><?= $stock['cnt'] ?>명</td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php include 'includes/footer.php'; ?>