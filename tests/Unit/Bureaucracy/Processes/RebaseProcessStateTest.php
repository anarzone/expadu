<?php

use App\Bureaucracy\Processes\RebaseProcessState;

test('review keeps unchanged work removes inapplicable steps and does not complete new requirements', function () {
    $old = [['id' => 'a', 'depends_on' => [], 'semantic_hash' => 'one'], ['id' => 'b', 'depends_on' => [], 'semantic_hash' => 'two']];
    $new = [$old[0], ['id' => 'c', 'depends_on' => ['a'], 'semantic_hash' => 'three']];
    $state = ['workflow' => 'completed', 'steps' => ['a' => 'completed', 'b' => 'completed'], 'completion_basis' => 'user_report'];
    $rebased = (new RebaseProcessState)->for($state, $old, $new);
    expect($rebased['steps'])->toBe(['a' => 'completed', 'c' => 'todo'])
        ->and($rebased['workflow'])->toBe('preparing')->and($rebased['completion_basis'])->toBeNull();
});

test('changed step meaning cannot inherit a previous completion', function () {
    $old = [['id' => 'a', 'depends_on' => [], 'semantic_hash' => 'one']];
    $new = [['id' => 'a', 'depends_on' => [], 'semantic_hash' => 'changed']];
    $state = ['workflow' => 'waiting_authority', 'steps' => ['a' => 'completed'], 'completion_basis' => null];
    $rebased = (new RebaseProcessState)->for($state, $old, $new);
    expect($rebased['steps'])->toBe(['a' => 'todo'])->and($rebased['workflow'])->toBe('waiting_authority');
});
