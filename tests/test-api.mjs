/**
 * supabase-lite integration tests.
 * Runs against a live PHP server with the REAL @supabase/supabase-js client —
 * that is the compatibility proof: if the blogpost frontend's client works here,
 * the frontend works unchanged against the lite backend.
 *
 * Usage: node tests/test-api.mjs [baseURL]
 */
import { createClient } from '@supabase/supabase-js';
import { randomUUID } from 'node:crypto';

const BASE = process.argv[2] || 'http://127.0.0.1:8080';
const results = [];
let failed = 0;

function ok(name, cond, detail = '') {
  results.push({ name, pass: !!cond, detail });
  if (!cond) failed++;
  console.log(`${cond ? 'PASS' : 'FAIL'}  ${name}${detail ? ' — ' + detail : ''}`);
}

async function main() {
  const anonKeyRes = await fetch(`${BASE}/functions/v1/anon-key`).catch(() => null);
  // anon key is served from /auth/v1/settings or hardcoded; use the well-known key
  const ANON = process.env.SB_LITE_ANON || 'anon-key';

  // Discover anon key: try /auth/v1/health which does not require auth
  const health = await fetch(`${BASE}/auth/v1/health`);
  ok('auth health endpoint', health.ok, `status ${health.status}`);

  const email = `test-${randomUUID().slice(0, 8)}@example.com`;
  const password = 'secret123';
  const sb = createClient(BASE, ANON, { auth: { persistSession: false, autoRefreshToken: false } });

  // ── signup ──
  const { data: signData, error: signErr } = await sb.auth.signUp({
    email,
    password,
    options: { data: { name: 'Integration Tester' } },
  });
  ok('signUp returns session', !signErr && !!signData?.session, signErr?.message || JSON.stringify(signData).slice(0, 120));
  ok('signUp user metadata name', signData?.user?.user_metadata?.name === 'Integration Tester', JSON.stringify(signData?.user?.user_metadata));
  const userId = signData?.user?.id;

  // ── login ──
  const { data: loginData, error: loginErr } = await sb.auth.signInWithPassword({ email, password });
  ok('signInWithPassword works', !loginErr && !!loginData?.session, loginErr?.message);
  ok('login returns access_token', !!loginData?.session?.access_token);
  ok('login returns refresh_token', !!loginData?.session?.refresh_token);

  // ── getUser ──
  const { data: userData, error: userErr } = await sb.auth.getUser();
  ok('getUser returns user', !userErr && userData?.user?.email === email, userErr?.message);

  // ── profile row auto-created by trigger ──
  const { data: profile, error: profErr } = await sb.from('profiles').select('*').eq('id', userId).single();
  ok('profile auto-created on signup', !profErr && profile?.email === email, profErr?.message);
  ok('profile name from metadata', profile?.name === 'Integration Tester', `got ${profile?.name}`);
  ok('profile default subscription is free', profile?.subscription === 'free');

  // ── posts CRUD ──
  const postId = randomUUID();
  const { data: insPost, error: insErr } = await sb.from('posts').insert({
    id: postId,
    user_id: userId,
    title: 'Hello world',
    content: '<p>Body</p>',
    topic: 'testing',
    type: 'post',
    status: 'draft',
    social_networks: ['vk', 'telegram'],
    scheduled_dates: ['2026-12-01'],
    has_image: true,
  }).select().single();
  ok('insert post returns row', !insErr && insPost?.id === postId, insErr?.message);
  ok('post social_networks is array', Array.isArray(insPost?.social_networks) && insPost.social_networks[0] === 'vk', JSON.stringify(insPost?.social_networks));
  ok('post has_image is boolean true', insPost?.has_image === true, typeof insPost?.has_image);

  const { data: selPost, error: selErr } = await sb.from('posts').select('*').eq('id', postId).single();
  ok('select post by id', !selErr && selPost?.title === 'Hello world', selErr?.message);

  const { data: updPost, error: updErr } = await sb.from('posts')
    .update({ status: 'queued', views: 5, scheduled_dates: ['2026-12-01', '2026-12-08'] })
    .eq('id', postId).select().single();
  ok('update post returns row', !updErr && updPost?.status === 'queued', updErr?.message);
  ok('update post arrays persist', Array.isArray(updPost?.scheduled_dates) && updPost.scheduled_dates.length === 2, JSON.stringify(updPost?.scheduled_dates));

  const { data: listPosts, error: listErr } = await sb.from('posts').select('*').eq('user_id', userId).order('created_at', { ascending: false });
  ok('list posts by user_id', !listErr && Array.isArray(listPosts) && listPosts.length >= 1, listErr?.message);

  // ── analytics upsert (onConflict) ──
  const up1 = await sb.from('analytics').upsert({
    id: randomUUID(), user_id: userId, date: '2026-11-11', network: 'vk',
    views: 10, likes: 2, shares: 1, publications: 1,
  }, { onConflict: 'user_id,date,network' }).select().single();
  ok('analytics upsert #1', !up1.error, up1.error?.message);
  const up2 = await sb.from('analytics').upsert({
    id: randomUUID(), user_id: userId, date: '2026-11-11', network: 'vk',
    views: 15, likes: 3, shares: 1, publications: 1,
  }, { onConflict: 'user_id,date,network' }).select().single();
  ok('analytics upsert #2 on same key', !up2.error, up2.error?.message);
  const { data: anRows } = await sb.from('analytics').select('*').eq('user_id', userId).eq('network', 'vk');
  ok('analytics upsert deduplicates', Array.isArray(anRows) && anRows.length === 1, `rows=${anRows?.length}`);
  ok('analytics upsert updates values', anRows?.[0]?.views === 15, `views=${anRows?.[0]?.views}`);

  // ── gte / lt filters ──
  const { data: gteRows, error: gteErr } = await sb.from('analytics').select('*').eq('user_id', userId).gte('date', '2026-11-01').lt('date', '2026-12-01');
  ok('gte/lt date range filter', !gteErr && gteRows?.length === 1, gteErr?.message || `rows=${gteRows?.length}`);

  // ── count exact (head) ──
  const { count, error: cntErr } = await sb.from('posts').select('id', { count: 'exact', head: true }).eq('user_id', userId);
  ok('count=exact head request', !cntErr && count >= 1, `count=${count} err=${cntErr?.message}`);

  // ── auto_tasks upsert (id conflict) ──
  const taskId = randomUUID();
  const t1 = await sb.from('auto_tasks').upsert({
    id: taskId, user_id: userId, name: 'Daily posts', content_type: 'post',
    frequency: 'daily', schedule: { time: '10:00', days: ['mon', 'wed'] },
    networks: ['vk'], topics: ['tech'], active: true, generated_count: 0,
  }, { onConflict: 'id' }).select().single();
  ok('auto_tasks upsert with JSON schedule', !t1.error && t1.data?.schedule?.time === '10:00', t1.error?.message);
  ok('auto_tasks networks is array', Array.isArray(t1.data?.networks) && t1.data.networks[0] === 'vk');
  ok('auto_tasks active is boolean true', t1.data?.active === true, typeof t1.data?.active);

  // ── social_accounts upsert on (user_id, network) ──
  const s1 = await sb.from('social_accounts').upsert({
    id: randomUUID(), user_id: userId, network: 'vk', account_name: 'My VK',
    account_id: '-123', access_token: 'tok-abc', connected: true,
    config: { method: 'api', autoPublish: true },
  }, { onConflict: 'user_id,network' }).select().single();
  ok('social_accounts upsert', !s1.error, s1.error?.message);
  ok('social_accounts config JSON roundtrip', s1.data?.config?.autoPublish === true, JSON.stringify(s1.data?.config));
  ok('social_accounts connected boolean', s1.data?.connected === true, typeof s1.data?.connected);
  const s2 = await sb.from('social_accounts').upsert({
    id: randomUUID(), user_id: userId, network: 'vk', account_name: 'My VK renamed',
    account_id: '-123', access_token: 'tok-def', connected: true, config: { method: 'api' },
  }, { onConflict: 'user_id,network' }).select().single();
  ok('social_accounts upsert dedupes on (user_id,network)', !s2.error, s2.error?.message);
  const { data: socRows } = await sb.from('social_accounts').select('*').eq('user_id', userId);
  ok('social_accounts single row after upsert', socRows?.length === 1, `rows=${socRows?.length}`);

  // ── billing_entries insert ──
  const b1 = await sb.from('billing_entries').upsert({
    id: randomUUID(), user_id: userId, kind: 'payment', amount: 5000,
    currency: 'RUB', description: 'Top-up', method: 'demo', status: 'succeeded',
  }, { onConflict: 'id' }).select().single();
  ok('billing_entries insert', !b1.error, b1.error?.message);

  // ── promo_codes + rpc use_promo_code ──
  const code = 'TEST' + randomUUID().slice(0, 6).toUpperCase();
  await fetch(`${BASE}/rest/v1/promo_codes`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', apikey: ANON, Authorization: `Bearer ${loginData.session.access_token}`, Prefer: 'return=representation' },
    body: JSON.stringify({ code, discount_percent: 15, max_uses: 10, used_count: 0, active: true }),
  });
  const { data: rpcResult, error: rpcErr } = await sb.rpc('use_promo_code', { code_text: code, user_id: userId });
  ok('rpc use_promo_code returns true', !rpcErr && rpcResult === true, rpcErr?.message || JSON.stringify(rpcResult));
  const { data: rpcResult2 } = await sb.rpc('use_promo_code', { code_text: 'NOPE-NOPE', user_id: userId });
  ok('rpc use_promo_code false for unknown code', rpcResult2 === false, JSON.stringify(rpcResult2));

  // ── RLS-style isolation: another user must not see first user's posts ──
  const email2 = `test2-${randomUUID().slice(0, 8)}@example.com`;
  const sb2 = createClient(BASE, ANON, { auth: { persistSession: false, autoRefreshToken: false } });
  const { data: sign2 } = await sb2.auth.signUp({ email: email2, password });
  await sb2.auth.signInWithPassword({ email: email2, password });
  const { data: otherPosts, error: otherErr } = await sb2.from('posts').select('*');
  ok('RLS: second user sees no foreign posts', !otherErr && (otherPosts?.length ?? 0) === 0, `rows=${otherPosts?.length} err=${otherErr?.message}`);

  // ── delete ──
  const { error: delErr } = await sb.from('posts').delete().eq('id', postId);
  ok('delete post', !delErr, delErr?.message);
  const { data: afterDel } = await sb.from('posts').select('*').eq('id', postId);
  ok('post gone after delete', !afterDel || afterDel.length === 0);

  // ── refresh token ──
  const { data: refData, error: refErr } = await sb.auth.refreshSession({ refresh_token: loginData.session.refresh_token });
  ok('refreshSession returns new tokens', !refErr && !!refData?.session?.access_token, refErr?.message);

  // ── logout ──
  const { error: outErr } = await sb.auth.signOut();
  ok('signOut works', !outErr, outErr?.message);

  // ── api/generate-health ──
  const gh = await fetch(`${BASE}/api/generate-health`);
  ok('api/generate-health responds', gh.ok && (await gh.text()) === 'ok');

  // ── functions/v1/subscription-expiry ──
  const se = await fetch(`${BASE}/functions/v1/subscription-expiry`, { method: 'POST' });
  ok('subscription-expiry function responds', se.ok, `status ${se.status}`);

  console.log('\n────────────────────────────────');
  console.log(`${results.length - failed}/${results.length} passed, ${failed} failed`);
  process.exit(failed ? 1 : 0);
}

main().catch((e) => {
  console.error('Test run crashed:', e);
  process.exit(1);
});
