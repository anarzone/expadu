const {test}=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const fs=require('node:fs');
const path=require('node:path');
const app=fs.readFileSync(path.join(__dirname,'../app.js'),'utf8');
const overview=fs.readFileSync(path.join(__dirname,'../overview.js'),'utf8');
function create(){
 const pending=[],calls=[];
 const submit={disabled:false},conversation={innerHTML:'',setAttribute(){},querySelector(){return null}},input={value:'',focus(){calls.push('focus')},blur(){},dispatchEvent(){}};
 const ctx=vm.createContext({state:{request:'',draft:[],saved:[],hasDraft:false,version:0},input,conversation,workspace:{hidden:false},sheet:{open:false},dayPreview:{visitor:'current',checks:[true,true,false,false],started:true},visitorSessions:new Map(),undoAction:null,Event:class{},setTimeout:fn=>pending.push(fn),document:{querySelector:s=>s==='.compose-submit'?submit:s==='#request'?input:{scrollTop:0,classList:{remove(){}}}},openComposer(){calls.push('open')},echo(){return input.value},question(...args){calls.push(args)},renderResults(){calls.push('results')},renderPlan(){calls.push('plan')},button:s=>s,renderDayOverview(){},updateCount(){},focusResponse(){}});
 vm.runInContext(app.slice(app.indexOf('function cancelPendingCompose()'),app.indexOf('function updateCount()')),ctx);
 vm.runInContext(overview.slice(overview.indexOf('function snapshotVisitor()'),overview.indexOf('function startGlance()')),ctx);
 return {ctx,calls,submit,flush:()=>pending.splice(0).forEach(fn=>fn())};
}
test('empty request stays on Today',async()=>{const {ctx,calls}=create();await ctx.compose('   ');assert.deepEqual(calls,['focus']);});
for(const request of ['somewhere quiet','an indoor idea','plan these together'])test(`one loading lifecycle: ${request}`,async()=>{const {ctx,calls,submit,flush}=create();ctx.state.draft=['walk'];const promise=ctx.compose(request);assert.equal(ctx.state.busy,true);assert.equal(submit.disabled,true);assert.deepEqual(calls,['open']);flush();await promise;assert.equal(ctx.state.busy,false);assert.equal(submit.disabled,false);assert.equal(ctx.state.request,request);assert.equal(calls.length,2);});
test('cancelled request cannot deliver late results',async()=>{const {ctx,calls,flush}=create();const pending=ctx.compose('quiet');ctx.cancelPendingCompose();flush();await pending;assert.deepEqual(calls,['open']);assert.equal(ctx.state.request,'quiet');});
test('newer request wins over an unfinished one',async()=>{const {ctx,calls,flush}=create();const first=ctx.compose('quiet');const second=ctx.compose('meet people');flush();await Promise.all([first,second]);assert.equal(ctx.state.kind,'social');assert.equal(calls.filter(v=>v==='results').length,1);});
test('constraints are not replaced with a kept plan',async()=>{const {ctx,calls,flush}=create();ctx.state.draft=['walk'];const p=ctx.compose('plan these without a cafe within 30 minutes');flush();await p;assert.ok(calls.some(v=>Array.isArray(v)&&v[0]==='Your requirements, kept.'));assert.ok(!calls.includes('plan'));});
test('first-visit scenario is isolated and switching back restores edits',()=>{const {ctx}=create();ctx.input.value='My request';ctx.state.draft=['cafe'];ctx.state.hasDraft=true;ctx.switchVisitor('first');assert.equal(ctx.state.draft.length,0);assert.equal(ctx.dayPreview.started,false);assert.equal(ctx.dayPreview.checks.some(Boolean),false);ctx.switchVisitor('current');assert.deepEqual(Array.from(ctx.state.draft),['cafe']);assert.equal(ctx.input.value,'My request');assert.equal(ctx.state.hasDraft,true);});
test('returning scenario uses distinct draft and saved arrays',()=>{const {ctx}=create();ctx.switchVisitor('returning');ctx.state.draft.push('cafe');assert.deepEqual(Array.from(ctx.state.saved),['walk']);ctx.switchVisitor('first');ctx.switchVisitor('returning');assert.deepEqual(Array.from(ctx.state.draft),['walk','cafe']);assert.deepEqual(Array.from(ctx.state.saved),['walk']);});

function layoutHarness(){
 const scrolls=[],styles={},listeners={};let resized;
 class Element{constructor(bottom,area='home'){this.bottom=bottom;this.area=area;}getBoundingClientRect(){return{bottom:this.bottom};}}
 const dock={height:72,contains:el=>el.area==='dock',getBoundingClientRect(){return{height:this.height,top:844-this.height};}};
 const ctx=vm.createContext({HTMLElement:Element,ResizeObserver:class{constructor(fn){resized=fn;}observe(){}},document:{querySelector:s=>s==='#dock-zone'?dock:{scrollBy:arg=>scrolls.push(['workspace',arg.top])},documentElement:{style:{setProperty:(k,v)=>styles[k]=v}},addEventListener:(type,fn)=>listeners[type]=fn},sheet:{contains:el=>el.area==='sheet'},workspace:{contains:el=>el.area==='workspace'},window:{scrollBy:arg=>scrolls.push(['home',arg.top])}});
 vm.runInContext(fs.readFileSync(path.join(__dirname,'../layout.js'),'utf8'),ctx);
 return {dock,styles,scrolls,resize:()=>resized(),focus:(bottom,area)=>listeners.focusin({target:new Element(bottom,area)})};
}
test('growing dock reserves enough page clearance',()=>{const h=layoutHarness();h.dock.height=124;h.resize();assert.ok(parseInt(h.styles['--dock-clearance'])>124);});
test('obscured page focus scrolls above Composer',()=>{const h=layoutHarness();h.focus(810);assert.equal(h.scrolls[0][0],'home');assert.ok(810-h.scrolls[0][1]<844-h.dock.height);});
test('Composer results scroll their own workspace',()=>{const h=layoutHarness();h.focus(810,'workspace');assert.equal(h.scrolls[0][0],'workspace');});
test('visible controls, dock and dialog do not move the page',()=>{const h=layoutHarness();h.focus(300);h.focus(810,'dock');h.focus(810,'sheet');assert.deepEqual(h.scrolls,[]);});
