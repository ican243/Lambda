require('dotenv').config();
const WebSocket = require('ws');
const { getApprovalKey } = require('./getApprovalKey');
const { saveStockLog, upsertStockLatest } = require('./db');
const { log } = require('./logger');

const targets = ['005930', '000660', '035420'];

// -----------------------------
// 브라우저들이 접속할 서버
// -----------------------------
const browserServer = new WebSocket.Server({ port: 8080 });
const browserClients = new Set();

browserServer.on('connection', (client) => {
    browserClients.add(client);
    log('브라우저 클라이언트 접속 (현재 ' + browserClients.size + '명)');

    client.on('close', () => {
        browserClients.delete(client);
        log('브라우저 클라이언트 종료 (현재 ' + browserClients.size + '명)');
    });
});

function broadcastToBrowsers(data) {
    const message = JSON.stringify(data);
    browserClients.forEach((client) => {
        if (client.readyState === WebSocket.OPEN) {
            client.send(message);
        }
    });
}

// -----------------------------
// 한투 웹소켓 연결
// -----------------------------
let retryCount = 0;

async function start() {
    const approvalKey = await getApprovalKey();
    log('접속키 발급 완료');

    const ws = new WebSocket('ws://ops.koreainvestment.com:31000');

    ws.on('open', () => {
        retryCount = 0;
        log('웹소켓 연결됨');

        targets.forEach(code => {
            const subscribeMsg = {
                header: {
                    approval_key: approvalKey,
                    custtype: 'P',
                    tr_type: '1',
                    'content-type': 'utf-8',
                },
                body: {
                    input: {
                        tr_id: 'H0STCNT0',
                        tr_key: code,
                    },
                },
            };
            ws.send(JSON.stringify(subscribeMsg));
        });
    });

    ws.on('message', async (data) => {
        const message = data.toString();

        if (message.includes('|')) {
            const parts = message.split('|');
            if (parts.length < 4) return;

            const body = parts[3];
            const fields = body.split('^');

            const stockCode = fields[0];
            const price = parseInt(fields[2]);
            const changePrice = parseInt(fields[4]);
            const changeRate = parseFloat(fields[5]);
            const volume = parseInt(fields[13]);

            try {
                await saveStockLog(stockCode, price, changePrice, changeRate, volume);
                await upsertStockLatest(stockCode, price, changePrice, changeRate, volume);
                log(`${stockCode} 저장: ${price}원`);

                broadcastToBrowsers({
                    stock_code: stockCode,
                    price: price,
                    change_price: changePrice,
                    change_rate: changeRate,
                    volume: volume,
                    created_at: new Date().toISOString(),
                });

            } catch (err) {
                log('저장 실패: ' + err.message);
            }
        }
    });

    ws.on('close', (code, reason) => {
        retryCount++;
        const delay = Math.min(retryCount * 10000, 60000);
        log(`연결 종료됨 (code: ${code}, reason: ${reason.toString()}), ${delay / 1000}초 후 재접속 시도 (${retryCount}번째)`);
        setTimeout(start, delay);
    });

    ws.on('error', (err) => {
        log('웹소켓 에러: ' + err.message);
    });
}

start();
log('브라우저용 웹소켓 서버 시작 (포트 8080)');