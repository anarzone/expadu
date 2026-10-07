const {test}=require('node:test');
const assert=require('node:assert/strict');
const {readFileSync}=require('node:fs');
const {runInNewContext}=require('node:vm');
const sandbox={window:{}};
runInNewContext(readFileSync(`${__dirname}/intent-model.js`,'utf8'),sandbox);
const {interpret,createState}=sandbox.window.ExpaduConcept;
test('needs in natural language reach the matching example without requiring today',()=>{
 assert.equal(interpret('I want somewhere quiet').kind,'quiet');
 assert.equal(interpret('I want to play football').kind,'football');
 assert.equal(interpret('I want to hang out').kind,'social');
});
test('unsupported sport, hard limits and exclusions never silently become broad matches',()=>{
 assert.equal(interpret('I want volleyball').reason,'volleyball');
 assert.equal(interpret('somewhere quiet within 1 km').reason,'constraint');
 assert.equal(interpret('football for 30 minutes').reason,'constraint');
 assert.equal(interpret('quiet, no parks').reason,'exclusion');
});
test('paperwork and directions retain their distinct purpose',()=>{
 assert.equal(interpret('help with my registration').kind,'paperwork');
 assert.equal(interpret('take me to Museum Ludwig').kind,'travel');
});
test('unspecified requests ask for direction instead of fabricating intent',()=>{
 assert.equal(interpret('I feel a bit moody').kind,'clarify');
 assert.equal(interpret('   ').kind,'empty');
});
test('save keeps exactly the visible choices and removed ideas stay removed',()=>{
 const state=createState();state.submit('quiet please');state.toggle('green');state.toggle('social');state.toggle('green');
 assert.deepEqual([...state.save()],['social']);state.editSaved();assert.deepEqual([...state.snapshot().chosen],['social']);
});
test('a new request preserves draft choices, saved day and original words',()=>{
 const state=createState();state.submit('a quiet place');state.toggle('green');state.save();
 state.submit('I want to play volleyball');assert.deepEqual([...state.snapshot().saved],['green']);
 assert.deepEqual([...state.snapshot().chosen],['green']);assert.equal(state.snapshot().request.original,'I want to play volleyball');
});
test('editing a saved day changes the draft until an explicit save',()=>{
 const state=createState();state.toggle('green');state.save();state.editSaved();state.toggle('social');
 assert.deepEqual([...state.snapshot().saved],['green']);assert.deepEqual([...state.save()],['green','social']);
});
