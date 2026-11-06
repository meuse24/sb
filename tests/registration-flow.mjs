// Real database/API integration; mail is captured privately in the disposable tree.
// No email is sent. AUTH_TEST_LIVE=1 node tests/registration-flow.mjs <php> [PHP options]
import assert from 'node:assert/strict';
import {execFileSync,spawn} from 'node:child_process';
import {once} from 'node:events';
import {cp,mkdtemp,mkdir,writeFile,readFile,rm,access} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join,resolve,dirname,basename} from 'node:path';
import {createServer} from 'node:net';
import {randomBytes,createHash} from 'node:crypto';
import {setTimeout as delay} from 'node:timers/promises';
import {runInNewContext} from 'node:vm';
if(process.env.AUTH_TEST_LIVE!=='1'){console.log('Set AUTH_TEST_LIVE=1 to run registration tests; no mail will be sent.');process.exit(0);}
const root=resolve(import.meta.dirname,'..');
const php=process.argv[2]||'php', options=process.argv.slice(3);
const config=JSON.parse(execFileSync(php,[...options,'-r','echo json_encode(require "config/config.local.php");'],{cwd:root,encoding:'utf8',windowsHide:true}));
const suffix=randomBytes(8).toString('hex');
const names=['main','existing','expired','resend','race','failed','rate','direct','directRace','directRate'];
const emails=Object.fromEntries(names.map(name=>[name,`registration-${name.toLowerCase()}-${suffix}@example.invalid`]));
const password=randomBytes(24).toString('base64url');
const passwordHash=execFileSync(php,[...options,'-r','echo password_hash(trim(stream_get_contents(STDIN)), PASSWORD_DEFAULT);'],{input:password,encoding:'utf8',windowsHide:true});
const digest=text=>createHash('sha256').update(text).digest('hex');
const hashes=new Set();
const temp=await mkdtemp(join(tmpdir(),'supabase-registration-test-'));
const probe=createServer().listen(0,'127.0.0.1');await once(probe,'listening');const port=probe.address().port;await new Promise(resolve=>probe.close(resolve));
let server;
async function api(method,path,body){const r=await fetch(config.supabase_url+'/rest/v1/'+path,{method,headers:{apikey:config.supabase_secret_key,'Content-Type':'application/json',Prefer:'return=representation'},...(body===undefined?{}:{body:JSON.stringify(body)})});assert.ok(r.ok,'Database request failed: '+r.status);const text=await r.text();return text?JSON.parse(text):null;}
const filter=email=>'email=eq.'+encodeURIComponent(email);
const exists=async path=>{try{await access(path);return true;}catch{return false;}};
const mailbox=join(temp,'mailbox.json');
const configSource=settings=>"<?php return json_decode(base64_decode('"+Buffer.from(JSON.stringify(settings)).toString('base64')+"'),true);";
try{
 await cp(join(root,'app'),join(temp,'app'),{recursive:true});await cp(join(root,'public'),join(temp,'public'),{recursive:true});await mkdir(join(temp,'config'));await writeFile(join(temp,'config/config.local.php'),configSource({...config,registration_require_email_confirmation:true}));await mkdir(join(temp,'sessions'));
 // Only this temporary mail adapter is replaced. No production mail is sent.
 await writeFile(join(temp,'app/mail.php'),`<?php
function registrationRequiresConfirmation(): bool { return ((require dirname(__DIR__) . '/config/config.local.php')['registration_require_email_confirmation'] ?? false) === true; }
function registrationMailReady(): bool { return !is_file(dirname(__DIR__) . '/mail-unavailable'); }
function sendRegistrationMail(string $email, string $token): void {
 if (is_file(dirname(__DIR__) . '/mail-failed')) throw new RuntimeException('Simulated delivery failure');
 file_put_contents(dirname(__DIR__) . '/mailbox.json', json_encode(['email'=>$email, 'token'=>$token]));
}
`);
 await writeFile(join(temp,'public/fixture.php'),`<?php require dirname(__DIR__) . '/app/bootstrap.php'; header('Content-Type: application/json'); echo json_encode($_SESSION);`);
 server=spawn(php,[...options,'-d','opcache.enable=0','-d','opcache.enable_cli=0','-d',`session.save_path=${join(temp,'sessions')}`,'-S',`127.0.0.1:${port}`,'-t',join(temp,'public')],{windowsHide:true,stdio:['ignore','ignore','pipe']});server.stderr.resume();
 let cookie='';
 async function request(path,form){const r=await fetch(`http://127.0.0.1:${port}/`+path,{redirect:'manual',headers:cookie?{Cookie:cookie}:{},...(form?{method:'POST',body:new URLSearchParams(form)}:{})});const c=r.headers.get('set-cookie');if(c)cookie=c.split(';')[0];return{status:r.status,headers:r.headers,body:await r.text()};}
 let ready=false;for(let i=0;i<60;i++){try{await request('register.php');ready=true;break;}catch{await delay(50);}}assert.ok(ready,'PHP did not start.');
 const csrf=body=>body.match(/name="csrf" value="([a-f0-9]+)"/)[1];
 async function register(email,displayName='Test',extra={}){await rm(mailbox,{force:true});const form=await request('register.php');return request('register.php',{csrf:csrf(form.body),email,display_name:displayName,...extra});}
 async function capture(){const value=JSON.parse(await readFile(mailbox,'utf8'));assert.ok(/^[a-f0-9]{64}$/.test(value.token),'Invalid captured token.');hashes.add(digest(value.token));return value;}
 async function start(token){const form=await request('confirm.php');const r=await request('confirm.php',{csrf:csrf(form.body),action:'start',token});assert.equal(r.status,303);assert.equal(r.headers.get('location'),'confirm.php');return request('confirm.php');}
 async function confirm(token){const form=await start(token);assert.ok(!form.body.includes(token),'Raw token rendered.');return request('confirm.php',{csrf:csrf(form.body),action:'confirm',password,confirm_password:password});}
 let form=await request('register.php');assert.equal(form.status,200);
 assert.equal((await request('register.php',{csrf:'invalid',email:emails.main})).status,403);
 assert.equal((await register('invalid')).status,422);
 assert.equal((await register(emails.main,'x'.repeat(101))).status,422);
 const name='<script>alert(1)</script> 👋';
 assert.equal((await register(' '+emails.main.toUpperCase()+' ',name)).status,303);
 const mail=await capture();assert.equal(mail.email,emails.main);
 const pending=(await api('GET',`registration_requests?${filter(emails.main)}`))[0];
 assert.ok(pending.token_hash===digest(mail.token)&&pending.token_hash!==mail.token,'Token must be hashed.');
 assert.equal((await api('GET',`users?${filter(emails.main)}&select=id`)).length,0);
 assert.ok(Date.parse(pending.expires_at)-Date.now()>29*60*1000);
 assert.equal((await register(emails.main,'Overwrite')).status,303);assert.equal(await exists(mailbox),false);
 assert.ok((await api('GET',`registration_requests?${filter(emails.main)}&select=token_hash`))[0].token_hash===digest(mail.token),'Cooldown changed the token.');
 await api('POST','users',{email:emails.existing,password_hash:passwordHash,display_name:'Existing'});
 assert.equal((await register(emails.existing)).status,303);assert.equal(await exists(mailbox),false);
 // Exercise the browser fragment bridge without exposing a token in any request URL.
 let fragmentCleared=false,submitted=false;const input={value:''};
 runInNewContext(await readFile(join(root,'public/assets/confirmation.js'),'utf8'),{URLSearchParams,window:{location:{hash:'#token='+mail.token,pathname:'/confirm.php',search:''},history:{replaceState(_state,_title,url){assert.equal(url,'/confirm.php');fragmentCleared=true;}}},document:{getElementById(id){return id==='confirmation-token'?input:{requestSubmit(){submitted=true;}};}}});
 assert.ok(fragmentCleared&&submitted&&input.value===mail.token,'Fragment bridge failed.');
 form=await request('confirm.php');assert.equal((await request('confirm.php',{csrf:'bad',action:'start',token:mail.token})).status,403);
 form=await start(mail.token);let token=csrf(form.body);
 const state=JSON.parse((await request('fixture.php')).body);assert.ok(state.registration_grant.hash===digest(mail.token)&&!JSON.stringify(state).includes(mail.token),'Session must only retain the token hash.');
 assert.equal((await request('confirm.php',{csrf:'bad',action:'confirm',password,confirm_password:password})).status,403);
 assert.equal((await request('confirm.php',{csrf:token,action:'confirm',password:'short',confirm_password:'short'})).status,422);
 assert.equal((await request('confirm.php',{csrf:token,action:'confirm',password,confirm_password:'mismatch'})).status,422);
 assert.equal((await api('GET',`users?${filter(emails.main)}&select=id`)).length,0);
 let result=await request('confirm.php',{csrf:token,action:'confirm',password,confirm_password:password});assert.equal(result.status,303);assert.equal(result.headers.get('location'),'login.php?registered=1');
 const saved=(await api('GET',`users?${filter(emails.main)}&select=display_name,password_hash,auth_version,email_confirmed_at`))[0];assert.equal(saved.display_name,name);assert.equal(saved.auth_version,1);assert.ok(saved.password_hash!==password,'Password must be hashed.');assert.equal(typeof saved.email_confirmed_at,'string');
 form=await request('login.php');assert.equal((await request('login.php',{csrf:csrf(form.body),email:emails.main,password})).status,303);
 form=await request('profile.php');assert.equal(form.status,200);assert.ok(form.body.includes('&lt;script&gt;')&&!form.body.includes('<script>'));await request('logout.php',{csrf:csrf(form.body)});
 assert.equal((await confirm(mail.token)).status,400,'Replay must fail.');
 assert.ok((await api('GET',`users?${filter(emails.main)}&select=password_hash`))[0].password_hash===saved.password_hash,'Replay changed the password.');
 assert.equal((await register(emails.main)).status,303);assert.equal(await exists(mailbox),false);
 assert.ok((await api('GET',`users?${filter(emails.existing)}&select=password_hash`))[0].password_hash===passwordHash,'Existing account changed.');
 await register(emails.expired);const expired=await capture();await api('PATCH',`registration_requests?${filter(emails.expired)}`,{expires_at:new Date(Date.now()-1000).toISOString()});assert.equal((await confirm(expired.token)).status,400);
 assert.equal((await api('GET',`users?${filter(emails.expired)}&select=id`)).length,0);
 await register(emails.resend);const first=await capture();await api('PATCH',`registration_requests?${filter(emails.resend)}`,{created_at:new Date(Date.now()-61000).toISOString()});await register(emails.resend);const replacement=await capture();assert.ok(first.token!==replacement.token);
 assert.equal((await confirm(first.token)).status,400);assert.equal((await confirm(replacement.token)).status,303);
 await register(emails.race);const race=await capture();const results=await Promise.all([api('POST','rpc/confirm_registration',{p_token_hash:digest(race.token),p_password_hash:passwordHash}),api('POST','rpc/confirm_registration',{p_token_hash:digest(race.token),p_password_hash:passwordHash})]);assert.deepEqual(results.map(value=>value.confirmed).sort(),[false,true]);assert.equal((await api('GET',`users?${filter(emails.race)}&select=id`)).length,1);
 await writeFile(join(temp,'mail-failed'),'');assert.equal((await register(emails.failed)).status,303);assert.equal((await api('GET',`registration_requests?${filter(emails.failed)}&select=token_hash`)).length,0);await rm(join(temp,'mail-failed'));
 await writeFile(join(temp,'mail-unavailable'),'');assert.equal((await request('register.php')).status,503);await rm(join(temp,'mail-unavailable'));
 for(let i=1;i<=11;i++){cookie='';assert.equal((await register(emails.rate)).status,i<=10?303:429,`Registration limit attempt ${i}`);if(await exists(mailbox))await capture();}
 // Current user-selected mode: immediate registration, with SMTP unavailable.
 await api('DELETE','login_attempts?bucket=eq.'+digest('ip:127.0.0.1'));
 await writeFile(join(temp,'config/config.local.php'),configSource({...config,registration_require_email_confirmation:false}));
 await writeFile(join(temp,'mail-unavailable'),'');await writeFile(join(temp,'mail-failed'),'');
 cookie='';form=await request('register.php');assert.equal(form.status,200);assert.ok(form.body.includes('name="password"'));
 assert.equal((await request('confirm.php')).headers.get('location'),'register.php');
 const directFields={password,confirm_password:password};
 assert.equal((await register(emails.direct)).status,422);
 assert.equal((await register(emails.direct,'Test',{password:'short',confirm_password:'short'})).status,422);
 assert.equal((await register(emails.direct,'Test',{password,confirm_password:'different'})).status,422);
 assert.equal((await register(emails.direct,'Test',{password:'😀'.repeat(3),confirm_password:'😀'.repeat(3)})).status,422);
 assert.equal((await request('register.php',{csrf:'bad',email:emails.direct,...directFields})).status,403);
 result=await register(emails.direct,name,directFields);assert.equal(result.status,303);assert.equal(result.headers.get('location'),'login.php?registered=1');assert.equal(await exists(mailbox),false);
 const directSaved=(await api('GET',`users?${filter(emails.direct)}&select=display_name,password_hash,is_active,email_confirmed_at`))[0];assert.equal(directSaved.display_name,name);assert.equal(directSaved.is_active,true);assert.equal(directSaved.email_confirmed_at,null);assert.ok(directSaved.password_hash!==password,'Direct registration stored plaintext.');
 assert.equal((await api('GET',`registration_requests?${filter(emails.direct)}&select=token_hash`)).length,0);
 form=await request('login.php');assert.equal((await request('login.php',{csrf:csrf(form.body),email:emails.direct,password})).status,303);form=await request('profile.php');assert.equal(form.status,200);assert.ok(form.body.includes('&lt;script&gt;'));await request('logout.php',{csrf:csrf(form.body)});
 result=await register(emails.direct,'Overwrite',{password:password+'x',confirm_password:password+'x'});assert.equal(result.status,409);assert.ok(!result.body.includes(password),'Password echoed after duplicate registration.');
 const duplicate=(await api('GET',`users?${filter(emails.direct)}&select=display_name,password_hash,auth_version,email_confirmed_at`))[0];assert.equal(duplicate.display_name,name);assert.equal(duplicate.auth_version,1);assert.equal(duplicate.email_confirmed_at,null);assert.ok(duplicate.password_hash===directSaved.password_hash,'Duplicate registration overwrote account.');
 const directRaces=await Promise.all([api('POST','rpc/register_user',{p_email:emails.directRace,p_display_name:'Race',p_password_hash:passwordHash}),api('POST','rpc/register_user',{p_email:emails.directRace,p_display_name:'Race',p_password_hash:passwordHash})]);assert.deepEqual(directRaces.map(value=>value.created).sort(),[false,true]);assert.equal((await api('GET',`users?${filter(emails.directRace)}&select=id`)).length,1);
 for(let i=1;i<=11;i++){cookie='';assert.equal((await register(emails.directRate,'Rate',directFields)).status,i===1?303:(i<=10?409:429),`Direct registration limit attempt ${i}`);}
 assert.equal(await exists(mailbox),false,'Disabled confirmation sent mail.');
 console.log('PASS registration: immediate signup/login without SMTP, password validation, unverified-email metadata, duplicate/concurrent account protection and persistent limits; optional hashed-token confirmation, cooldown, expiry/replay and mail-failure cleanup. No mail sent.');
}finally{
 if(server&&server.exitCode===null){server.kill();await once(server,'exit');}
 for(const email of Object.values(emails)){await api('DELETE',`registration_requests?${filter(email)}`);await api('DELETE',`users?${filter(email)}`);for(const identity of ['email:'+email,'email:registration:'+email])await api('DELETE','login_attempts?bucket=eq.'+digest(identity));}
 for(const hash of hashes)await api('DELETE','login_attempts?bucket=eq.'+digest('email:registration-confirm:'+hash));await api('DELETE','login_attempts?bucket=eq.'+digest('ip:127.0.0.1'));
 assert.equal(dirname(temp),resolve(tmpdir()));assert.match(basename(temp),/^supabase-registration-test-/);await rm(temp,{recursive:true,force:true});
}
