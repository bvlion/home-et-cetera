const test = require('node:test')
const assert = require('node:assert/strict')

test('axios is requireable', () => {
  assert.equal(typeof require('axios').get, 'function')
})

test('firebase-admin modular API exposes initializeApp/cert/getDatabase', () => {
  const { initializeApp, cert } = require('firebase-admin/app')
  const { getDatabase } = require('firebase-admin/database')
  assert.equal(typeof initializeApp, 'function')
  assert.equal(typeof cert, 'function')
  assert.equal(typeof getDatabase, 'function')
})

test('child_process resolves to the Node.js core module, not an npm package', () => {
  assert.equal(require.resolve('child_process'), 'child_process')
  assert.equal(typeof require('child_process').execFileSync, 'function')
})

test('node-aes-cmac matches the NIST SP800-38B / RFC4493 test vector', () => {
  const { aesCmac } = require('node-aes-cmac')
  const key = Buffer.from('2b7e151628aed2a6abf7158809cf4f3c', 'hex')
  const message = Buffer.from('6bc1bee22e409f96e93d7e117393172a', 'hex')
  assert.equal(aesCmac(key, message), '070a16b46b4d4144f79bdd9dd04a287c')
})

test('crypto.randomUUID generates a valid RFC4122 v4 UUID (Node.js standard API, no dependency)', () => {
  const crypto = require('crypto')
  assert.equal(typeof crypto.randomUUID, 'function')
  const id = crypto.randomUUID()
  assert.match(id, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i)
})
