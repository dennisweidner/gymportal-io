#!/usr/bin/env node
// MCP server for the gymportal Agent API. No dependencies, Node 18+.
//
// Speaks the Model Context Protocol over stdio (newline-delimited JSON-RPC)
// and turns each tool call into one HTTP request against /api/v1/agent.
//
//   GYMPORTAL_API_URL    e.g. https://app.example.org   (no trailing slash)
//   GYMPORTAL_API_TOKEN  token from `php artisan agent:token create`
//
// Write tools only preview unless the model passes confirm=true. What a token
// may do at all is decided by its abilities on the server, not here.

import { createInterface } from 'node:readline';

const BASE = (process.env.GYMPORTAL_API_URL || '').replace(/\/+$/, '');
const TOKEN = process.env.GYMPORTAL_API_TOKEN || '';

const id = { type: 'integer', description: 'Member id (from find_member or list_members)' };
const confirm = {
  type: 'boolean',
  description: 'false or omitted: only show what would happen. true: really do it. Ask the human before passing true.',
};

const TOOLS = [
  { name: 'health', description: 'Which gym this token belongs to and what it may do.', props: {}, call: () => ['GET', 'health'] },
  { name: 'get_mirror_status', description: 'Whether this gym is a mirror of an export, how old the data is and how many confirmed writes still wait for a human.', props: {}, call: () => ['GET', 'mirror'] },
  { name: 'list_pending_actions', description: 'Confirmed writes that wait for a human to carry them out in the portal (mirror mode). status: open (default), done, dismissed or all.', props: { status: { type: 'string' } }, call: (a) => ['GET', 'actions', pick(a, ['status'])] },
  { name: 'close_pending_action', description: 'Mark a pending action as done (carried out in the portal) or dismissed.', props: { action_id: { type: 'integer' }, status: { type: 'string', description: 'done or dismissed' } }, required: ['action_id', 'status'], call: (a) => ['POST', `actions/${num(a.action_id)}/close`, null, { status: a.status }] },
  { name: 'list_plans', description: 'Active membership plans with price, commitment and notice period.', props: {}, call: () => ['GET', 'plans'] },
  {
    name: 'find_member',
    description: 'Exact lookup of one member by email, member number or phone. Returns match = unique | none | ambiguous. Never guess on ambiguous.',
    props: { email: { type: 'string' }, member_number: { type: 'string' }, phone: { type: 'string' } },
    call: (a) => ['GET', 'members/lookup', pick(a, ['email', 'member_number', 'phone'])],
  },
  {
    name: 'list_members',
    description: 'Search members by name, email or member number, optionally filtered by status.',
    props: { search: { type: 'string' }, status: { type: 'string' }, page: { type: 'integer' }, per_page: { type: 'integer' } },
    call: (a) => ['GET', 'members', pick(a, ['search', 'status', 'page', 'per_page'])],
  },
  { name: 'get_member', description: 'Contact data and status of one member.', props: { member_id: id }, required: ['member_id'], call: (a) => ['GET', `members/${num(a.member_id)}`] },
  {
    name: 'get_contract',
    description: 'Contract of a member: plan, status, earliest cancellation date and its deadline, pause, withdrawal. During a free period contract_membership is the paid contract these dates refer to. The dates are computed by the portal; do not derive your own.',
    props: { member_id: id }, required: ['member_id'], call: (a) => ['GET', `members/${num(a.member_id)}/contract`],
  },
  {
    name: 'get_access_status',
    description: 'Whether a member should get in, and if not, why (blockers), plus the last check-in.',
    props: { member_id: id }, required: ['member_id'], call: (a) => ['GET', `members/${num(a.member_id)}/access`],
  },
  { name: 'get_check_ins', description: 'Recent visits of a member.', props: { member_id: id, limit: { type: 'integer' } }, required: ['member_id'], call: (a) => ['GET', `members/${num(a.member_id)}/check-ins`, pick(a, ['limit'])] },
  { name: 'get_payments', description: 'Recent payments of a member with a summary of failed and pending ones.', props: { member_id: id, limit: { type: 'integer' } }, required: ['member_id'], call: (a) => ['GET', `members/${num(a.member_id)}/payments`, pick(a, ['limit'])] },
  { name: 'get_occupancy', description: 'Visits by weekday and hour. Says insufficient_data instead of recommending an hour when there are too few visits.', props: { days: { type: 'integer' } }, call: (a) => ['GET', 'occupancy', pick(a, ['days'])] },
  { name: 'get_stats', description: 'Key figures of the gym: members, new sign-ups, status distribution, plans, revenue (revenue needs payments.read).', props: {}, call: () => ['GET', 'stats'] },
  { name: 'list_inactive_members', description: 'Members with a running contract who have not visited for N days: candidates for churn.', props: { days: { type: 'integer' }, limit: { type: 'integer' } }, call: (a) => ['GET', 'insights/inactive-members', pick(a, ['days', 'limit'])] },
  {
    name: 'update_member_contact',
    description: 'Change contact data of a member (phone, address, postal_code, city, country, emergency contact). The e-mail address is the member app login and can only be changed in the portal.',
    props: { member_id: id, confirm, phone: { type: 'string' }, address: { type: 'string' }, address_addition: { type: 'string' }, postal_code: { type: 'string' }, city: { type: 'string' }, country: { type: 'string' }, emergency_contact_name: { type: 'string' }, emergency_contact_phone: { type: 'string' } },
    required: ['member_id'],
    call: (a) => ['PATCH', `members/${num(a.member_id)}`, null, omit(a, ['member_id'])],
  },
  { name: 'add_member_note', description: 'Append a dated note to the member record.', props: { member_id: id, confirm, text: { type: 'string' } }, required: ['member_id', 'text'], call: (a) => ['POST', `members/${num(a.member_id)}/notes`, null, omit(a, ['member_id'])] },
  {
    name: 'cancel_contract',
    description: 'Ordinary cancellation to the next possible date. reason: move | financial | health | dissatisfied | no_time | other.',
    props: { member_id: id, confirm, reason: { type: 'string' }, note: { type: 'string' }, send_confirmation: { type: 'boolean', description: 'Send the confirmation mail to the member.' }, expected_effective_date: { type: 'string', description: 'Required with confirm=true: the effective_date from the preview. If it moved, nothing is stored and the new date comes back.' } },
    required: ['member_id'],
    call: (a) => ['POST', `members/${num(a.member_id)}/contract/cancellation`, null, omit(a, ['member_id'])],
  },
  { name: 'revoke_cancellation', description: 'Take back a cancellation that has not taken effect yet.', props: { member_id: id, confirm }, required: ['member_id'], call: (a) => ['DELETE', `members/${num(a.member_id)}/contract/cancellation`, null, omit(a, ['member_id'])] },
  {
    name: 'pause_contract',
    description: 'Pause the active membership from start_date to end_date (YYYY-MM-DD).',
    props: { member_id: id, confirm, start_date: { type: 'string' }, end_date: { type: 'string' }, reason: { type: 'string' } },
    required: ['member_id', 'start_date', 'end_date'],
    call: (a) => ['POST', `members/${num(a.member_id)}/contract/pause`, null, omit(a, ['member_id'])],
  },
  { name: 'resume_contract', description: 'End a pause as of today.', props: { member_id: id, confirm }, required: ['member_id'], call: (a) => ['POST', `members/${num(a.member_id)}/contract/resume`, null, omit(a, ['member_id'])] },
];

function pick(obj, keys) {
  const out = {};
  for (const k of keys) if (obj[k] !== undefined && obj[k] !== null && obj[k] !== '') out[k] = obj[k];
  return out;
}

function omit(obj, keys) {
  const out = { ...obj };
  for (const k of keys) delete out[k];
  return out;
}

function num(value) {
  const n = Number(value);
  if (!Number.isInteger(n) || n < 1) throw new Error('id must be a positive integer');
  return n;
}

async function callApi(method, path, query, body) {
  if (!BASE || !TOKEN) throw new Error('GYMPORTAL_API_URL and GYMPORTAL_API_TOKEN must be set');
  const url = new URL(`${BASE}/api/v1/agent/${path}`);
  for (const [k, v] of Object.entries(query || {})) url.searchParams.set(k, String(v));
  const res = await fetch(url, {
    method,
    headers: { Authorization: `Bearer ${TOKEN}`, Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}) },
    body: body ? JSON.stringify(body) : undefined,
  });
  const text = await res.text();
  let json;
  try { json = JSON.parse(text); } catch { json = { success: false, error: { code: 'non_json_response', message: text.slice(0, 300) } }; }
  return { status: res.status, json };
}

function send(message) {
  process.stdout.write(`${JSON.stringify(message)}\n`);
}

async function handle(msg) {
  const { id: reqId, method, params } = msg;
  if (method === 'initialize') {
    return send({ jsonrpc: '2.0', id: reqId, result: { protocolVersion: params?.protocolVersion || '2024-11-05', capabilities: { tools: {} }, serverInfo: { name: 'gymportal-agent-api', version: '1.0.0' } } });
  }
  if (method === 'ping') return send({ jsonrpc: '2.0', id: reqId, result: {} });
  if (method === 'tools/list') {
    return send({ jsonrpc: '2.0', id: reqId, result: { tools: TOOLS.map((t) => ({ name: t.name, description: t.description, inputSchema: { type: 'object', properties: t.props, required: t.required || [], additionalProperties: false } })) } });
  }
  if (method === 'tools/call') {
    const tool = TOOLS.find((t) => t.name === params?.name);
    if (!tool) return send({ jsonrpc: '2.0', id: reqId, error: { code: -32602, message: `Unknown tool: ${params?.name}` } });
    try {
      const [httpMethod, path, query, body] = tool.call(params.arguments || {});
      const { status, json } = await callApi(httpMethod, path, query, body);
      return send({ jsonrpc: '2.0', id: reqId, result: { content: [{ type: 'text', text: JSON.stringify(json, null, 2) }], isError: status >= 400 || json?.error?.code === 'non_json_response' } });
    } catch (error) {
      return send({ jsonrpc: '2.0', id: reqId, result: { content: [{ type: 'text', text: String(error.message || error) }], isError: true } });
    }
  }
  if (reqId !== undefined) send({ jsonrpc: '2.0', id: reqId, error: { code: -32601, message: `Method not found: ${method}` } });
}

const pending = new Set();
const rl = createInterface({ input: process.stdin });
rl.on('line', (line) => {
  if (!line.trim()) return;
  let msg;
  try { msg = JSON.parse(line); } catch { return send({ jsonrpc: '2.0', id: null, error: { code: -32700, message: 'Parse error' } }); }
  const job = handle(msg).finally(() => pending.delete(job));
  pending.add(job);
});
rl.on('close', async () => { await Promise.allSettled([...pending]); process.exit(0); });
