module.exports.morning = (admin, execSend) => morning(admin, execSend)
module.exports.living = (admin, execSend) => livingSet(admin, execSend)
module.exports.livingOff = (admin, execSend) => livingOff(admin, execSend)
module.exports.beforeSleep = (admin, execSend) => beforeSleep(admin, execSend)
module.exports.sleep = (admin, execSend) => sleep(admin, execSend)
module.exports.allOff = (admin, execSend) => allOff(admin, execSend)

const axios = require('axios')

async function livingOff(admin, execSend) {
  const results = await remo(admin, ['fan_off', 'living_light'])

  if (results) {
    execSend('end living off')
  } else {
    execSend('error living off')
  }
}

async function livingSet(admin, execSend) {
  const fan = 'fan_2'
  const urlNames = ['living_light', fan, 'fan_reverse', 'fan_off', fan, 'fan_reverse'] // 夏場
  // const urlNames = ['living_light', fan] // 冬場

  const results = await remo(admin, urlNames)

  if (results) {
    if (execSend !== null) {
      execSend('end living on')
    }
  } else {
    if (execSend !== null) {
      execSend('error living on')
    }
  }
}

async function morning(admin, execSend) {
  // await admin.database().ref('/pi/morning').set(new Date().toISOString())
  await admin.database().ref('/pi/curtain').set(`開け ${new Date().toISOString()}`)
  // await remo(admin, ['bed_room_light_on'])
  await livingSet(admin, execSend)
  await remo(admin, ['living_2_orange', 'bed_room_aircon_power_off', 'living_2_orange'])
}

async function beforeSleep(admin, execSend) {
  const results = await remo(admin, ['bed_room_light_on', 'outlet_off', 'fan_off', 'living_light', 'living_aircon_power_off', 'work_room_light_off', 'work_room_aircon_power_off'])

  if (results) {
    execSend('end before sleep off')
  } else {
    execSend('error before sleep off')
  }
}

async function sleep(admin, execSend) {
  await admin.database().ref('/pi/curtain').set(`閉め ${new Date().toISOString()}`)
  const results = await remo(admin, ['bed_room_light_off'])

  if (results) {
    execSend('end sleep off')
  } else {
    execSend('error sleep off')
  }
}

async function allOff(admin, execSend) {
  const results = await remo(admin, ['fan_off', 'living_light', 'living_aircon_power_off', 'bed_room_light_off', 'bed_room_aircon_power_off', 'outlet_off', 'work_room_light_off', 'work_room_aircon_power_off'])

  if (results) {
    execSend('end living off')
  } else {
    execSend('error living off')
  }
}

const remo = async (admin, urlNames) => {
  const remoToken = (await admin.database().ref('/remo/token').once('value')).val()
  const header = {
    'Accept': 'application/json',
    'Authorization': 'Bearer ' + remoToken
  }

  const urlPromises = urlNames.map((urlName) =>
    admin.database().ref('/remo/url/' + urlName).once('value')
      .then((snapshot) => snapshot.val())
      .catch((err) => {
        console.log(err)
      })
  )

  const paramsArray = urlNames.map((urlName) => {
    const params = new URLSearchParams()
    if (urlName.slice(-9) == 'power_off') {
      params.append('button', 'power-off')
    }
    if (urlName.slice(-9) == 'light_off') {
      params.append('button', 'off')
    }
    if (urlName.slice(-8) == 'light_on') {
      params.append('button', 'on')
    }
    return params
  })

  let urls = []
  await Promise.all(urlPromises)
    .then((url) => urls = url)
    .catch((err) => {
      console.log(err)
    })

  const promises = []
  const waitPromise = () => new Promise(resolve => setTimeout(resolve, 1500))

  urls.forEach((value, index) => {
    if (index > 0) {
      promises.push(waitPromise)
    }
    promises.push(() => axios.post(value, paramsArray[index], { headers: header }))
  })

  const results = await sequential(promises)
  return results
}

const sequential = async (promises) => {
  const first = promises.shift()
  if (first === null) {
    return []
  }

  const results = []
  await promises
    .concat(() => Promise.resolve())
    .reduce(async (prev, next) => {
      const res = await prev
      results.push(res)
      return next()
    }, Promise.resolve(first()))

  return results
}
