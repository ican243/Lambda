require('dotenv').config();
const axios = require('axios');

async function getApprovalKey() {
    const url = 'https://openapivts.koreainvestment.com:29443/oauth2/Approval';

    const response = await axios.post(url, {
        grant_type: 'client_credentials',
        appkey: process.env.KIS_APP_KEY,
        secretkey: process.env.KIS_APP_SECRET,
    }, { timeout: 10000 });   // 10초 안에 답이 없으면 포기 (안 그러면 2분 넘게 멈춰 있는다)

    return response.data.approval_key;
}

module.exports = { getApprovalKey };