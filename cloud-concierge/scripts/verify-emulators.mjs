import assert from 'node:assert/strict';
import http from 'node:http';
import {once} from 'node:events';

// This script uses only the demo project, loopback endpoints and invented values.
const project = 'demo-cloud-concierge';
assert.equal(process.env.GCLOUD_PROJECT, project);
assert.equal(process.env.FIREBASE_DATABASE_EMULATOR_HOST, '127.0.0.1:5002');
const database = `http://127.0.0.1:5002`;
const namespace = `${project}-default-rtdb`;
const endpoint = `http://127.0.0.1:5001/${project}/us-central1/postRequestFunction`;
const requests = [];
const server = http.createServer(async (request, response) => {
  let body = '';
  for await (const chunk of request) body += chunk;
  requests.push({path: request.url, body, authorization: request.headers.authorization, at: Date.now(), response});
  server.emit('request-recorded');
  // Hold the holiday webhook response to check the existing early HTTP response.
  if (request.url !== '/holiday') response.end('{}');
});
server.listen(0, '127.0.0.1');
await once(server, 'listening');
const mockEndpoint = `http://127.0.0.1:${server.address().port}`;
const aliases = ['fan_off', 'living_light', 'fan_2', 'fan_reverse', 'living_2_orange', 'bed_room_aircon_power_off', 'bed_room_light_on', 'outlet_off', 'living_aircon_power_off', 'work_room_light_off', 'work_room_aircon_power_off', 'bed_room_light_off'];

try {
  const seeded = await fetch(`${database}/.json?ns=${namespace}`, {
    method: 'PUT', headers: {Authorization: 'Bearer owner'},
    body: JSON.stringify({
      token: 'example-request-token',
      remo: {token: 'example-device-token', url: Object.fromEntries(aliases.map(alias => [alias, `${mockEndpoint}/${alias}`]))},
      'holidays-webhook': {token: 'example-holiday-token', 'holiday-update-url': `${mockEndpoint}/holiday`}
    })
  });
  assert.equal(seeded.status, 200);
  const anonymousRead = await fetch(`${database}/token.json?ns=${namespace}`);
  assert.equal(anonymousRead.status, 401);
  const anonymousWrite = await fetch(`${database}/token.json?ns=${namespace}`, {method: 'PUT', body: '"example-other-token"'});
  assert.equal(anonymousWrite.status, 401);

  const methodResponse = await fetch(endpoint);
  assert.equal(methodResponse.status, 404);
  assert.equal(await methodResponse.text(), 'Not post request');
  for (const header of [{}, {'x-auth-header': 'example-wrong-token'}]) {
    const response = await fetch(endpoint, {method: 'POST', headers: {...header, 'Content-Type': 'application/json'}, body: JSON.stringify({type: 'living_on'})});
    assert.equal(response.status, 400);
    assert.equal(await response.text(), 'Not has Header');
  }
  assert.equal(requests.length, 0);

  const operations = [
    {type: 'unknown', response: 'This is post request'},
    {type: 'speak_text', body: {text: 'Example speech'}, response: 'speak Example speech', path: 'notifier', value: 'Example speech'},
    {type: 'speak_time', response: /^speak Example \d{2}:\d{2} \d{4}-\d{2}-\d{2}T.*Z$/, path: 'notifier', value: /^Example \d{2}:\d{2} \d{4}-\d{2}-\d{2}T.*Z$/},
    {type: 'curtain', body: {command: 'example-command'}, response: 'curtain example-command', path: 'curtain', value: 'example-command'},
    {type: 'pc_switch', response: 'floor heating updated', path: 'pc_switch', value: /^\d{4}-\d{2}-\d{2}T.*Z$/},
    {type: 'sesame_open', response: /^sesame 83 \d{4}-\d{2}-\d{2}T.*Z$/, path: 'sesame', value: /^83 \d{4}-\d{2}-\d{2}T.*Z$/},
    {type: 'sesame_close', response: /^sesame 82 \d{4}-\d{2}-\d{2}T.*Z$/, path: 'sesame', value: /^82 \d{4}-\d{2}-\d{2}T.*Z$/},
    {type: 'sesame_toggle', response: /^sesame 88 \d{4}-\d{2}-\d{2}T.*Z$/, path: 'sesame', value: /^88 \d{4}-\d{2}-\d{2}T.*Z$/},
    {type: 'set_today_holiday', response: 'setTodayHoliday', holiday: 1, shouldUseTomorrow: false},
    {type: 'set_today_weekday', response: 'setTodayWeekday', holiday: 0, shouldUseTomorrow: false},
    {type: 'set_tomorrow_holiday', response: 'setTomorrowHoliday', holiday: 1, shouldUseTomorrow: true},
    {type: 'set_tomorrow_weekday', response: 'setTomorrowWeekday', holiday: 0, shouldUseTomorrow: true},
    {type: 'living_on', response: 'end living on', aliases: ['living_light', 'fan_2', 'fan_reverse', 'fan_off', 'fan_2', 'fan_reverse']},
    {type: 'living_off', response: 'end living off', aliases: ['fan_off', 'living_light']},
    {type: 'morning', response: 'end living on', path: 'curtain', value: /^開け \d{4}-\d{2}-\d{2}T.*Z$/, aliases: ['living_light', 'fan_2', 'fan_reverse', 'fan_off', 'fan_2', 'fan_reverse', 'living_2_orange', 'bed_room_aircon_power_off', 'living_2_orange']},
    {type: 'before_sleep', response: 'end before sleep off', aliases: ['bed_room_light_on', 'outlet_off', 'fan_off', 'living_light', 'living_aircon_power_off', 'work_room_light_off', 'work_room_aircon_power_off']},
    {type: 'sleep', response: 'end sleep off', path: 'curtain', value: /^閉め \d{4}-\d{2}-\d{2}T.*Z$/, aliases: ['bed_room_light_off']},
    {type: 'all_off', response: 'end living off', aliases: ['fan_off', 'living_light', 'living_aircon_power_off', 'bed_room_light_off', 'bed_room_aircon_power_off', 'outlet_off', 'work_room_light_off', 'work_room_aircon_power_off']}
  ];

  for (const operation of operations) {
    const start = requests.length;
    const date = new Date(Date.now() + (new Date().getTimezoneOffset() + 9 * 60) * 60 * 1000);
    if (operation.shouldUseTomorrow) date.setDate(date.getDate() + 1);
    const response = await fetch(endpoint, {method: 'POST', headers: {'Content-Type': 'application/json', 'x-auth-header': 'example-request-token'}, body: JSON.stringify({type: operation.type, ...operation.body})});
    assert.equal(response.status, 200, operation.type);
    const text = await response.text();
    if (operation.response instanceof RegExp) assert.match(text, operation.response);
    else assert.equal(text, operation.response);

    if (operation.path) {
      const stored = await fetch(`${database}/pi/${operation.path}.json?ns=${namespace}`, {headers: {Authorization: 'Bearer owner'}});
      const value = await stored.json();
      if (operation.value instanceof RegExp) assert.match(value, operation.value);
      else assert.equal(value, operation.value);
      if (operation.type === 'speak_time') {
        assert.equal(text, 'speak ' + value);
        const timestamp = value.split(' ')[2];
        const time = new Date(timestamp).toLocaleTimeString('ja-JP', {timeZone: 'Asia/Tokyo', hour: '2-digit', minute: '2-digit', hour12: false});
        assert.equal(value.split(' ')[1], time);
      }
    }
    const count = operation.aliases?.length ?? (operation.holiday === undefined ? 0 : 1);
    if (operation.type === 'morning') assert.ok(requests.length - start < count, 'morning responds before its final device sequence completes');
    while (requests.length - start < count) await once(server, 'request-recorded', {signal: AbortSignal.timeout(25000)});
    const captured = requests.slice(start);
    assert.equal(captured.length, count, operation.type);
    if (operation.holiday !== undefined) {
      assert.equal(captured[0].path, '/holiday');
      assert.equal(captured[0].authorization, 'Bearer example-holiday-token');
      assert.deepEqual(JSON.parse(captured[0].body), {date: `${date.getFullYear()}-${date.getMonth() + 1}-${date.getDate()}`, holiday: operation.holiday, type: 'group'});
      captured[0].response.end('{}');
      await once(captured[0].response, 'finish');
    }
    if (operation.aliases) {
      assert.deepEqual(captured.map(request => request.path), operation.aliases.map(alias => '/' + alias));
      for (const [index, request] of captured.entries()) {
        assert.equal(request.authorization, 'Bearer example-device-token');
        const alias = operation.aliases[index];
        const expectedButton = alias.endsWith('power_off') ? 'power-off' : alias.endsWith('light_off') ? 'off' : alias.endsWith('light_on') ? 'on' : null;
        assert.equal(new URLSearchParams(request.body).get('button'), expectedButton);
        if (index > 0 && !(operation.type === 'morning' && index === 6)) assert.ok(request.at - captured[index - 1].at >= 1300, 'existing 1500ms device pacing');
      }
    }
    console.log('Verified ' + operation.type);
  }
  console.log('Verified all 17 operations, authentication, HTTP methods and database rules using invented data.');
} finally {
  server.closeAllConnections();
  await new Promise(resolve => server.close(resolve));
}
