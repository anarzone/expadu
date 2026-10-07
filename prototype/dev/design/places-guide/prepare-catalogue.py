"""Build a sourced, frozen field-guide catalogue. No network/database writes."""
import collections
import hashlib
import json
import re
from pathlib import Path

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[3]
SOURCE = ROOT / 'docs/places/cologne-expansion/2026-09-18/place-refinement/catalogue-refined.jsonl'
reviewed = json.loads((HERE.parent / 'places-optional/sample.json').read_text())['places']
photos = {p['sourceId']: p for p in reviewed if p['photo']}
recorded_areas = {p['sourceId']: p['area'] for p in reviewed if p['area'] != 'Area not recorded'}
labels = {
    'cafe': 'Café', 'restaurant': 'Restaurant', 'fast_food': 'Quick food', 'pub': 'Pub',
    'bar': 'Bar', 'biergarten': 'Beer garden', 'ice_cream': 'Ice cream', 'bakery': 'Bakery',
    'park': 'Park', 'garden': 'Garden', 'nature_reserve': 'Nature reserve', 'dog_park': 'Dog park',
    'museum': 'Museum', 'gallery': 'Gallery', 'library': 'Library', 'books': 'Bookshop',
    'arts_centre': 'Arts centre', 'theatre': 'Theatre', 'cinema': 'Cinema',
    'attraction': 'Sight', 'viewpoint': 'Viewpoint', 'artwork': 'Public art',
    'playground': 'Playground', 'sports_centre': 'Sports centre', 'fitness_centre': 'Fitness centre',
    'pitch': 'Sports pitch', 'sports_hall': 'Sports hall', 'stadium': 'Stadium',
    'swimming_pool': 'Swimming pool', 'swimming_area': 'Swimming area', 'water_park': 'Water park',
    'climbing': 'Climbing', 'miniature_golf': 'Mini golf', 'ice_rink': 'Ice rink',
    'bowling_alley': 'Bowling', 'beach_resort': 'Lakeside swimming', 'zoo': 'Zoo',
}
food = {'cafe','restaurant','fast_food','pub','bar','biergarten','ice_cream','bakery'}
outside = {'park','garden','nature_reserve','dog_park','viewpoint'}
culture = {'museum','gallery','library','books','arts_centre','theatre','cinema','attraction','artwork'}
sport = set(labels) - food - outside - culture
cuisine_labels = {'coffee_shop':'Coffee','cake':'Cake','sandwich':'Sandwiches','italian':'Italian','regional':'Regional food','german':'German','pizza':'Pizza','burger':'Burgers','kebab':'Kebab','asian':'Asian','japanese':'Japanese','vietnamese':'Vietnamese','chinese':'Chinese','indian':'Indian','thai':'Thai','greek':'Greek','turkish':'Turkish','ice_cream':'Ice cream','international':'International','sushi':'Sushi','spanish':'Spanish','mexican':'Mexican','korean':'Korean','lebanese':'Lebanese'}
exclusions = collections.Counter()
places = []
for line in SOURCE.read_text().splitlines():
    group = json.loads(line)
    sources = [s for s in group['sources'] if s.get('source') == 'openstreetmap']
    main = next((s for s in sources if s['id'] == group['id']), None)
    if not main:
        exclusions['no_primary_osm_record'] += 1
        continue
    tags = main.get('tags', {})
    kind = next((tags[k] for k in ['amenity','leisure','tourism','shop'] if tags.get(k) in labels), None)
    if kind is None:
        exclusions['outside_discovery_categories'] += 1
        continue
    if tags.get('access') in ['private','no'] or any(k in tags for k in ['disused:amenity','abandoned:amenity','demolished:amenity']):
        exclusions['restricted_or_inactive_source'] += 1
        continue
    if not (50.7 < group['latitude'] < 51.2 and 6.6 < group['longitude'] < 7.3):
        exclusions['invalid_city_point'] += 1
        continue
    title = group['name'].strip()
    if not title or len(title) > 180:
        exclusions['unusable_name'] += 1
        continue
    category = 'food' if kind in food else 'outside' if kind in outside else 'culture' if kind in culture else 'play'
    address = ' '.join(filter(None, [tags.get('addr:street'), tags.get('addr:housenumber')])) or None
    area = recorded_areas.get(group['id']) or tags.get('addr:suburb') or None
    postcode = tags.get('addr:postcode')
    if postcode and not re.fullmatch(r'\d{5}', postcode): postcode = None
    facts = []
    def fact(key, label):
        if key in tags: facts.append({'key':key,'value':tags[key],'label':label,'sourceId':main['id']})
    if tags.get('outdoor_seating') == 'yes': fact('outdoor_seating','Outdoor seating')
    if tags.get('diet:vegan') in ['yes','only']: fact('diet:vegan','Vegan options' if tags['diet:vegan']=='yes' else 'Vegan menu')
    if tags.get('diet:vegetarian') in ['yes','only']: fact('diet:vegetarian','Vegetarian options' if tags['diet:vegetarian']=='yes' else 'Vegetarian menu')
    if tags.get('wheelchair') in ['yes','limited','no']:
        fact('wheelchair', {'yes':'Wheelchair access listed','limited':'Limited wheelchair access','no':'No wheelchair access listed'}[tags['wheelchair']])
    if tags.get('fee') in ['yes','no']: fact('fee','Admission fee listed' if tags['fee']=='yes' else 'No admission fee listed')
    if tags.get('sport'): fact('sport',', '.join(s.replace('_',' ') for s in tags['sport'].split(';')).capitalize())
    cuisines = [cuisine_labels.get(x, x.replace('_',' ').capitalize()) for x in tags.get('cuisine','').split(';') if x]
    if cuisines: fact('cuisine',', '.join(cuisines[:3]))
    if tags.get('changing_table') == 'yes': fact('changing_table','Changing table listed')
    if tags.get('drinking_water') == 'yes': fact('drinking_water','Drinking water listed')
    website = tags.get('website') or tags.get('contact:website')
    if website and not website.startswith(('https://','http://')): website = None
    description = tags.get('description:en') or tags.get('description')
    if description: description = re.sub('<[^>]*>', '', description).strip()[:700]
    summary = labels[kind] + (f' on {tags["addr:street"]}' if tags.get('addr:street') else f' in {area}' if area else ' in Cologne') + '.'
    if cuisines and kind in food: summary += ' '+', '.join(cuisines[:3])+'.'
    if tags.get('outdoor_seating') == 'yes': summary += ' Outdoor seating is listed.'
    photo = photos.get(group['id'], {}).get('photo')
    if photo:
        photo = dict(photo)
        photo['file'] = '../places-optional/' + photo['file'].removeprefix('./')
        assert hashlib.sha256((HERE/photo['file']).read_bytes()).hexdigest() == photo['sha256']
    source_url = main['source_url']
    places.append({
        'id':'sample-'+group['id'].replace(':','-').replace('/','-'),
        'sourceId':group['id'],'type':'place','title':title,'category':category,'kind':kind,
        'label':labels[kind],'icon':{'food':'cup','outside':'leaf','culture':'museum','play':'ball'}[category],
        'address':address,'area':area,'postcode':postcode,
        'lat':group['latitude'],'lng':group['longitude'],
        'summary':summary,'description':description,'descriptionLanguage':'en' if tags.get('description:en') else 'source',
        'facts':[f['label'] for f in facts],'evidence':facts,
        'rawHours':tags.get('opening_hours'),'hoursCheckedAt':tags.get('check_date:opening_hours'),
        'access':tags.get('access'),'website':website,'sourceUrl':source_url,
        'captured':'18 September 2026','identityStatus':group['identity_status'],
        'location':address or (area+', Cologne' if area else 'Cologne'),
        'photo':photo,'image':'/dev/design/places-optional/'+photo['file'].split('places-optional/')[1] if photo else None,
        'source':photo['source'] if photo else source_url,'credit':photo['attribution'] if photo else None,
        'aliases':group.get('aliases',[]),'memberSourceIds':[s['id'] for s in sources],
    })
places.sort(key=lambda p:(p['title'].casefold(),p['id']))
manifest = {
    'snapshot':'2026-09-18','preparedFor':'Places field-guide design','city':'Cologne',
    'source':str(SOURCE.relative_to(ROOT)), 'sourceSha256':hashlib.sha256(SOURCE.read_bytes()).hexdigest(),
    'records':len(places),'categories':dict(collections.Counter(p['category'] for p in places)),
    'withDescriptions':sum(bool(p['description']) for p in places),'withRecordedHours':sum(bool(p['rawHours']) for p in places),
    'withRecordedAmenities':sum(bool(p['facts']) for p in places),'withPostcodes':sum(bool(p['postcode']) for p in places),
    'withReviewedPhotos':sum(bool(p['photo']) for p in places),'exclusions':dict(exclusions),
    'publication':'Frozen source snapshot for design; provisional identities, not an imported/published city census.',
    'factsLicense':'ODbL-1.0','factsAttribution':'© OpenStreetMap contributors',
}
(HERE/'catalogue.json').write_text(json.dumps({'meta':manifest,'places':places},ensure_ascii=False,separators=(',',':'))+'\n')
(HERE/'catalogue-manifest.json').write_text(json.dumps(manifest,ensure_ascii=False,indent=2)+'\n')
print(json.dumps(manifest,ensure_ascii=False,indent=2))
