require('dotenv').config();
const axios = require('axios');

async function getApprovalKey() {
    const url = 'https://openapivts.koreainvestment.com:29443/oauth2/Approval';

    const response = await axios.post(url, {
        grant_type: 'client_credentials',
        appkey: process.env.KIS_APP_KEY,
        secretkey: process.env.KIS_APP_SECRET,
    });

    return response.data.approval_key;
}

module.exports = { getApprovalKey };