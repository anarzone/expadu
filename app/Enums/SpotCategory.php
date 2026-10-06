<?php

namespace App\Enums;

enum SpotCategory: string
{
    // v2 physical-leisure categories — the primary Places content
    case Park = 'park';
    case SportsCentre = 'sports_centre';
    case Playground = 'playground';
    case Pitch = 'pitch';
    case Basketball = 'basketball';
    case Tennis = 'tennis';
    case Skatepark = 'skatepark';
    case Swimming = 'swimming';
    case Lake = 'lake';
    case DogPark = 'dog_park';
    case TableTennis = 'table_tennis';
    case Boules = 'boules';
    case Bbq = 'bbq';
    case Picnic = 'picnic';
    case Viewpoint = 'viewpoint';

    // Culture & sights — named destinations, not facilities
    case Museum = 'museum';
    case Gallery = 'gallery';
    case Attraction = 'attraction';
    case Zoo = 'zoo';

    // Food, drink and other indoor destinations.
    case Cafe = 'cafe';
    case Coworking = 'coworking';
    case Library = 'library';
    case Restaurant = 'restaurant';
    case FastFood = 'fast_food';
    case Bar = 'bar';
    case Bakery = 'bakery';

    // Named everyday destinations; source subtypes remain in evidence tags.
    case Supermarket = 'supermarket';
    case Convenience = 'convenience';
    case Kiosk = 'kiosk';
    case Clothes = 'clothes';
    case Shoes = 'shoes';
    case Jewelry = 'jewelry';
    case DepartmentStore = 'department_store';
    case ShoppingCentre = 'shopping_centre';
    case GeneralStore = 'general_store';
    case BicycleShop = 'bicycle_shop';
    case SportsShop = 'sports_shop';
    case Bookshop = 'bookshop';
    case Stationery = 'stationery';
    case Electronics = 'electronics';
    case PhoneShop = 'phone_shop';
    case ComputerShop = 'computer_shop';
    case Furniture = 'furniture';
    case Homeware = 'homeware';
    case Hardware = 'hardware';
    case GardenCentre = 'garden_centre';
    case Florist = 'florist';
    case PetShop = 'pet_shop';
    case ToyShop = 'toy_shop';
    case GiftShop = 'gift_shop';
    case ArtShop = 'art_shop';
    case Antiques = 'antiques';
    case SecondHand = 'second_hand';
    case MusicShop = 'music_shop';
    case DrinksShop = 'drinks_shop';
    case CoffeeTeaShop = 'coffee_tea_shop';
    case Butcher = 'butcher';
    case Greengrocer = 'greengrocer';
    case Delicatessen = 'delicatessen';
    case SeafoodShop = 'seafood_shop';
    case Confectionery = 'confectionery';
    case CarDealer = 'car_dealer';
    case AutoParts = 'auto_parts';
    case MotorcycleShop = 'motorcycle_shop';
    case Hairdresser = 'hairdresser';
    case Beauty = 'beauty';
    case Laundry = 'laundry';
    case DryCleaning = 'dry_cleaning';
    case Tailor = 'tailor';
    case ShoeRepair = 'shoe_repair';
    case CarRepair = 'car_repair';
    case TravelAgency = 'travel_agency';
    case TicketShop = 'ticket_shop';
    case Massage = 'massage';
    case Tattoo = 'tattoo';
    case CopyShop = 'copy_shop';
    case PetGrooming = 'pet_grooming';
    case Locksmith = 'locksmith';
    case Bank = 'bank';
    case PostOffice = 'post_office';
    case CarRental = 'car_rental';
    case Pharmacy = 'pharmacy';
    case Drugstore = 'drugstore';
    case Doctor = 'doctor';
    case Dentist = 'dentist';
    case Optician = 'optician';
    case HearingAids = 'hearing_aids';
    case MedicalSupply = 'medical_supply';
    case Clinic = 'clinic';
    case Hospital = 'hospital';
    case Veterinary = 'veterinary';
    case CommunityCentre = 'community_centre';
    case PlaceOfWorship = 'place_of_worship';
    case LanguageSchool = 'language_school';
    case MusicSchool = 'music_school';
    case Hotel = 'hotel';
    case Hostel = 'hostel';
    case GuestHouse = 'guest_house';
    case Motel = 'motel';
    case Campsite = 'campsite';
    case FitnessCentre = 'fitness_centre';
    case DanceStudio = 'dance_studio';
    case Theatre = 'theatre';
    case Cinema = 'cinema';
    case ArtsCentre = 'arts_centre';
    case EscapeRoom = 'escape_room';
    case IceCream = 'ice_cream';
    case Market = 'market';

    public function isActivityFacility(): bool
    {
        return in_array($this, [self::Playground, self::Pitch, self::Basketball,
            self::Tennis, self::TableTennis, self::Boules, self::DogPark,
            self::Bbq, self::Picnic, self::Skatepark], true);
    }

    public function isOutdoor(): bool
    {
        return match ($this) {
            self::Park, self::Playground, self::Pitch, self::Basketball,
            self::Tennis, self::Skatepark, self::Lake, self::DogPark,
            self::TableTennis, self::Boules, self::Bbq, self::Picnic,
            self::Viewpoint, self::Zoo, self::Campsite, self::Market => true,
            default => false,
        };
    }

    public function emoji(): string
    {
        return match ($this) {
            self::Park => '🌳',
            self::SportsCentre => '🏟️',
            self::Playground => '🛝',
            self::Pitch => '⚽',
            self::Basketball => '🏀',
            self::Tennis => '🎾',
            self::Skatepark => '🛹',
            self::Swimming => '🏊',
            self::Lake => '🏞️',
            self::DogPark => '🐕',
            self::TableTennis => '🏓',
            self::Boules => '🎯',
            self::Bbq => '🍖',
            self::Picnic => '🧺',
            self::Viewpoint => '🌅',
            self::Museum => '🏛️',
            self::Gallery => '🖼️',
            self::Attraction => '🎡',
            self::Zoo => '🦁',
            self::Cafe => '☕',
            self::Coworking => '💻',
            self::Library => '📚',
            self::Restaurant => '🍽️',
            self::FastFood => '🌯',
            self::Bar => '🍻',
            self::Supermarket => '🛍️',
            self::Convenience => '🛍️',
            self::Kiosk => '🛍️',
            self::Clothes => '🛍️',
            self::Shoes => '🛍️',
            self::Jewelry => '🛍️',
            self::DepartmentStore => '🛍️',
            self::ShoppingCentre => '🛍️',
            self::GeneralStore => '🛍️',
            self::BicycleShop => '🛍️',
            self::SportsShop => '🛍️',
            self::Bookshop => '🛍️',
            self::Stationery => '🛍️',
            self::Electronics => '🛍️',
            self::PhoneShop => '🛍️',
            self::ComputerShop => '🛍️',
            self::Furniture => '🛍️',
            self::Homeware => '🛍️',
            self::Hardware => '🛍️',
            self::GardenCentre => '🛍️',
            self::Florist => '🛍️',
            self::PetShop => '🛍️',
            self::ToyShop => '🛍️',
            self::GiftShop => '🛍️',
            self::ArtShop => '🛍️',
            self::Antiques => '🛍️',
            self::SecondHand => '🛍️',
            self::MusicShop => '🛍️',
            self::DrinksShop => '🛍️',
            self::CoffeeTeaShop => '🛍️',
            self::Butcher => '🛍️',
            self::Greengrocer => '🛍️',
            self::Delicatessen => '🛍️',
            self::SeafoodShop => '🛍️',
            self::Confectionery => '🛍️',
            self::CarDealer => '🛍️',
            self::AutoParts => '🛍️',
            self::MotorcycleShop => '🛍️',
            self::Hairdresser => '🛠️',
            self::Beauty => '🛠️',
            self::Laundry => '🛠️',
            self::DryCleaning => '🛠️',
            self::Tailor => '🛠️',
            self::ShoeRepair => '🛠️',
            self::CarRepair => '🛠️',
            self::TravelAgency => '🛠️',
            self::TicketShop => '🛠️',
            self::Massage => '🛠️',
            self::Tattoo => '🛠️',
            self::CopyShop => '🛠️',
            self::PetGrooming => '🛠️',
            self::Locksmith => '🛠️',
            self::Bank => '🛠️',
            self::PostOffice => '🛠️',
            self::CarRental => '🛠️',
            self::Pharmacy => '🩺',
            self::Drugstore => '🩺',
            self::Doctor => '🩺',
            self::Dentist => '🩺',
            self::Optician => '🩺',
            self::HearingAids => '🩺',
            self::MedicalSupply => '🩺',
            self::Clinic => '🩺',
            self::Hospital => '🩺',
            self::Veterinary => '🩺',
            self::CommunityCentre => '🤝',
            self::PlaceOfWorship => '🤝',
            self::LanguageSchool => '🤝',
            self::MusicSchool => '🤝',
            self::Hotel => '🛏️',
            self::Hostel => '🛏️',
            self::GuestHouse => '🛏️',
            self::Motel => '🛏️',
            self::Campsite => '🛏️',
            self::FitnessCentre => '🏋️',
            self::DanceStudio => '🏋️',
            self::Theatre => '🎭',
            self::Cinema => '🎭',
            self::ArtsCentre => '🎭',
            self::EscapeRoom => '🎭',
            self::IceCream => '🍦',
            self::Market => '🍦',

            self::Bakery => '🥐',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Park => 'Park',
            self::SportsCentre => 'Sports centre',
            self::Playground => 'Playground',
            self::Pitch => 'Pitch',
            self::Basketball => 'Basketball court',
            self::Tennis => 'Tennis court',
            self::Skatepark => 'Skatepark',
            self::Swimming => 'Swimming',
            self::Lake => 'Lake',
            self::DogPark => 'Dog park',
            self::TableTennis => 'Table tennis',
            self::Boules => 'Boules',
            self::Bbq => 'BBQ spot',
            self::Picnic => 'Picnic spot',
            self::Viewpoint => 'Viewpoint',
            self::Museum => 'Museum',
            self::Gallery => 'Gallery',
            self::Attraction => 'Attraction',
            self::Zoo => 'Zoo',
            self::Cafe => 'Café',
            self::Coworking => 'Coworking',
            self::Library => 'Library',
            self::Restaurant => 'Restaurant',
            self::FastFood => 'Fast food',
            self::Bar => 'Bar',
            self::Supermarket => 'Supermarket',
            self::Convenience => 'Convenience shop',
            self::Kiosk => 'Kiosk',
            self::Clothes => 'Clothes shop',
            self::Shoes => 'Shoe shop',
            self::Jewelry => 'Jewellery shop',
            self::DepartmentStore => 'Department store',
            self::ShoppingCentre => 'Shopping centre',
            self::GeneralStore => 'General shop',
            self::BicycleShop => 'Bicycle shop',
            self::SportsShop => 'Sports shop',
            self::Bookshop => 'Bookshop',
            self::Stationery => 'Stationery shop',
            self::Electronics => 'Electronics shop',
            self::PhoneShop => 'Phone shop',
            self::ComputerShop => 'Computer shop',
            self::Furniture => 'Furniture shop',
            self::Homeware => 'Homeware shop',
            self::Hardware => 'Hardware shop',
            self::GardenCentre => 'Garden centre',
            self::Florist => 'Florist',
            self::PetShop => 'Pet shop',
            self::ToyShop => 'Toy shop',
            self::GiftShop => 'Gift shop',
            self::ArtShop => 'Art shop',
            self::Antiques => 'Antiques shop',
            self::SecondHand => 'Second-hand shop',
            self::MusicShop => 'Music shop',
            self::DrinksShop => 'Drinks shop',
            self::CoffeeTeaShop => 'Coffee and tea shop',
            self::Butcher => 'Butcher',
            self::Greengrocer => 'Greengrocer',
            self::Delicatessen => 'Delicatessen',
            self::SeafoodShop => 'Seafood shop',
            self::Confectionery => 'Confectionery shop',
            self::CarDealer => 'Car dealership',
            self::AutoParts => 'Car parts shop',
            self::MotorcycleShop => 'Motorcycle shop',
            self::Hairdresser => 'Hairdresser',
            self::Beauty => 'Beauty salon',
            self::Laundry => 'Laundry',
            self::DryCleaning => 'Dry cleaner',
            self::Tailor => 'Tailor',
            self::ShoeRepair => 'Shoe repair',
            self::CarRepair => 'Car repair',
            self::TravelAgency => 'Travel agency',
            self::TicketShop => 'Ticket office',
            self::Massage => 'Massage studio',
            self::Tattoo => 'Tattoo studio',
            self::CopyShop => 'Copy shop',
            self::PetGrooming => 'Pet grooming',
            self::Locksmith => 'Locksmith',
            self::Bank => 'Bank',
            self::PostOffice => 'Post office',
            self::CarRental => 'Car rental',
            self::Pharmacy => 'Pharmacy',
            self::Drugstore => 'Drugstore',
            self::Doctor => 'Doctor',
            self::Dentist => 'Dentist',
            self::Optician => 'Optician',
            self::HearingAids => 'Hearing aid specialist',
            self::MedicalSupply => 'Medical supplies',
            self::Clinic => 'Clinic',
            self::Hospital => 'Hospital',
            self::Veterinary => 'Veterinary clinic',
            self::CommunityCentre => 'Community centre',
            self::PlaceOfWorship => 'Place of worship',
            self::LanguageSchool => 'Language school',
            self::MusicSchool => 'Music school',
            self::Hotel => 'Hotel',
            self::Hostel => 'Hostel',
            self::GuestHouse => 'Guest house',
            self::Motel => 'Motel',
            self::Campsite => 'Campsite',
            self::FitnessCentre => 'Gym',
            self::DanceStudio => 'Dance studio',
            self::Theatre => 'Theatre',
            self::Cinema => 'Cinema',
            self::ArtsCentre => 'Arts centre',
            self::EscapeRoom => 'Escape room',
            self::IceCream => 'Ice cream shop',
            self::Market => 'Market',

            self::Bakery => 'Bakery',
        };
    }

    /**
     * Places filter buckets. Work/study categories remain outside browsing.
     */
    public function coarse(): string
    {
        return match ($this) {
            self::Park, self::Viewpoint, self::Bbq, self::Picnic => 'park',
            self::Pitch => 'pitch',
            self::SportsCentre, self::Basketball, self::Tennis, self::TableTennis, self::Boules, self::Skatepark => 'court',
            self::Swimming, self::Lake => 'swimming',
            self::Playground => 'playground',
            self::DogPark => 'dog_park',
            self::Museum, self::Gallery, self::Attraction, self::Zoo => 'culture',
            self::Cafe, self::Restaurant, self::FastFood, self::Bar, self::Bakery => 'food_drink',
            self::Supermarket, self::Convenience, self::Kiosk, self::Clothes, self::Shoes, self::Jewelry, self::DepartmentStore, self::ShoppingCentre, self::GeneralStore, self::BicycleShop, self::SportsShop, self::Bookshop, self::Stationery, self::Electronics, self::PhoneShop, self::ComputerShop, self::Furniture, self::Homeware, self::Hardware, self::GardenCentre, self::Florist, self::PetShop, self::ToyShop, self::GiftShop, self::ArtShop, self::Antiques, self::SecondHand, self::MusicShop, self::DrinksShop, self::CoffeeTeaShop, self::Butcher, self::Greengrocer, self::Delicatessen, self::SeafoodShop, self::Confectionery, self::CarDealer, self::AutoParts, self::MotorcycleShop => 'shopping',
            self::Hairdresser, self::Beauty, self::Laundry, self::DryCleaning, self::Tailor, self::ShoeRepair, self::CarRepair, self::TravelAgency, self::TicketShop, self::Massage, self::Tattoo, self::CopyShop, self::PetGrooming, self::Locksmith, self::Bank, self::PostOffice, self::CarRental => 'services',
            self::Pharmacy, self::Drugstore, self::Doctor, self::Dentist, self::Optician, self::HearingAids, self::MedicalSupply, self::Clinic, self::Hospital, self::Veterinary => 'health',
            self::CommunityCentre, self::PlaceOfWorship, self::LanguageSchool, self::MusicSchool => 'community',
            self::Hotel, self::Hostel, self::GuestHouse, self::Motel, self::Campsite => 'stay',
            self::FitnessCentre, self::DanceStudio => 'fitness',
            self::Theatre, self::Cinema, self::ArtsCentre, self::EscapeRoom => 'culture',
            self::IceCream, self::Market => 'food_drink',
            default => 'other',
        };
    }

    /**
     * Fine category values that roll up into a coarse Places bucket.
     *
     * @return list<string>
     */
    public static function finesForCoarse(string $coarse): array
    {
        return array_values(array_map(
            fn (self $c) => $c->value,
            array_filter(self::cases(), fn (self $c) => $c->coarse() === $coarse),
        ));
    }

    /**
     * Fine category values for a selector that may be EITHER a coarse bucket
     * ('court' → all its fines) OR an already-fine value ('basketball' → itself).
     *
     * Mirrors how the composer's feasibility filter widens a request: a fine
     * value always matches itself. Bare finesForCoarse() returns [] for a fine
     * value that heads no coarse family of its own (basketball/tennis roll up
     * into 'court'; library into 'other') — so a solo "basketball" plan was
     * silently emptied to complements. This never returns [] for a known value,
     * so the composer builds the day around exactly what was asked for.
     *
     * @return list<string>
     */
    public static function finesForSelector(string $selector): array
    {
        $fines = self::finesForCoarse($selector);

        // Not a coarse bucket → the selector is already a fine value (or an
        // unknown token like an event category); return it as-is.
        return $fines !== [] ? $fines : [$selector];
    }

    /** All fine category values that belong to the Places page (non-'other'). */
    public static function placesFines(): array
    {
        return array_values(array_map(
            fn (self $c) => $c->value,
            array_filter(self::cases(), fn (self $c) => $c->coarse() !== 'other'),
        ));
    }

    /** Coarse selectors accepted by every Places/Composer consumer. */
    public static function placesCoarse(): array
    {
        return array_values(array_unique(array_map(
            fn (self $category): string => $category->coarse(),
            array_filter(self::cases(), fn (self $category): bool => $category->coarse() !== 'other'),
        )));
    }

    public function preservesVenueIdentity(): bool
    {
        return in_array($this->coarse(), ['food_drink', 'shopping', 'services', 'health', 'community', 'stay', 'fitness'], true)
            || in_array($this, [self::Theatre, self::Cinema, self::ArtsCentre, self::EscapeRoom], true);
    }

    /** @return list<string> */
    public static function independentVenueFines(): array
    {
        return array_values(array_map(
            fn (self $category): string => $category->value,
            array_filter(self::cases(), fn (self $category): bool => $category->preservesVenueIdentity()),
        ));
    }

    public static function coarseLabel(string $coarse): string
    {
        return match ($coarse) {
            'park' => 'Parks',
            'pitch' => 'Pitches',
            'court' => 'Courts',
            'swimming' => 'Swimming',
            'playground' => 'Playgrounds',
            'dog_park' => 'Dog parks',
            'culture' => 'Culture',
            'food_drink' => 'Food & drink',
            'shopping' => 'Shopping',
            'services' => 'Local services',
            'health' => 'Health',
            'community' => 'Community',
            'stay' => 'Places to stay',
            'fitness' => 'Fitness',

            default => ucfirst($coarse),
        };
    }
}
