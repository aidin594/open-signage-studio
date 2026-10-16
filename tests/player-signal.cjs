const fs = require('fs');
const vm = require('vm');
const assert = require('assert').strict;
const js = fs.readFileSync(require('path').join(__dirname, '../assets/player.js'), 'utf8');
const start = js.indexOf('// Fast path: a tiny static revision marker');
const end = js.indexOf('if(document.fonts &&',start);
assert(start > 0 && end > start, 'Cannot find optimized polling implementation');
const segment = js.slice(start,end);
async function run() {
  let revisions = 1, signalBroken = false, restCount=0, markerCount=0, renderCount=0;
  const timeouts = [];
  const data = {rev:0,slides:[],idx:0,message:null,paused:false,settings:{poll:30,duration:8}};
  const config = {api:'/rest/',signalUrl:'/uploads/revision.json',preview:false};
  const sandbox = {config,data,document:{querySelector:()=>null},app:{append(){}},el:()=>({}),
    render:()=>{renderCount++},setTimeout:(fn,delay)=>{timeouts.push({fn,delay});return timeouts.length;},
    clearTimeout:()=>{},Date,console,
    fetch:async url=>{
      if(url.includes('revision.json')){
        markerCount++;
        return {ok:!signalBroken,status:signalBroken?403:200,json:async()=>({revision:revisions})};
      }
      if(url.includes('/rest/screen/state?')){
        restCount++;
        const since=Number(new URL(url,'http://localhost').searchParams.get('since'));
        const changed = revisions !== since;
        return {ok:true,json:async()=>changed?{changed:true,revision:revisions,
          slides:[{id:1,kind:'text',text:'Test'}],message:null,paused:false,
          settings:{poll:30},server_time:Date.now()/1000}:{changed:false,revision:revisions}};
      }
      throw new Error('Unexpected fetch: '+url);
    }
  };
  vm.createContext(sandbox);
  vm.runInContext(segment + ';globalThis.testPoll=poll;',sandbox);
  await sandbox.testPoll();
  assert.equal(restCount,1,'First screen load must use REST');
  assert.equal(timeouts[timeouts.length-1].delay,3000,'Static poll begins after 3sec');
  await sandbox.testPoll();
  assert.equal(markerCount,1);
  assert.equal(restCount,1,'Unchanged signal must not call WordPress');
  revisions=2;
  await sandbox.testPoll();
  assert.equal(restCount,2,'Changed signal fetches fresh REST state');
  assert.equal(renderCount,2,'Each changed state rendered');
  signalBroken=true;
  await sandbox.testPoll();
  assert.equal(restCount,3,'Broken signal must fall back to REST');
  assert.equal(timeouts[timeouts.length-1].delay,30000,'Fallback respects configured 30sec interval');
  console.log('PASS: static signal changes, low REST load, and automatic REST fallback');
}
run().catch(e=>{console.error(e);process.exit(1)});
