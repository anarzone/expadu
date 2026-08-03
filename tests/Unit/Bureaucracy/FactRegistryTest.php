<?php

use App\Bureaucracy\Facts\FactDefinition;
use App\Bureaucracy\Facts\FactRegistry;
use Illuminate\Support\Collection;
use Symfony\Component\Yaml\Yaml;

/*
|--------------------------------------------------------------------------
| Test helpers
|--------------------------------------------------------------------------
|
| The production catalogue does not yet exist, so the registry's default path
| is loaded directly in the live-catalogue tests. Normalization and rejection
| tests synthesise disposable YAML files on the fly and never touch the real
| catalogue, seed data, config, or migrations.
|
*/

function bureaucracyCataloguePath(): string
{
    return dirname(__DIR__, 3).'/database/seeders/data/bureaucracy/schema/facts.yaml';
}

function writeTemporaryCatalogue(array $facts): string
{
    $path = sys_get_temp_dir().'/facts-'.bin2hex(random_bytes(6)).'.yaml';
    file_put_contents($path, Yaml::dump($facts));

    return $path;
}

/*
|--------------------------------------------------------------------------
| Live catalogue: structure + every registered key
|--------------------------------------------------------------------------
*/

test('all returns an Illuminate keyed collection of every registered fact', function () {
    $registry = new FactRegistry(bureaucracyCataloguePath());

    $all = $registry->all();

    expect($all)->toBeInstanceOf(Collection::class);
    expect($all->isEmpty())->toBeFalse();
    expect($all->keys()->all())->toBe($all->keys()->unique()->values()->all());
});

test('a no-argument registry resolves the default catalogue without throwing', function () {
    $registry = new FactRegistry;

    expect($registry->all())->toBeInstanceOf(Collection::class);
});

/*
|--------------------------------------------------------------------------
| Live catalogue: the legally decisive facts
|--------------------------------------------------------------------------
*/

$legallyDecisiveFacts = [
    ['current_residence_title', ['type' => 'enum', 'priority' => 100, 'reconfirm' => 180, 'sensitivity' => 'high']],
    ['residence_title_expires_at', ['type' => 'date', 'priority' => 100, 'reconfirm' => 180, 'sensitivity' => 'high']],
    ['case_goal', ['type' => 'enum', 'priority' => 90, 'reconfirm' => 180, 'sensitivity' => 'normal']],
    ['sponsor_current_title', ['type' => 'enum', 'priority' => 95, 'reconfirm' => 180, 'sensitivity' => 'high']],
    ['blue_card_qualifying_months', ['type' => 'integer', 'priority' => 90, 'reconfirm' => 30, 'sensitivity' => 'high']],
    ['family_residence_permit_held_since', ['type' => 'date', 'priority' => 85, 'reconfirm' => 180, 'sensitivity' => 'high']],
    ['marital_household_continues', ['type' => 'boolean', 'priority' => 95, 'reconfirm' => 180, 'sensitivity' => 'high']],
    ['weekly_work_hours', ['type' => 'integer', 'priority' => 80, 'reconfirm' => 90, 'sensitivity' => 'normal']],
    ['livelihood_secured', ['type' => 'enum', 'priority' => 75, 'reconfirm' => 90, 'sensitivity' => 'high']],
    ['housing_sufficient', ['type' => 'enum', 'priority' => 60, 'reconfirm' => 180, 'sensitivity' => 'normal']],
    ['legal_social_knowledge_proved', ['type' => 'enum', 'priority' => 55, 'reconfirm' => 365, 'sensitivity' => 'normal']],
];

test('each legally decisive fact is registered with its exact decisive values', function (string $key, array $expected) {
    $registry = new FactRegistry(bureaucracyCataloguePath());
    $definition = $registry->definition($key);

    expect($definition)->toBeInstanceOf(FactDefinition::class);
    expect($definition->key)->toBe($key);
    expect($definition->type)->toBe($expected['type']);
    expect($definition->priority)->toBe($expected['priority']);
    expect($definition->reconfirmAfterDays)->toBe($expected['reconfirm']);
    expect($definition->sensitivity)->toBe($expected['sensitivity']);
})->with($legallyDecisiveFacts);

test('the residence title enum registers its canonical route options', function () {
    $registry = new FactRegistry(bureaucracyCataloguePath());
    $definition = $registry->definition('current_residence_title');

    expect($definition->options)->toBe([
        'national_d_visa',
        'standard_work_permit',
        'blue_card',
        'family_reunification',
        'settlement_permit_18c',
        'other',
    ]);
});

test('the sponsor title enum registers its canonical route options', function () {
    $registry = new FactRegistry(bureaucracyCataloguePath());
    $definition = $registry->definition('sponsor_current_title');

    expect($definition->options)->toBe([
        'national_d_visa',
        'standard_work_permit',
        'blue_card_pending',
        'blue_card',
        'settlement_permit_18c',
        'other',
    ]);
});

test('the case goal enum registers its canonical route options', function () {
    $registry = new FactRegistry(bureaucracyCataloguePath());
    $definition = $registry->definition('case_goal');

    expect($definition->options)->toBe(['blue_card', 'renew_current_title', 'settlement_permit', 'understand_options']);
});

/*
|--------------------------------------------------------------------------
| Live catalogue: the six additional registered keys
|--------------------------------------------------------------------------
*/

$additionalKeys = ['entry_mode', 'visa_expires_at', 'german_level', 'citizenship_group', 'purpose', 'permit_track'];

test('the six additional keys are registered so rules never depend on an unregistered key', function (string $key) {
    $registry = new FactRegistry(bureaucracyCataloguePath());

    expect($registry->definition($key))->toBeInstanceOf(FactDefinition::class);
    expect($registry->all()->has($key))->toBeTrue();
})->with($additionalKeys);

/*
|--------------------------------------------------------------------------
| FactDefinition contract
|--------------------------------------------------------------------------
*/

test('a definition exposes all documented public readonly camelCase properties', function () {
    $registry = new FactRegistry(bureaucracyCataloguePath());
    $definition = $registry->definition('current_residence_title');

    foreach (['key', 'type', 'options', 'question', 'why', 'sensitivity', 'priority', 'reconfirmAfterDays', 'legacyValues'] as $property) {
        expect(property_exists($definition, $property))->toBeTrue();
    }

    expect($definition->question)->toBe('Which German visa or residence title do you currently hold?');
    expect($definition->why)->toBe('Your current title changes the application route and which deadline applies.');
});

test('a definition is immutable to external writes', function () {
    $registry = new FactRegistry(bureaucracyCataloguePath());
    $definition = $registry->definition('current_residence_title');

    try {
        $definition->type = 'mutated';
    } catch (Error $e) {
        expect(str_contains($e->getMessage(), 'readonly'))->toBeTrue();

        return;
    }

    $this->fail('A readonly property must reject reassignment from outside the class.');
});

test('definition of an unknown key throws a domain exception', function () {
    $registry = new FactRegistry(bureaucracyCataloguePath());

    $registry->definition('definitely_not_a_registered_key');
})->throws(DomainException::class);

/*
|--------------------------------------------------------------------------
| Value normalisation (synthetic catalogue with an explicit legacy mapping)
|--------------------------------------------------------------------------
*/

test('normalize passes canonical enum values through unchanged', function () {
    $path = writeTemporaryCatalogue([
        'current_residence_title' => [
            'type' => 'enum',
            'options' => ['blue_card', 'other'],
            'legacy_values' => ['legacy_blue_card' => 'blue_card'],
            'question' => 'test',
            'why' => 'test',
            'sensitivity' => 'high',
            'priority' => 100,
            'reconfirm_after_days' => 180,
        ],
    ]);

    $registry = new FactRegistry($path);
    $definition = $registry->definition('current_residence_title');

    expect($definition->normalize('blue_card'))->toBe('blue_card');
});

test('normalize applies only an explicit legacy value mapping', function () {
    $path = writeTemporaryCatalogue([
        'current_residence_title' => [
            'type' => 'enum',
            'options' => ['blue_card', 'other'],
            'legacy_values' => ['legacy_blue_card' => 'blue_card'],
            'question' => 'test',
            'why' => 'test',
            'sensitivity' => 'high',
            'priority' => 100,
            'reconfirm_after_days' => 180,
        ],
    ]);

    $registry = new FactRegistry($path);

    expect($registry->definition('current_residence_title')->normalize('legacy_blue_card'))->toBe('blue_card');
});

test('normalize throws a domain exception for a value with no registered mapping', function () {
    $path = writeTemporaryCatalogue([
        'current_residence_title' => [
            'type' => 'enum',
            'options' => ['blue_card', 'other'],
            'legacy_values' => ['legacy_blue_card' => 'blue_card'],
            'question' => 'test',
            'why' => 'test',
            'sensitivity' => 'high',
            'priority' => 100,
            'reconfirm_after_days' => 180,
        ],
    ]);

    $registry = new FactRegistry($path);

    $registry->definition('current_residence_title')->normalize('some_unmapped_free_form_string');
})->throws(DomainException::class);

/*
|--------------------------------------------------------------------------
| Rejection of malformed catalogues (disposable YAML files)
|--------------------------------------------------------------------------
*/

test('a catalogue with duplicate keys is rejected', function () {
    $path = sys_get_temp_dir().'/facts-'.bin2hex(random_bytes(6)).'.yaml';
    file_put_contents($path, <<<'YAML'
current_residence_title:
  type: enum
  options: [blue_card]
  question: test
  why: test
  sensitivity: high
  priority: 100
  reconfirm_after_days: 180
current_residence_title:
  type: enum
  options: [blue_card]
  question: test
  why: test
  sensitivity: high
  priority: 100
  reconfirm_after_days: 180
YAML);

    new FactRegistry($path);
})->throws(DomainException::class);

test('a catalogue with an unsupported type is rejected', function () {
    $path = writeTemporaryCatalogue([
        'odd_fact' => [
            'type' => 'banana',
            'question' => 'test',
            'why' => 'test',
            'sensitivity' => 'normal',
            'priority' => 10,
            'reconfirm_after_days' => 30,
        ],
    ]);

    new FactRegistry($path);
})->throws(DomainException::class);

test('an enum with an invalid default is rejected', function () {
    $path = writeTemporaryCatalogue([
        'odd_fact' => [
            'type' => 'enum',
            'options' => ['blue_card', 'other'],
            'default' => 'not_a_registered_option',
            'question' => 'test',
            'why' => 'test',
            'sensitivity' => 'normal',
            'priority' => 10,
            'reconfirm_after_days' => 30,
        ],
    ]);

    new FactRegistry($path);
})->throws(DomainException::class);

test('a fact missing question text is rejected', function () {
    $path = writeTemporaryCatalogue([
        'odd_fact' => [
            'type' => 'enum',
            'options' => ['blue_card'],
            'why' => 'test',
            'sensitivity' => 'normal',
            'priority' => 10,
            'reconfirm_after_days' => 30,
        ],
    ]);

    new FactRegistry($path);
})->throws(DomainException::class);

test('a fact with an undefined reconfirmation interval is rejected', function () {
    $path = writeTemporaryCatalogue([
        'odd_fact' => [
            'type' => 'enum',
            'options' => ['blue_card'],
            'question' => 'test',
            'why' => 'test',
            'sensitivity' => 'normal',
            'priority' => 10,
        ],
    ]);

    new FactRegistry($path);
})->throws(DomainException::class);
