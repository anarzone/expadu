# Panorama source check — 24 September 2026

This checks a previously excluded image class; it changes no staging media and makes no coverage claim.

The saved city camera inventory contains 55,458 panoramas, 53,734 captured since 18 September 2023. Applying a minimum provider quality score of 0.65 leaves 52,159 camera records. Matching reference points 5–50 metres from those cameras yields leads for 3,419 of the 12,857 provisional research groups, including 1,096 dining groups. These are proximity leads, not photographed or publishable places. No new citywide camera crawl was performed.

A deterministic 12-place diagnostic sample (eight dining, two shopping, two playgrounds; ascending SHA256 of place IDs within category) was fetched through the existing read-only application credential. All twelve actual 2,048-pixel source panoramas were visually inspected. Five show identifiable named storefronts: Kongs Bar, Hospital Bar, Zwitscherhäuschen, Pizza & Pasta Traditionale, and Manola. Seven lack a clear or established subject. Creator metadata and byte hashes are retained; no credentials are in the evidence.

This provides evidence that panorama imagery can supplement venue photography, unlike the preceding failed flat-image sample. It does not establish 75% coverage or a citywide success rate. The five identifiable images are equirectangular panoramas, unsuitable for direct placement into a normal photo card. They still need precise canonical place matching, final rights review and a venue-facing presentation with attribution and capture date. No panorama viewer or crop has been implemented or published.

The [official Mapillary licence guidance](https://help.mapillary.com/hc/en-us/articles/115001770409-CC-BY-SA-license-for-open-data) states CC BY-SA reuse with attribution. The [official API example](https://github.com/mapillary/api-demo/) demonstrates metadata retrieval and a MapillaryJS panorama viewer. Current MapillaryJS documentation was checked through Context7; that documentation lookup did not establish a provider endpoint for ready-made perspective thumbnails. No unsupported thumbnail conversion endpoint was assumed.
