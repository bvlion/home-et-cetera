process.loadEnvFile(require('path').join(__dirname, '.env'))
for (const name of [
  'HOLIDAY_API_URL',
  'HOLIDAY_API_AUTHORIZATION',
  'NOTIFIER_URL',
  'HOLIDAY_NOTIFICATION_VOLUME',
  'HOLIDAY_NOTIFICATION_TYPE',
  'HOLIDAY_NOTIFICATION_HOLIDAY_TEXT',
  'HOLIDAY_NOTIFICATION_WORKDAY_TEXT',
]) {
  if (!process.env[name]) {
    throw new Error(`必要な環境変数が未設定です: ${name}`)
  }
}

const logger = require('./logger')
const axios = require('axios')

const nowDate = new Date()
const nowYear = nowDate.getFullYear()
const nowMonth = nowDate.getMonth() + 1
const nowDay = nowDate.getDate()
let nowHoliday = nowDate.getDay() == 0 || nowDate.getDay() == 6

const afterDate = new Date()
afterDate.setDate(afterDate.getDate() + 1)
const afterYear = afterDate.getFullYear()
const afterMonth = afterDate.getMonth() + 1
const afterDay = afterDate.getDate()
let afterHoliday = afterDate.getDay() == 0 || afterDate.getDay() == 6

const holidayRequestConfig = {
  headers: {
    'Authorization': process.env.HOLIDAY_API_AUTHORIZATION,
  },
}

axios.get(process.env.HOLIDAY_API_URL + nowYear + '-' + nowMonth + '-' + nowDay, holidayRequestConfig)
  .then((res1) =>
      axios.get(process.env.HOLIDAY_API_URL + afterYear + '-' + afterMonth + '-' + afterDay, holidayRequestConfig)
      .then((res2) => {
        // 祝日だった場合は上書き
        if (res1.data.holiday) {
          nowHoliday = res1.data.holiday;
        }
        if (res2.data.holiday) {
          afterHoliday = res2.data.holiday;
        }
        // 強制設定されている場合は上書き
        if (res1.data.force) {
          nowHoliday = res1.data.holiday;
        }
        if (res2.data.force) {
          afterHoliday = res2.data.holiday;
        }

        if (nowHoliday != afterHoliday) {
          const params = new URLSearchParams()
          params.append('volume', process.env.HOLIDAY_NOTIFICATION_VOLUME)
          params.append('type', process.env.HOLIDAY_NOTIFICATION_TYPE)

          // 明日が休みの場合
          if (afterHoliday) {
            params.append('text', process.env.HOLIDAY_NOTIFICATION_HOLIDAY_TEXT)
          }
          // 明日が平日の場合
          if (!afterHoliday) {
            params.append('text', process.env.HOLIDAY_NOTIFICATION_WORKDAY_TEXT)
          }

          axios.post(process.env.NOTIFIER_URL, params)
            .then((res) => logger.exec.info('google home notifier posted [おやすみ設定]'))
            .catch((error) => logger.exec.warn('google home notifier error: ' + error.message))
        }
      })
      .catch((error2) => logger.exec.warn('normal-holiday-setting ' + error2.message))
  )
  .catch((error) => logger.exec.warn('normal-holiday-setting ' + error.message))
