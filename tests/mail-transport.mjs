// Tests the real PHPMailer adapter against a local TLS SMTP sink. No external mail.
// node tests/mail-transport.mjs <php> [PHP options, including OpenSSL]
import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {once} from 'node:events';
import {mkdtemp,mkdir,cp,writeFile,readFile,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join,resolve,dirname,basename} from 'node:path';
import {createServer} from 'node:tls';
import {randomBytes} from 'node:crypto';
const root=resolve(import.meta.dirname,'..'),php=process.argv[2]||'php',options=process.argv.slice(3);
const temp=await mkdtemp(join(tmpdir(),'supabase-smtp-test-'));
let smtp;
async function run(source,extra=[]){const child=spawn(php,[...options,...extra,'-r',source],{cwd:temp,windowsHide:true,stdio:['ignore','pipe','pipe']});let stdout='';child.stdout.on('data',chunk=>{stdout+=chunk;});child.stderr.resume();const[code]=await once(child,'exit');return{code,stdout};}
try{
 await mkdir(join(temp,'app'));await mkdir(join(temp,'config'));await cp(join(root,'app/mail.php'),join(temp,'app/mail.php'));await cp(join(root,'app/vendor'),join(temp,'app/vendor'),{recursive:true});
 await writeFile(join(temp,'certificate.ini'),'[req]\ndistinguished_name=dn\nx509_extensions=v3\nprompt=no\n[dn]\nCN=localhost\n[v3]\nsubjectAltName=DNS:localhost,IP:127.0.0.1\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\nextendedKeyUsage=serverAuth\n');
 const generated=await run('$o=["config"=>"certificate.ini","digest_alg"=>"sha256","private_key_bits"=>2048,"x509_extensions"=>"v3"]; $k=openssl_pkey_new($o); $csr=openssl_csr_new(["commonName"=>"localhost"],$k,$o); $c=openssl_csr_sign($csr,null,$k,1,$o); openssl_x509_export($c,$cert); openssl_pkey_export($k,$key,null,$o); file_put_contents("server.crt",$cert); file_put_contents("server.key",$key);');assert.equal(generated.code,0,'Test certificate generation failed.');
 const messages=[];
 smtp=createServer({cert:await readFile(join(temp,'server.crt')),key:await readFile(join(temp,'server.key'))},socket=>{
  let buffer='',stage=0,inData=false,lines=[];socket.setTimeout(10000,()=>socket.destroy());socket.write('220 localhost SMTP\r\n');
  socket.on('data',chunk=>{buffer+=chunk.toString();let end;while((end=buffer.indexOf('\r\n'))>=0){const line=buffer.slice(0,end);buffer=buffer.slice(end+2);
   if(inData){if(line==='.'){messages.push(lines.join('\r\n'));lines=[];inData=false;socket.write('250 stored locally\r\n');}else lines.push(line);continue;}
   if(stage===1){stage=2;socket.write('334 UGFzc3dvcmQ6\r\n');continue;}if(stage===2){stage=0;socket.write('235 authenticated\r\n');continue;}
   if(line.startsWith('EHLO'))socket.write('250-localhost\r\n250-AUTH LOGIN\r\n250 SIZE 100000\r\n');
   else if(line==='AUTH LOGIN'){stage=1;socket.write('334 VXNlcm5hbWU6\r\n');}
   else if(line.startsWith('MAIL FROM:')||line.startsWith('RCPT TO:')||line==='RSET')socket.write('250 OK\r\n');
   else if(line==='DATA'){inData=true;socket.write('354 send message\r\n');}
   else if(line==='QUIT'){socket.end('221 bye\r\n');}else socket.write('500 unsupported\r\n');
  }});socket.on('error',()=>{});
 });smtp.on('tlsClientError',()=>{});smtp.listen(0,'127.0.0.1');await once(smtp,'listening');const port=smtp.address().port;
 await writeFile(join(temp,'config/mail.local.php'),`<?php return ['host'=>'127.0.0.1','port'=>${port},'encryption'=>'smtps','username'=>'test-user','password'=>'test-secret','from_address'=>'sender@example.invalid','from_name'=>'Test','base_url'=>'https://example.invalid/app'];`);
 const token=randomBytes(32).toString('hex');
 const source=`require "app/mail.php"; try { sendRegistrationMail("recipient@example.invalid", "${token}"); echo "sent"; } catch (RuntimeException) { echo "failed"; exit(1); }`;
 const result=await run(source,['-d','openssl.cafile='+join(temp,'server.crt')]);assert.equal(result.code,0,'Trusted TLS SMTP delivery failed.');assert.equal(result.stdout,'sent');assert.equal(messages.length,1);
 const message=messages[0].replace(/=\r\n/g,'').replace(/=([0-9A-F]{2})/g,(_match,hex)=>String.fromCharCode(parseInt(hex,16)));
 assert.ok(message.includes('/confirm.php#token='+token),'Confirmation link missing from captured mail.');assert.ok(!message.includes('test-secret'),'SMTP password leaked into mail.');
 const rejected=await run(source,['-d','openssl.cafile='+join(temp,'missing-ca.crt')]);assert.equal(rejected.code,1,'Untrusted TLS must fail.');assert.equal(rejected.stdout,'failed');assert.equal(messages.length,1,'Untrusted connection delivered mail.');
 console.log('PASS real mail adapter: local authenticated TLS SMTP delivery, fragment confirmation link, no SMTP credential in message and rejection of untrusted certificates. No external mail sent.');
}finally{
 if(smtp){await new Promise(resolve=>smtp.close(resolve));}
 assert.equal(dirname(temp),resolve(tmpdir()));assert.match(basename(temp),/^supabase-smtp-test-/);await rm(temp,{recursive:true,force:true});
}
