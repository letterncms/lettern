// The same update feed, good for another 30 days. Its releases keep their own signatures, which this
// key couldn't make: all it says is that the list is still current.
import { createPrivateKey, sign } from 'node:crypto'
import { readFileSync, writeFileSync } from 'node:fs'

const [file] = process.argv.slice(2)
const envelope = JSON.parse(readFileSync(file, 'utf8'))
const payload = JSON.parse(Buffer.from(envelope.payload, 'base64url'))
const stamp = (date) => date.toISOString().replace(/\.\d+Z$/, 'Z')
const now = Date.now()

const bytes = Buffer.from(JSON.stringify({ ...payload, version: payload.version + 1, issued: stamp(new Date(now)), expires: stamp(new Date(now + 30 * 86400_000)) }))
const seed = Buffer.from(process.env.CMS_FEED_KEY, 'base64url').subarray(0, 32)
const key = createPrivateKey({ key: Buffer.concat([Buffer.from('302e020100300506032b657004220420', 'hex'), seed]), format: 'der', type: 'pkcs8' })
const signature = sign(null, Buffer.concat([Buffer.from(`cms-signed:1:${payload.type}\n`), bytes]), key)

writeFileSync(file, JSON.stringify({ payload: bytes.toString('base64url'), sigs: [{ kid: process.env.CMS_FEED_KID, sig: signature.toString('base64url') }] }))
