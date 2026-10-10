const fs = require('fs');
const path = require('path');

function log(message) {
    const time = new Date().toISOString();
    const line = `[${time}] ${message}\n`;

    console.log(line.trim());   // 터미널에도 출력

    const logDir = path.join(__dirname, 'logs');
    if (!fs.existsSync(logDir)) {
        fs.mkdirSync(logDir);   // logs 폴더 없으면 자동 생성
    }

    const logFile = path.join(logDir, 'app.log');
    fs.appendFileSync(logFile, line);   // 파일에 이어서 기록
}

module.exports = { log };