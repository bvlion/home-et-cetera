module.exports.setTodayHoliday = (admin, execSend) => setTodayHoliday(admin, execSend)
module.exports.setTodayWeekday = (admin, execSend) => setTodayWeekday(admin, execSend)
module.exports.setTomorrowHoliday = (admin, execSend) => setTomorrowHoliday(admin, execSend)
module.exports.setTomorrowWeekday = (admin, execSend) => setTomorrowWeekday(admin, execSend)

const axios = require('axios')

async function setTodayHoliday(admin, execSend) {
  setHoliday(admin, 1, false)
  execSend('setTodayHoliday')
}

async function setTodayWeekday(admin, execSend) {
  setHoliday(admin, 0, false)
  execSend('setTodayWeekday')
}

async function setTomorrowHoliday(admin, execSend) {
  setHoliday(admin, 1, true)
  execSend('setTomorrowHoliday')
}

async function setTomorrowWeekday(admin, execSend) {
  setHoliday(admin, 0, true)
  execSend('setTomorrowWeekday')
}

async function setHoliday(admin, holiday_id, addDate) {
  let nowDate = new Date(Date.now() + ((new Date().getTimezoneOffset() + (9 * 60)) * 60 * 1000))

  if (addDate) {
    nowDate.setDate(nowDate.getDate() + 1)
  }

  let nowYear = nowDate.getFullYear()
  let nowMonth = nowDate.getMonth() + 1
  let nowDay = nowDate.getDate()

  const url = (await admin.database().ref('/holidays-webhook/holiday-update-url').once('value')).val()
  const token = (await admin.database().ref('/holidays-webhook/token').once('value')).val()
  axios.defaults.headers.common['Authorization'] ='Bearer ' + token
  axios.post(url, {
    date: nowYear + '-' + nowMonth + '-' + nowDay,
    holiday: holiday_id,
    type: 'group'
  })
    .then((res) => console.log(res))
    .catch((error) => console.log(error))
}
