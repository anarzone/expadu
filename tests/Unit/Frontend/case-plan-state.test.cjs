const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { test } = require('node:test');
const vm = require('node:vm');
const React = require('react');
const { renderToStaticMarkup } = require('react-dom/server');
const ts = require('typescript');
const source = path.resolve(
    __dirname,
    '../../../resources/js/components/bureaucracy/case-plan-state.ts',
);
const exports_ = {};
vm.runInNewContext(
    ts.transpileModule(
        fs.existsSync(source) ? fs.readFileSync(source, 'utf8') : '',
        {
            compilerOptions: { module: ts.ModuleKind.CommonJS },
        },
    ).outputText,
    { exports: exports_ },
);

function plan(overrides = {}) {
    return {
        coverage_state: 'matched',
        active_conflict: null,
        next_question: null,
        sections: {
            current_status: [],
            do_now: [{ key: 'reviewed-step', type: 'task' }],
            next: [],
            coming_up: [],
            options: [],
            waiting: [],
            information_needed: [],
            good_to_know: [],
            opens_when: [],
            not_covered: [],
        },
        ...overrides,
    };
}

test('no selected question does not make a fresh uncovered case ready', () => {
    const fresh = plan({ coverage_state: 'not_covered' });
    fresh.sections.do_now = [];
    fresh.sections.not_covered = [{ kind: 'coverage_notice' }];
    assert.equal(exports_.caseAssistantState(fresh), 'empty');
});

test('partial coverage remains partial when some reviewed steps exist', () => {
    const partial = plan({ coverage_state: 'not_covered' });
    partial.sections.not_covered = [{ kind: 'coverage_notice' }];
    assert.equal(exports_.caseAssistantState(partial), 'partial');
});

test('a matched case with no unresolved details can show its ready state', () => {
    assert.equal(exports_.caseAssistantState(plan()), 'ready');
});

test('open or deferred questions never read as a complete set of details', () => {
    assert.equal(
        exports_.caseAssistantState(plan({ next_question: { id: 1 } })),
        'question',
    );
    assert.equal(
        exports_.caseAssistantState(
            plan({ coverage_state: 'needs_information' }),
        ),
        'needs_information',
    );
    const deferred = plan();
    deferred.sections.information_needed = [{ fact_key: 'missing-detail' }];
    assert.equal(exports_.caseAssistantState(deferred), 'needs_information');
});

test('rule conflicts and active answer conflicts both prevent a ready state', () => {
    assert.equal(
        exports_.caseAssistantState(plan({ coverage_state: 'conflict' })),
        'conflict',
    );
    assert.equal(
        exports_.caseAssistantState(plan({ active_conflict: { id: 1 } })),
        'conflict',
    );
});

test('an empty assistant offers optional review without claiming complete information', () => {
    const component = {};
    const route = { url: () => '/onboarding' };
    vm.runInNewContext(
        ts.transpileModule(
            fs.readFileSync(
                path.join(path.dirname(source), 'case-assistant-card.tsx'),
                'utf8',
            ),
            {
                compilerOptions: {
                    module: ts.ModuleKind.CommonJS,
                    jsx: ts.JsxEmit.ReactJSX,
                },
            },
        ).outputText,
        {
            exports: component,
            require: (id) => {
                if (id === '@inertiajs/react') {
                    return {
                        usePage: () => ({ props: {} }),
                        Link: ({ children, ...props }) =>
                            React.createElement('a', props, children),
                    };
                }

                if (id === '@/constants/icons') {
                    return { ICON_STROKE: 1.7 };
                }

                if (id === '@/routes') {
                    return { onboarding: route };
                }

                if (id.startsWith('@/routes/')) {
                    return { update: route, store: route, answer: route };
                }

                if (id === './ai-consent-sheet') {
                    return { AiConsentSheet: () => null };
                }

                return require(id);
            },
        },
    );
    const html = renderToStaticMarkup(
        React.createElement(component.CaseAssistantCard, {
            question: null,
            state: 'empty',
            ai: { available: false, consented: false, remaining_quota: 0 },
        }),
    );
    assert.doesNotMatch(html, /enough confirmed information|Verified rules/);
    assert.match(html, /Review my details/);
});
