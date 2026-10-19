import unittest

from prepare import comparable, http_url, norm, osm_category, potential_duplicate, tag_holds


def record(key, name, lat=50.94, lng=6.95, **extra):
    return comparable({"key": key, "source_name": name, "source": "osm", "source_id": key, "category": "cafe", "lat": lat, "lng": lng, **extra})


class ReleaseGuards(unittest.TestCase):
    def test_source_identity_is_not_its_own_duplicate(self):
        a = record("node/1", "Café One")
        b = {**a, "key": "existing:2"}
        self.assertIsNone(potential_duplicate(a, b))

    def test_nearby_accent_and_category_prefix_variants_are_held(self):
        self.assertEqual(potential_duplicate(record("node/1", "Café Grün"), record("way/2", "Grun", lat=50.94005)), "similar_name_nearby")

    def test_distinct_branches_are_not_merged_by_name(self):
        self.assertIsNone(potential_duplicate(record("node/1", "Starbucks"), record("node/2", "Starbucks", lat=50.96)))

    def test_different_venues_sharing_a_building_are_not_merged(self):
        self.assertIsNone(potential_duplicate(record("node/1", "Museum", category="museum"), record("node/2", "Restaurant", category="restaurant")))

    def test_positive_charge_prevents_free_claim(self):
        self.assertIn("conflicting_fee_and_charge", tag_holds({"fee": "no", "charge": "10 EUR"}))

    def test_conditional_free_claim_is_held(self):
        self.assertIn("conditional_fee_not_supported_by_deployed_resolver", tag_holds({"fee": "no", "fee:conditional": "yes @ (Sa-Su)"}))

    def test_restricted_and_closed_sources_are_held(self):
        self.assertIn("restricted_or_conditional_access", tag_holds({"access": " private "}))
        self.assertIn("lifecycle_requires_review", tag_holds({"disused:amenity": "cafe"}))

    def test_end_dates_require_lifecycle_review(self):
        self.assertIn("dated_lifecycle_requires_review", tag_holds({"end_date": "2019-09-28"}))
        self.assertIn("dated_lifecycle_requires_review", tag_holds({"closing_date": "2026"}))

    def test_distinctive_name_overlap_at_same_address_is_held(self):
        for first, second, street in (("Ristorante Arena", "Arena Pizzeria", "Aachener Straße 487"), ("Vetrina", "Vetrina Toscana", "Lindenstraße 5"), ("Restaurant Und Bistro Kaukasia", "Kaukasia", "Clever Straße 2")):
            with self.subTest(first=first):
                a = record("node/1", first, category="restaurant", address=street + ", Köln")
                b = record("node/2", second, category="restaurant", address=street + ", 50668", lat=50.94005)
                self.assertEqual(potential_duplicate(a, b), "same_address_distinctive_name_overlap")

    def test_shared_generic_words_at_one_address_do_not_merge_venues(self):
        a = record("node/1", "Bistro Grün", address="Example street 2")
        b = record("node/2", "Bistro Rot", address="Example street 2", lat=50.94005)
        self.assertIsNone(potential_duplicate(a, b))

    def test_required_booking_is_not_spontaneously_playable(self):
        self.assertIn("booking_or_membership_requires_supported_constraints", tag_holds({"reservation": "required"}))
        self.assertIn("booking_or_membership_requires_supported_constraints", tag_holds({"reservation": "members_only"}))
        self.assertEqual(tag_holds({"reservation": "recommended"}), [])

    def test_generic_sport_is_not_misclassified_as_football(self):
        self.assertIsNone(osm_category({"leisure": "pitch", "sport": "hockey"}))
        self.assertEqual(osm_category({"leisure": "pitch", "sport": "soccer;basketball"}), "pitch")

    def test_invalid_or_credential_urls_are_not_contact_links(self):
        self.assertIsNone(http_url("javascript:alert(1)"))
        self.assertIsNone(http_url("https://name:secret@example.com/"))
        self.assertEqual(http_url("https://example.com/path"), "https://example.com/path")

    def test_international_domain_and_path_are_encoded_without_losing_raw_source(self):
        self.assertEqual(http_url("https://café.de/straße"), "https://xn--caf-dma.de/stra%C3%9Fe")

    def test_unnamed_facility_with_legacy_null_source_does_not_crash(self):
        a = record("node/1", "", category="pitch")
        b = record(None, "Sports pitch", category="pitch", lat=50.94005)
        self.assertEqual(potential_duplicate(a, b), "nearby_facility_node_and_area")


if __name__ == "__main__":
    unittest.main()
