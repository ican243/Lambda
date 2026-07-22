require('dotenv').config();
const mysql = require('mysql2/promise');

const pool = mysql.createPool({
    host: process.env.DB_HOST,
    user: process.env.DB_USER,
    password: process.env.DB_PASS,
    database: process.env.DB_NAME,
});

// -----------------------------
// 시세 기록 저장 (stock_logs)
// -----------------------------
async function saveStockLog(stockCode, price, changePrice, changeRate, volume) {
    const sql = `
        INSERT INTO stock_logs (stock_code, price, change_price, change_rate, volume)
        VALUES (?, ?, ?, ?, ?)
    `;
    await pool.execute(sql, [stockCode, price, changePrice, changeRate, volume]);
}

// -----------------------------
// 최신 시세 갱신 (stock_latest)
// -----------------------------
async function upsertStockLatest(stockCode, price, changePrice, changeRate, volume) {
    const sql = `
        INSERT INTO stock_latest (stock_code, stock_name, price, change_price, change_rate, volume)
        VALUES (?, (SELECT stock_name FROM stock_master WHERE stock_code = ?), ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            price = VALUES(price), 
            change_price = VALUES(change_price),
            change_rate = VALUES(change_rate), 
            volume = VALUES(volume)
    `;
    await pool.execute(sql, [stockCode, stockCode, price, changePrice, changeRate, volume]);
}

module.exports = { saveStockLog, upsertStockLatest };