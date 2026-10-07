const axios = require('axios')
const { initializeApp, cert } = require('firebase-admin/app')
const { getDatabase } = require('firebase-admin/database')
const exec = require('child_process').execFileSync
const logger = require('./logger')
const aesCmac = require('node-aes-cmac').aesCmac
const crypto = require('crypto')
const fs = require("fs")
const path = require("path")

process.loadEnvFile(path.join(__dirname, '.env'))
for (const name of [
  'FIREBASE_DATABASE_URL',
  'NATURE_REMO_MONITOR_2_SIGNAL_ID',
  'NATURE_REMO_MONITOR_1_SIGNAL_ID',
  'HOLIDAY_API_URL',
  'HOLIDAY_API_AUTHORIZATION',
  'NATURE_REMO_MORNING_SIGNAL_ID',
  'NOTIFIER_URL',
  'SESAME_1_DEVICE_ID',
  'SESAME_1_SECRET',
  'SESAME_2_DEVICE_ID',
  'SESAME_2_SECRET',
  'NATURE_REMO_AUTHORIZATION',
  'SWITCHBOT_TOKEN',
  'SWITCHBOT_SECRET',
  'SWITCHBOT_CURTAIN_DEVICE_ID',
  'SESAME_HISTORY_LABEL',
  'SESAME_API_KEY',
  'PC_SWITCH_SCRIPT_PATH',
  'PC_SWITCH_DEVICE_ID',
]) {
  if (!process.env[name]) {
    throw new Error(`必要な環境変数が未設定です: ${name}`)
  }
}

const STATE_PATH = path.join(__dirname, "monitor_state.json")

const serviceAccount = require(__dirname + '/firebase-adminsdk.json')
const app = initializeApp({
  credential: cert(serviceAccount),
  databaseURL: process.env.FIREBASE_DATABASE_URL
})
const db = getDatabase(app)

db.ref('pi').on('child_changed', (changedSnapshot) => {
  const key = changedSnapshot.key
  switch (key) {
    case 'pc_switch':
      exec('/usr/bin/sudo', [
        __dirname + '/shells/pc_switch.sh',
        process.env.PC_SWITCH_SCRIPT_PATH,
        process.env.PC_SWITCH_DEVICE_ID,
      ])

      const isMonitor1 = loadFlag()
      // true なら monitor2 に切り替え
      remo(isMonitor1 ? process.env.NATURE_REMO_MONITOR_2_SIGNAL_ID : process.env.NATURE_REMO_MONITOR_1_SIGNAL_ID)
      saveFlag(!isMonitor1)
      break;
    case 'curtain':
      const targetCommand = changedSnapshot.val().split(' ')[0]
      var command = 'turnOn'
      if (targetCommand == '閉め') {
        command = 'turnOff'
      }

      curtain(command)
      break;
    case 'morning':
      const nowDate = new Date()
      const nowYear = nowDate.getFullYear()
      const nowMonth = nowDate.getMonth() + 1
      const nowDay = nowDate.getDate()
      let nowHoliday = nowDate.getDay() == 0 || nowDate.getDay() == 6

      const url = process.env.HOLIDAY_API_URL
        + nowYear + '-' + nowMonth + '-' + nowDay

      axios.get(url, {
        headers: {
         'Accept': 'application/json',
         'Authorization': process.env.HOLIDAY_API_AUTHORIZATION
        }
      }).then((res) => {
        logger.exec.info(res.data)
        if (res.data.holiday) { // 祝日なら true にする
          nowHoliday = res.data.holiday
        }
        if (res.data.force) { // 強制フラグを最後にチェック
          nowHoliday = res.data.holiday
        }

        if (nowHoliday) {
          curtain('turnOn')
        } else {
          // CD on
          remo(process.env.NATURE_REMO_MORNING_SIGNAL_ID)
        }
      })
      .catch(error => logger.exec.warn(error))
      break;
    case 'notifier':
      const values = changedSnapshot.val().split(' … ')

      if (values.length != 4) {
        logger.exec.warn('Values Error [' + val + ']')
        return
      }

      const params = new URLSearchParams()
      params.append('text', values[0])
      params.append('volume', values[2])
      params.append('type', values[3])

      axios.post(process.env.NOTIFIER_URL, params)
        .then((res) => logger.exec.info('google home notifier posted [' + values[0] + ']'))
        .catch((error) => logger.exec.warn('google home notifier error: ' + error.message))
      break;
    case 'sesame':
      const type = changedSnapshot.val().split(' ')
      wm2_cmd(process.env.SESAME_1_DEVICE_ID, process.env.SESAME_1_SECRET, type[0])
      wm2_cmd(process.env.SESAME_2_DEVICE_ID, process.env.SESAME_2_SECRET, type[0])
      break;
    default:
      logger.exec.warn('unknown node')
      break;
  }
})

let remo = (id) => {
  axios.post(`https://api.nature.global/1/signals/${id}/send`, null, {
    headers: {
     'Accept': 'application/json',
     'Authorization': process.env.NATURE_REMO_AUTHORIZATION
    }
  })
  .then(res => {
    logger.exec.info(`statusCode: ${res.status}`)
    logger.exec.info(res.data)
  })
  .catch(error => logger.exec.warn(error))
}

let curtain = (command) => {
  const token = process.env.SWITCHBOT_TOKEN
  const secret = process.env.SWITCHBOT_SECRET
  const t = Date.now()
  const nonce = crypto.randomUUID()
  const data = token + t + nonce
  const sign = crypto.createHmac('sha256', secret).update(Buffer.from(data, 'utf-8')).digest().toString("base64")

  const body = {
    "command": command,
    "parameter": "default",
    "commandType": "command"
  }
  const deviceId = process.env.SWITCHBOT_CURTAIN_DEVICE_ID

  axios.post(`https://api.switch-bot.com/v1.1/devices/${deviceId}/commands`, body, {
    headers: {
      "Authorization": token,
      "sign": sign,
      "nonce": nonce,
      "t": t,
      'Content-Type': 'application/json'
    }
  })
  .then(res => {
      logger.exec.info(`statusCode: ${res.status}`)
      logger.exec.info(res.data)
  })
  .catch(error => logger.exec.warn(error))
}

let wm2_cmd = (sesame_id, key_secret_hex, cmd) => {
  //(toggle:88,lock:82,unlock:83)
  let base64_history = Buffer.from(process.env.SESAME_HISTORY_LABEL).toString('base64')

  let key = Buffer.from(key_secret_hex, 'hex')
  const date = Math.floor(Date.now() / 1000)
  const dateDate = Buffer.allocUnsafe(4)
  dateDate.writeUInt32LE(date)
  const message = Buffer.from(dateDate.slice(1, 4))
  const sign = aesCmac(key, message)
  const api_key = process.env.SESAME_API_KEY

  axios({
    method: 'post',
    url: `https://app.candyhouse.co/api/sesame2/${sesame_id}/cmd`,
    headers: { 'x-api-key': api_key },
    data: {
      cmd: cmd,
      history: base64_history,
      sign: sign,
    },
  })
    .then((res) => logger.exec.info(res))
    .catch((error) => logger.exec.warn(error))
}

let loadFlag = () => {
  try {
    const raw = fs.readFileSync(STATE_PATH, "utf8")
    const obj = JSON.parse(raw)
    return typeof obj.monitor1 === "boolean" ? obj.monitor1 : false
  } catch (e) {
    saveFlag(false)
    return false
  }
}

let saveFlag = (flag) => {
  try {
    fs.writeFileSync(STATE_PATH, JSON.stringify({ monitor1: !!flag }, null, 2), "utf8")
  } catch (e) {
    logger.exec.warn(e)
  }
}

const activeMessage = 'ラズパイ各種モジュール起動しました'
logger.exec.info(activeMessage)
console.log(activeMessage)
