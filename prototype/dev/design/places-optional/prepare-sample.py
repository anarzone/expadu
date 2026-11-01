"""Prepare a public, frozen design sample. No database or network writes."""
import hashlib,json,shutil
from pathlib import Path
HERE=Path(__file__).resolve().parent
ROOT=HERE.parents[3]
DATA=ROOT/'docs/places/cologne-expansion'
source={r['id']:r for r in map(json.loads,(DATA/'2026-09-18/places-named.jsonl').read_text().splitlines())}
photos=json.loads((DATA/'2026-09-24/photo-delivery/reviewed-batch.json').read_text())
photo_by_source={s:p for p in photos for s in p['source_ids'] if s.startswith('osm:')}
payloads={r['payload']['id']:r['payload'] for r in json.loads((DATA/'2026-09-24/photo-delivery/staging-evidence/api-verification-postdeploy.json').read_text())['places']}
selection=[
 'osm:node/104272811','osm:node/4639554291','osm:node/244894955',
 'osm:way/4532738','osm:node/774756903','osm:node/633480736',
 'osm:node/3426389407','osm:way/44443729','osm:node/458790816',
 'osm:node/160695366','osm:node/256220994','osm:way/13969251',
 'osm:way/24234398',next(s for p in photos if p['spot']['name']=='Neptunbad' for s in p['source_ids'] if s.startswith('osm:')),
 next(s for p in photos if p['spot']['name']=='Volksgarten' for s in p['source_ids'] if s.startswith('osm:')),
 'osm:node/5739097722']
rows=[]
for identity in selection:
 r=source[identity];s=next(s for s in r['sources'] if s['id']==identity);t=s.get('tags',{});p=photo_by_source.get(identity);api=payloads.get(p['spot']['id'],{}) if p else {}
 kind=t.get('amenity',t.get('leisure',t.get('tourism','place')))
 group='Cafés' if kind=='cafe' else 'Restaurants' if kind in ['restaurant','fast_food','pub','bar'] else 'Parks' if kind=='park' else 'Playgrounds' if kind=='playground' else 'Culture' if kind in ['library','museum','arts_centre'] else 'Activities'
 label={'cafe':'Café','restaurant':'Restaurant','library':'Library','park':'Park','playground':'Playground','museum':'Museum','sports_centre':'Leisure centre','fitness_centre':'Fitness centre'}.get(kind,kind.replace('_',' ').capitalize())
 facts=[]
 if t.get('outdoor_seating')=='yes':facts.append({'label':'Outdoor seating','key':'outdoor_seating','value':'yes'})
 if t.get('cuisine'):
  value=t['cuisine'];facts.append({'label':{'coffee_shop;cake;sandwich':'Coffee, cake & sandwiches','coffee_shop':'Coffee shop','italian':'Italian food'}.get(value,value.replace(';',', ').replace('_',' ').capitalize()),'key':'cuisine','value':value})
 if t.get('wheelchair') in ['yes','limited','no']:facts.append({'label':{'yes':'Wheelchair access listed','limited':'Limited wheelchair access','no':'No wheelchair access listed'}[t['wheelchair']],'key':'wheelchair','value':t['wheelchair']})
 if t.get('fee')=='yes':facts.append({'label':'Admission fee listed','key':'fee','value':'yes'})
 photo=None
 if p:
  file=DATA/f"2026-09-24/photo-delivery/images/{p['number']:02d}.jpg"
  assert hashlib.sha256(file.read_bytes()).hexdigest()==p['checksum']
  target=HERE/f"assets/{p['spot']['id']}.jpg";shutil.copy2(file,target)
  photo={'file':f'./assets/{target.name}','attribution':p['attribution'],'author':p['author'],'source':p['source_page_url'],'license':p['license_code'],'licenseUrl':p['license_url'],'sha256':p['checksum'],'reviewedAt':'2026-09-24','review':p['visual_review']['note']}
 address=' '.join(filter(None,[t.get('addr:street'),t.get('addr:housenumber')]))
 website=t.get('website') or t.get('contact:website')
 if website and not website.startswith(('https://','http://')):website=None
 rows.append({'id':'sample-'+identity.replace(':','-').replace('/','-'),'sourceId':identity,'type':'place','title':r['name'],'category':group,'label':label,'area':api.get('veedel') or t.get('addr:suburb') or 'Area not recorded','address':address or None,'lat':r['latitude'],'lng':r['longitude'],'icon':{'Parks':'leaf','Cafés':'cup','Restaurants':'cutlery','Culture':'museum','Playgrounds':'smile','Activities':'waves'}[group],'facts':[f['label'] for f in facts],'sourceFacts':facts,'rawHours':t.get('opening_hours'),'website':website,'access':t.get('access'),'captured':'18 September 2026','sourceUrl':s['source_url'],'locationKind':'Source map point; entrance not verified','photo':photo,'image':('/dev/design/places-optional/assets/'+str(p['spot']['id'])+'.jpg') if p else None,'source':p['source_page_url'] if p else s['source_url'],'credit':p['attribution'] if p else None,'unknown':'Current hours, entry conditions and travel still need checking.'})
for row in rows:
 row['location']=row['address'] or (row['area']+', Cologne' if row['area']!='Area not recorded' else 'Cologne · entrance not verified')
payload={'city':'Cologne','date':'2026-09-18','photoReviewDate':'2026-09-24','sample':True,'places':rows}
(HERE/'sample.json').write_text(json.dumps(payload,ensure_ascii=False,indent=2)+'\n')
(HERE/'sample.js').write_text('/* Frozen public sample, not a live catalogue. See sample.json and README.md. */\nwindow.PlacesSample = '+json.dumps(payload,ensure_ascii=False,indent=2)+';\nExpaduModel.places.push(...PlacesSample.places);\n')
for name in ['maplibre-gl.js','maplibre-gl.css']:
 shutil.copy2(ROOT/'node_modules/maplibre-gl/dist'/name,HERE/'vendor'/name)
shutil.copy2(ROOT/'node_modules/maplibre-gl/LICENSE.txt',HERE/'vendor/LICENSE.txt')
print({'sample_places':len(rows),'with_reviewed_photos':sum(bool(r['photo']) for r in rows),'categories':sorted({r['category'] for r in rows})})
