export default {
    extends: ['@commitlint/config-conventional'],
    rules: {
        'type-enum': [2, 'always', ['feat', 'fix', 'chore', 'refactor', 'style', 'test', 'docs', 'perf', 'ci', 'wip']],
        'type-case': [2, 'always', 'lower-case'],
        'type-empty': [2, 'never'],
        'subject-empty': [2, 'never'],
        'subject-case': [0],
        'subject-full-stop': [2, 'never', '.'],
        'subject-max-length': [2, 'always', 100],
        'subject-min-length': [2, 'always', 5],
        'header-max-length': [2, 'always', 120],
        'body-max-line-length': [2, 'always', 200],
    }
};
