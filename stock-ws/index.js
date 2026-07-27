require('dotenv').config();
const WebSocket = require('ws');
const { getApprovalKey } = require('./getApprovalKey');
const { saveStockLog, upsertStockLatest } = require('./db');
const { log } = require('./logger');

// 홈 대시보드용 상시 구독 종목 (체결만)
const HOME_TARGETS = ['005930', '000660', '035420'];

// -----------------------------
// 브라우저들이 접속할 서버
// -----------------------------
const browserServer = new WebSocket.Server({ port: 8080 });
const browserClients = new Set();

// -----------------------------
// 한투 실시간 구독 상태
//   subs[trId] = Map(code -> refcount)  ← 여러 브라우저가 같은 종목을 봐도 KIS엔 1건만 등록
// -----------------------------
let kisWs = null;
let approvalKey = null;
const subs = { H0STCNT0: new Map(), H0STASP0: new Map() };

function sendKis(trId, code, on) {
    if (!kisWs || kisWs.readyState !== WebSocket.OPEN) return;
    kisWs.send(JSON.stringify({
        header: { approval_key: approvalKey, custtype: 'P', tr_type: on ? '1' : '2', 'content-type': 'utf-8' },
        body: { input: { tr_id: trId, tr_key: code } },
    }));
}

function subscribe(trId, code) {
    const m = subs[trId];
    const n = m.get(code) || 0;
    if (n === 0) { sendKis(trId, code, true); log(`구독+ ${trId} ${code}`); }
    m.set(code, n + 1);
}

function unsubscribe(trId, code) {
    const m = subs[trId];
    const n = m.get(code) || 0;
    if (n <= 0) return;
    if (n === 1) { m.delete(code); sendKis(trId, code, false); log(`구독- ${trId} ${code}`); }
    else m.set(code, n - 1);
}

// 브라우저가 상세페이지를 열면 그 종목의 호가+체결을 실시간 구독
function watchCode(client, code) {
    if (client._watch === code) return;
    if (client._watch) unwatchCode(client);   // 다른 종목 보고 있었으면 해제
    client._watch = code;
    subscribe('H0STASP0', code);   // 호가
    subscribe('H0STCNT0', code);   // 체결
}

function unwatchCode(client) {
    const code = client._watch;
    if (!code) return;
    client._watch = null;
    unsubscribe('H0STASP0', code);
    unsubscribe('H0STCNT0', code);
}

browserServer.on('connection', (client) => {
    client._watch = null;
    browserClients.add(client);
    log('브라우저 접속 (현재 ' + browserClients.size + '명)');

    client.on('message', (raw) => {
        let msg;
        try { msg = JSON.parse(raw.toString()); } catch { return; }
        if (msg.action === 'watch' && msg.code) watchCode(client, msg.code);
        else if (msg.action === 'unwatch') unwatchCode(client);
    });

    client.on('close', () => {
        unwatchCode(client);
        browserClients.delete(client);
        log('브라우저 종료 (현재 ' + browserClients.size + '명)');
    });
});

function broadcast(data) {
    const message = JSON.stringify(data);
    browserClients.forEach((client) => {
        if (client.readyState === WebSocket.OPEN) client.send(message);
    });
}

// -----------------------------
// 한투 웹소켓 연결
// -----------------------------
let retryCount = 0;

async function start() {
    approvalKey = await getApprovalKey();
    log('접속키 발급 완료');

    kisWs = new WebSocket('ws://ops.koreainvestment.com:31000');

    kisWs.on('open', () => {
        retryCount = 0;
        log('한투 웹소켓 연결됨');

        // refcount 초기화 후 홈 종목 상시 구독
        subs.H0STCNT0.clear();
        subs.H0STASP0.clear();
        HOME_TARGETS.forEach(code => subscribe('H0STCNT0', code));

        // (재접속인 경우) 브라우저들이 보고 있던 종목 재구독
        browserClients.forEach(c => {
            if (c._watch) { subscribe('H0STASP0', c._watch); subscribe('H0STCNT0', c._watch); }
        });
    });

    kisWs.on('message', async (data) => {
        const message = data.toString();

        // 실시간 데이터 프레임은 '0|TRID|건수|body' 형식.
        // 그 외(구독응답 JSON, PINGPONG)는 여기서 처리/무시.
        if (!message.startsWith('0')) {
            if (message.includes('PINGPONG')) kisWs.send(message);   // 살아있음 응답
            return;
        }

        const parts = message.split('|');
        if (parts.length < 4) return;
        const trId = parts[1];
        const body = parts[3];

        if (trId === 'H0STCNT0') await handleTrade(body);
        else if (trId === 'H0STASP0') handleOrderbook(body);
    });

    kisWs.on('close', (code, reason) => {
        retryCount++;
        const delay = Math.min(retryCount * 10000, 60000);
        log(`연결 종료됨 (code: ${code}, reason: ${reason.toString()}), ${delay / 1000}초 후 재접속 시도 (${retryCount}번째)`);
        setTimeout(start, delay);
    });

    kisWs.on('error', (err) => {
        log('웹소켓 에러: ' + err.message);
    });
}

// H0STCNT0 실시간 체결 → 가격 + 체결내역 브로드캐스트, DB 저장
async function handleTrade(body) {
    const f = body.split('^');
    const stockCode = f[0];
    const hour = f[1];                    // 체결시간 HHMMSS
    const price = parseInt(f[2]);
    const sign = parseInt(f[3]);          // 1상한 2상승 3보합 4하한 5하락
    const changePrice = parseInt(f[4]);
    const changeRate = parseFloat(f[5]);
    const cntgVol = parseInt(f[12]);      // 체결량
    const volume = parseInt(f[13]);       // 누적거래량
    const strength = parseFloat(f[18]);   // 체결강도

    try {
        await saveStockLog(stockCode, price, changePrice, changeRate, volume);
        await upsertStockLatest(stockCode, price, changePrice, changeRate, volume);
    } catch (err) {
        log('저장 실패: ' + err.message);
    }

    // 가격 (홈/상세 공통)
    broadcast({
        type: 'price',
        stock_code: stockCode,
        price, change_price: changePrice, change_rate: changeRate, volume,
        created_at: new Date().toISOString(),
    });
    // 체결 한 건 (상세 '시세' 위젯 + 체결강도)
    broadcast({
        type: 'trade',
        stock_code: stockCode,
        time: hour, price, sign, volume: cntgVol, rate: changeRate, strength,
    });
}

// H0STASP0 실시간 호가 → 호가창 브로드캐스트
function handleOrderbook(body) {
    const f = body.split('^');
    const stockCode = f[0];
    const asks = [], bids = [];
    for (let i = 0; i < 10; i++) {
        asks.push({ price: parseInt(f[3 + i]),  qty: parseInt(f[23 + i]) });   // 매도호가/잔량 1~10
        bids.push({ price: parseInt(f[13 + i]), qty: parseInt(f[33 + i]) });   // 매수호가/잔량 1~10
    }
    broadcast({
        type: 'orderbook',
        stock_code: stockCode,
        asks, bids,
        total_ask: parseInt(f[43]),
        total_bid: parseInt(f[44]),
    });
}

start();
log('브라우저용 웹소켓 서버 시작 (포트 8080)');
