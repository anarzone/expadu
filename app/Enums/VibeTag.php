<?php

namespace App\Enums;

/**
 * The closed set of vibe tags a place can carry for Composer's matching
 * ("somewhere quiet", "cosy", "with the kids", "rainy day"). Every tag on a
 * place has evidence: an OSM tag (decided by App\Places\Enrichment\VibeTagRules)
 * or a quote from the place's source text that contains one of the tag's cue
 * words (proposed by the model, checked in code). The rules for each tag are
 * listed in VibeTagRules and in docs; a tag nothing in the data can support
 * is not on this list (no "local favourite": there is no popularity data).
 */
enum VibeTag: string
{
    case Quiet = 'quiet';
    case Lively = 'lively';
    case Cosy = 'cosy';
    case FamilyFriendly = 'family_friendly';
    case KidPlay = 'kid_play';
    case OutdoorSeating = 'outdoor_seating';
    case Indoors = 'indoors';
    case GardenOrPark = 'garden_or_park';
    case Waterside = 'waterside';
    case ScenicView = 'scenic_view';
    case Historic = 'historic';
    case WorkFriendly = 'work_friendly';
    case Budget = 'budget';
    case Upscale = 'upscale';
    case VegetarianFriendly = 'vegetarian_friendly';
    case VeganOptions = 'vegan_options';
    case DogFriendly = 'dog_friendly';
    case LocalFood = 'local_food';
    case StepFree = 'step_free';
    case LateNight = 'late_night';

    /**
     * Hard needs and clock facts come from OSM in code only, never from the
     * model: step-free is `wheelchair=yes`, late night is the opening hours.
     */
    public function codeOnly(): bool
    {
        return $this === self::StepFree || $this === self::LateNight;
    }

    /** @return list<self> the tags the model may propose */
    public static function modelTags(): array
    {
        return array_values(array_filter(self::cases(), fn (self $tag): bool => ! $tag->codeOnly()));
    }

    /** One line for the prompt: what the tag means. */
    public function meaning(): string
    {
        return match ($this) {
            self::Quiet => 'a calm, quiet place',
            self::Lively => 'a lively, social place: bars, live music, dancing',
            self::Cosy => 'cosy, snug, homely',
            self::FamilyFriendly => 'set up for families with children',
            self::KidPlay => 'somewhere children can play',
            self::OutdoorSeating => 'seats outside: a terrace or beer garden',
            self::Indoors => 'an indoor place, good on a rainy day',
            self::GardenOrPark => 'a park or garden',
            self::Waterside => 'by a river, lake or other water',
            self::ScenicView => 'a view over the city or landscape',
            self::Historic => 'a historic site or building',
            self::WorkFriendly => 'suits working on a laptop',
            self::Budget => 'cheap, only when the text says so',
            self::Upscale => 'upscale or fine dining, only when the text says so',
            self::VegetarianFriendly => 'vegetarian dishes',
            self::VeganOptions => 'vegan dishes',
            self::DogFriendly => 'dogs welcome',
            self::LocalFood => 'German or Rhineland food and drink, e.g. a Brauhaus',
            self::StepFree => 'step-free access',
            self::LateNight => 'open until midnight or later',
        };
    }

    /**
     * Cue words (German and English) one of which a quoted text evidence must
     * contain, so a quote like "Bistro" can't stand in for "cosy". Null for
     * the code-only tags, which never take text evidence.
     */
    public function textCue(): ?string
    {
        return match ($this) {
            self::Quiet => '/ruhig|\bstill|quiet|calm|peace|tranquil|idyll|beschaulich|abgeschieden|secluded|entspann|relax|erholung/iu',
            self::Lively => '/lebendig|lebhaft|lively|vibrant|bustling|\blive\b|livemusik|konzert|concert|party|partys|\bdj\b|tanz|danc|musik|music|stimmung|feiern/iu',
            self::Cosy => '/gemütlich|gemuetlich|\bcos(y|ier)|\bcoz(y|ier)|urig|heimelig|kuschelig|behaglich|wohnzimmer|living room|kamin|fireplace|snug|intim/iu',
            self::FamilyFriendly => '/famil|kinder|kindgerecht|\bkids?\b|child|\bbab(y|ies|ys)\b|eltern|parents|wickel|kinderwagen|stroller|hochstuhl|highchair/iu',
            self::KidPlay => '/spiel|\bplay|toys?\b|klettern|climb|rutsch|\bslides?\b|schaukel|swings?\b|sandkasten|sandpit|sandbox|trampolin/iu',
            self::OutdoorSeating => '/terrass|terrace|biergarten|beer garden|garten|garden|draußen|draussen|outdoor|outside|außenbereich|aussenbereich|patio|freiluft|open[- ]air|im freien/iu',
            self::Indoors => '/indoor|drinnen|\binnen|halle\b|\bhall\b|überdacht|ueberdacht|covered/iu',
            self::GardenOrPark => '/park|garten|garden|grünfläche|wiese|meadow|lawn/iu',
            self::Waterside => '/rhein|rhine|\bufer|river|fluss|\bsee\b|\blake|weiher|teich|pond|wasser|water|hafen|harbou?r|kanal|canal|strand|beach|marina/iu',
            self::ScenicView => '/aussicht|ausblick|blick|\bview|panorama|skyline|overlook|fernsicht/iu',
            self::Historic => '/histor|mittelalter|medieval|römisch|roman\b|denkmal|monument|jahrhundert|century|altstadt|old town|gotisch|gothic|barock|baroque|antik|ancient|ruine|\bruins?\b/iu',
            self::WorkFriendly => '/wlan|wi-?fi|laptop|arbeit|\bwork|cowork|steckdose|socket|power outlet|\bstudy|lernen/iu',
            self::Budget => '/günstig|guenstig|preiswert|billig|\bcheap|inexpensive|budget|low[- ]cost|studentenpreis|affordable|kleines geld/iu',
            self::Upscale => '/gehoben|fine dining|gourmet|sterne|michelin|exklusiv|exclusive|luxus|luxur|upscale|\bedel/iu',
            self::VegetarianFriendly => '/vegetar|veggie|fleischlos|meat-?free|meatless|pflanzlich|plant-based/iu',
            self::VeganOptions => '/vegan/iu',
            self::DogFriendly => '/hund|\bdogs?\b/iu',
            self::LocalFood => '/kölsch|koelsch|brauhaus|rheinisch|rhenish|regional|traditionell|traditional|hausmannskost|deutsche küche|german (food|cuisine)|kölner küche/iu',
            self::StepFree, self::LateNight => null,
        };
    }
}
