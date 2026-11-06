// Opt-in CRUD integration. Uses disposable accounts only; can also test deployed pages.
// AUTH_TEST_LIVE=1 node tests/admin-flow.mjs <php> [PHP options]
// Optional ADMIN_TEST_BASE_URL=https://your-site/public/
import assert from 'node:assert/strict';
import {spawn,execFileSync} from 'node:child_process';
import {once} from 'node:events';
import {cp,mkdtemp,mkdir,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join,resolve,dirname,basename} from 'node:path';
import {createServer} from 'node:net';
import {randomBytes,createHash} from 'node:crypto';
import {setTimeout as delay} from 'node:timers/promises';
if(process.env.AUTH_TEST_LIVE!=='1'){console.log('Set AUTH_TEST_LIVE=1 for temporary-account admin integration tests.');process.exit(0);}
const root=resolve(import.meta.dirname,'..'),php=process.argv[2]||'php',options=process.argv.slice(3);
const config=JSON.parse(execFileSync(php,[...options,'-r','echo json_encode(require "config/config.local.php");'],{cwd:root,windowsHide:true,encoding:'utf8'}));
const prefix='admin-test-'+randomBytes(8).toString('hex');
const emails=Object.fromEntries(['a','b','normal','target','changed','signup'].map(name=>[name,`${prefix}-${name}@example.invalid`]));
const allEmails=new Set(Object.values(emails));
const password=randomBytes(24).toString('base64url'),resetPassword=randomBytes(24).toString('base64url');
const passwordHash=execFileSync(php,[...options,'-r','echo password_hash(trim(stream_get_contents(STDIN)), PASSWORD_DEFAULT);'],{windowsHide:true,encoding:'utf8',input:password});
const digest=value=>createHash('sha256').update(value).digest('hex');
let base=process.env.ADMIN_TEST_BASE_URL,temp,server;
const ids={};
async function api(method,path,body){const r=await fetch(config.supabase_url+'/rest/v1/'+path,{method,headers:{apikey:config.supabase_secret_key,'Content-Type':'application/json',Prefer:'return=representation'},...(body===undefined?{}:{body:JSON.stringify(body)})});assert.ok(r.ok,'Database HTTP '+r.status+' during '+method+' '+path.split('?')[0]);const text=await r.text();return text?JSON.parse(text):null;}
function client(){let cookie='';return async(path,form)=>{const r=await fetch(base+path,{redirect:'manual',headers:cookie?{Cookie:cookie}:{},...(form?{method:'POST',body:new URLSearchParams(form)}:{})});const c=r.headers.get('set-cookie');if(c)cookie=c.split(';')[0];return{status:r.status,headers:r.headers,body:await r.text()};};}
const csrf=body=>body.match(/name="csrf" value="([a-f0-9]+)"/)[1];
const version=body=>body.match(/name="expected_version" value="([0-9]+)"/)[1];
async function login(request,email,candidate=password){const form=await request('login.php');return request('login.php',{csrf:csrf(form.body),email,password:candidate});}
try{
 if(!base){temp=await mkdtemp(join(tmpdir(),'supabase-admin-test-'));await cp(join(root,'app'),join(temp,'app'),{recursive:true});await cp(join(root,'public'),join(temp,'public'),{recursive:true});await mkdir(join(temp,'config'));await cp(join(root,'config/config.local.php'),join(temp,'config/config.local.php'));await mkdir(join(temp,'sessions'));const probe=createServer().listen(0,'127.0.0.1');await once(probe,'listening');const port=probe.address().port;await new Promise(resolve=>probe.close(resolve));base=`http://127.0.0.1:${port}/`;server=spawn(php,[...options,'-d','opcache.enable=0','-d','opcache.enable_cli=0','-d',`session.save_path=${join(temp,'sessions')}`,'-S',`127.0.0.1:${port}`,'-t',join(temp,'public')],{windowsHide:true,stdio:['ignore','ignore','pipe']});server.stderr.resume();let ready=false;for(let i=0;i<60;i++){try{await fetch(base+'login.php');ready=true;break;}catch{await delay(50);}}assert.ok(ready,'PHP did not start.');}
 base=base.replace(/\/?$/,'/');
 for(const name of ['a','b','normal']){const result=await api('POST','users?select=id',{email:emails[name],display_name:name==='normal'?'<script>normal</script>':name,password_hash:passwordHash,is_admin:name!=='normal'});ids[name]=result[0].id;}
 const anonymous=client(),normal=client(),a=client(),b=client();
 for(const path of ['admin.php','admin-user.php','admin-delete.php?id='+ids.normal])assert.equal((await anonymous(path)).headers.get('location'),'login.php');
 assert.equal((await login(normal,emails.normal)).status,303);let page=await normal('profile.php');assert.ok(!page.body.includes('href="admin.php"'));const normalToken=csrf(page.body);
 for(const path of ['admin.php','admin-user.php','admin-delete.php?id='+ids.a])assert.equal((await normal(path)).status,403);
 assert.equal((await normal('admin-user.php',{csrf:normalToken,email:emails.target,display_name:'Attack',password,confirm_password:password,is_admin:'1',is_active:'1',actor_id:ids.a})).status,403);
 assert.equal((await normal('admin-delete.php?id='+ids.a,{csrf:normalToken,expected_version:'1',confirm_delete:'1'})).status,403);
 assert.equal((await normal('profile.php',{csrf:normalToken,display_name:'Normal',is_admin:'1'})).status,303);
 assert.equal((await api('GET','users?id=eq.'+ids.normal+'&select=is_admin'))[0].is_admin,false);
 // The public registration form must never grant admin flags.
 page=await anonymous('register.php');assert.equal((await anonymous('register.php',{csrf:csrf(page.body),email:emails.signup,display_name:'Signup',password,confirm_password:password,is_admin:'1',role:'admin'})).status,303);assert.equal((await api('GET','users?email=eq.'+encodeURIComponent(emails.signup)+'&select=is_admin'))[0].is_admin,false);
 assert.equal((await login(a,emails.a)).status,303);assert.equal((await login(b,emails.b)).status,303);
 page=await a('profile.php');assert.ok(page.body.includes('href="admin.php"'));
 page=await a('admin.php?q='+prefix);assert.equal(page.status,200);assert.ok(page.body.includes(emails.a)&&page.body.includes(emails.normal));assert.ok(!page.body.includes('password_hash')&&!page.body.includes(password));
 page=await a('admin-user.php');let token=csrf(page.body);
 const create={csrf:token,email:emails.target,display_name:'<script>Target</script>',password,confirm_password:password,is_active:'1'};
 assert.equal((await a('admin-user.php',{...create,csrf:'bad'})).status,403);
 assert.equal((await a('admin-user.php',{...create,password:'short',confirm_password:'short'})).status,422);
 assert.equal((await a('admin-user.php',{...create,email:'invalid'})).status,422);
 assert.equal((await a('admin-user.php',create)).status,303);
 let target=(await api('GET','users?email=eq.'+encodeURIComponent(emails.target)))[0];ids.target=target.id;assert.equal(target.is_admin,false);assert.ok(target.password_hash!==password);
 assert.equal((await a('admin-user.php',create)).status,409);
 const targetOld=client();assert.equal((await login(targetOld,emails.target)).status,303);
 page=await a('admin.php?q='+prefix);assert.ok(page.body.includes('&lt;script&gt;Target&lt;/script&gt;')&&!page.body.includes('<script>'));
 await api('PATCH','users?id=eq.'+target.id,{email_confirmed_at:new Date().toISOString()});
 page=await a('admin-user.php?id='+target.id);token=csrf(page.body);const originalVersion=version(page.body);
 const edit={csrf:token,expected_version:originalVersion,email:emails.changed,display_name:'Changed',is_active:'1',is_admin:'1',password:'',confirm_password:'',auth_version:'999'};
 assert.equal((await a('admin-user.php?id='+target.id,{...edit,csrf:'bad'})).status,403);
 assert.equal((await a('admin-user.php?id='+target.id,edit)).status,303);
 target=(await api('GET','users?id=eq.'+target.id))[0];assert.equal(target.auth_version,2);assert.equal(target.is_admin,true);assert.equal(target.email_confirmed_at,null);assert.ok(target.password_hash!==resetPassword);
 assert.equal((await a('admin-user.php?id='+target.id,{...edit,display_name:'Stale'})).status,409);
 assert.equal((await targetOld('profile.php')).headers.get('location'),'login.php?expired=1');
 const promoted=client();assert.equal((await login(promoted,emails.changed)).status,303);assert.equal((await promoted('admin.php')).status,200);
 page=await a('admin-user.php?id='+target.id);const reset={csrf:csrf(page.body),expected_version:version(page.body),email:emails.changed,display_name:'Disabled',password:resetPassword,confirm_password:resetPassword};
 assert.equal((await a('admin-user.php?id='+target.id,reset)).status,303);
 target=(await api('GET','users?id=eq.'+target.id))[0];assert.equal(target.auth_version,3);assert.equal(target.is_admin,false);assert.equal(target.is_active,false);assert.ok(target.password_hash!==resetPassword);
 assert.equal((await promoted('admin.php')).headers.get('location'),'login.php?expired=1');assert.equal((await login(client(),emails.changed,resetPassword)).status,401);
 page=await a('admin-user.php?id='+target.id);assert.equal((await a('admin-user.php?id='+target.id,{csrf:csrf(page.body),expected_version:version(page.body),email:emails.changed,display_name:'Enabled',is_active:'1',password:'',confirm_password:''})).status,303);
 assert.equal((await login(client(),emails.changed,password)).status,401);const targetNew=client();assert.equal((await login(targetNew,emails.changed,resetPassword)).status,303);
 page=await a('admin-delete.php?id='+target.id);token=csrf(page.body);assert.equal((await api('GET','users?id=eq.'+target.id+'&select=id')).length,1);
 assert.equal((await a('admin-delete.php?id='+target.id,{csrf:token,expected_version:version(page.body)})).status,422);
 assert.equal((await a('admin-delete.php?id='+target.id,{csrf:'bad',expected_version:version(page.body),confirm_delete:'1'})).status,403);
 assert.equal((await a('admin-delete.php?id='+target.id,{csrf:token,expected_version:'1',confirm_delete:'1'})).status,409);
 assert.equal((await a('admin-delete.php?id='+target.id,{csrf:token,expected_version:version(page.body),confirm_delete:'1'})).status,303);
 assert.equal((await api('GET','users?id=eq.'+target.id+'&select=id')).length,0);assert.equal((await targetNew('profile.php')).headers.get('location'),'login.php?expired=1');assert.equal((await a('admin-user.php?id='+target.id)).status,404);
 page=await a('admin-user.php?id='+ids.a.toUpperCase());assert.ok(!page.body.includes('name="password"'));const self={csrf:csrf(page.body),expected_version:version(page.body),email:emails.a,display_name:'A',is_active:'1',is_admin:'1',password:'',confirm_password:'',actor_id:ids.b,p_actor_id:ids.b,p_actor_version:'1'};
 const demote={...self};delete demote.is_admin;assert.equal((await a('admin-user.php?id='+ids.a,demote)).status,409);const disable={...self};delete disable.is_active;assert.equal((await a('admin-user.php?id='+ids.a,disable)).status,409);
 assert.equal((await a('admin-user.php?id='+ids.a,{...self,password:resetPassword,confirm_password:resetPassword})).status,409);
 assert.equal((await a('admin-delete.php?id='+ids.a,{csrf:self.csrf,expected_version:'1',confirm_delete:'1',actor_id:ids.b,p_actor_id:ids.b})).status,409);
 // Pagination must not silently hide later users; fixtures remain normal users.
 const pageRows=Array.from({length:26},(_,i)=>{const email=`${prefix}-page-${String(i).padStart(2,'0')}@example.invalid`;allEmails.add(email);return{email,password_hash:passwordHash,display_name:'Page '+i};});await api('POST','users?select=id',pageRows);
 page=await a('admin.php?q='+prefix+'-page-');assert.equal(page.status,200);assert.ok(page.body.includes('26 Treffer')&&page.body.includes('Nächste Seite'));
 page=await a('admin.php?q='+prefix+'-page-&page=2');assert.equal(page.status,200);assert.ok(page.body.includes('Seite 2 von 2'));
 // Concurrent reciprocal demotion: exactly one mutation wins, leaving one admin.
 const mutation=(actor,target,email)=>({p_actor_id:actor,p_actor_version:1,p_target_id:target,p_expected_version:1,p_email:email,p_display_name:'Demoted',p_is_active:true,p_is_admin:false,p_password_hash:null});
 const raced=await Promise.all([api('POST','rpc/admin_update_user',mutation(ids.a,ids.b,emails.b)),api('POST','rpc/admin_update_user',mutation(ids.b,ids.a,emails.a))]);assert.deepEqual(raced.map(value=>value.ok).sort(),[false,true]);
 const state=await api('GET',`users?id=in.(${ids.a},${ids.b})&select=id,is_admin,auth_version`);assert.equal(state.filter(value=>value.is_admin).length,1);
 const winner=state.find(value=>value.is_admin);const winnerClient=winner.id===ids.a?a:b;const loserClient=winner.id===ids.a?b:a;
 assert.equal((await winnerClient('admin.php')).status,200);assert.equal((await loserClient('admin.php')).headers.get('location'),'login.php?expired=1');
 page=await winnerClient('admin-user.php?id='+winner.id);assert.equal((await winnerClient('admin-user.php?id='+winner.id,{csrf:csrf(page.body),expected_version:version(page.body),email:winner.id===ids.a?emails.a:emails.b,display_name:'Own edit',is_active:'1',is_admin:'1',password:'',confirm_password:''})).headers.get('location'),'login.php?account_updated=1');
 console.log('PASS admin CRUD: anonymous/normal-user denial, no signup escalation, list/search/pagination, create/edit/reset/disable/promote/delete, no hash output, CSRF/stale-edit guards, session revocation, self-protection and concurrent role-revocation safety.');
}finally{
 if(server&&server.exitCode===null){server.kill();await once(server,'exit');}
 for(const email of allEmails){await api('DELETE','users?email=eq.'+encodeURIComponent(email));for(const identity of ['email:'+email,'email:registration:'+email])await api('DELETE','login_attempts?bucket=eq.'+digest(identity));}
 if(temp){assert.equal(dirname(temp),resolve(tmpdir()));assert.match(basename(temp),/^supabase-admin-test-/);await rm(temp,{recursive:true,force:true});await api('DELETE','login_attempts?bucket=eq.'+digest('ip:127.0.0.1'));}
}
