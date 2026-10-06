'use strict'

const { onRequest } = require("firebase-functions/v2/https")
const admin = require('firebase-admin')

admin.initializeApp({
  databaseURL: process.env.FIREBASE_DATABASE_URL,
})

exports.postRequestFunction = onRequest((request, response) => {
  if (request.method !== 'POST') {
    response.status(404).send('Not post request')
    return
  }
  asyncProcess(admin, request, response)
})

async function asyncProcess(admin, request, response) {
  const headerToken = (await admin.database().ref('/token').once('value')).val()

  if (headerToken != request.get('x-auth-header')) {
    response.status(400).send('Not has Header')
    return
  }

  const infrared = require('./intents/infrared')
  const other = require('./intents/others')
  const raspi = require('./intents/raspi')
  const execSend = function (msg) {
    response.status(200).send(msg)
  }

  if (request.body.type == 'living_on') {
    infrared.living(admin, execSend)
  } else if (request.body.type == 'living_off') {
    infrared.livingOff(admin, execSend)
  } else if (request.body.type == 'morning') {
    infrared.morning(admin, execSend)
  } else if (request.body.type == 'set_today_holiday') {
    other.setTodayHoliday(admin, execSend)
  } else if (request.body.type == 'set_today_weekday') {
    other.setTodayWeekday(admin, execSend)
  } else if (request.body.type == 'set_tomorrow_holiday') {
    other.setTomorrowHoliday(admin, execSend)
  } else if (request.body.type == 'set_tomorrow_weekday') {
    other.setTomorrowWeekday(admin, execSend)
  } else if (request.body.type == 'speak_text') {
    raspi.speakText(request.body.text, admin, execSend)
  } else if (request.body.type == 'speak_time') {
    const nowJst = new Date().toLocaleTimeString('ja-JP', {
      timeZone: 'Asia/Tokyo',
      hour: '2-digit',
      minute: '2-digit',
      hour12: false,
    })
    raspi.speakText(process.env.SPEAK_TIME_TEMPLATE.replaceAll('{time}', nowJst).replaceAll('{timestamp}', new Date().toISOString()), admin, execSend)
  } else if (request.body.type == 'curtain') {
    raspi.curtain(request.body.command, admin, execSend)
  } else if (request.body.type == 'pc_switch') {
    raspi.pcSwitch(admin, execSend)
  } else if (request.body.type == 'sesame_open') {
    raspi.sesame(`83 ${new Date().toISOString()}`, admin, execSend)
  } else if (request.body.type == 'sesame_close') {
    raspi.sesame(`82 ${new Date().toISOString()}`, admin, execSend)
  } else if (request.body.type == 'sesame_toggle') {
    raspi.sesame(`88 ${new Date().toISOString()}`, admin, execSend)
  } else if (request.body.type == 'before_sleep') {
    infrared.beforeSleep(admin, execSend)
  } else if (request.body.type == 'sleep') {
    infrared.sleep(admin, execSend)
  } else if (request.body.type == 'all_off') {
    infrared.allOff(admin, execSend)
  } else {
    execSend('This is post request')
  }
}
