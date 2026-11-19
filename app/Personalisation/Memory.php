<?php

namespace App\Personalisation;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * What Composer remembers about a person: lasting facts they told it ("Vegetarian", "Has a
 * 3-year-old", "Works from home on Fridays") or added themselves, read with every answer so it
 * knows them (owner, 16 Nov 2026: "the engine which knows the user"). Only with personalisation on,
 * under its pseudonym; never anything about health, religion or beliefs, sexuality, politics or a
 * union (GDPR Art. 9), whatever the words.
 */
final class Memory
{
    /** At most this many facts: the oldest are theirs to forget, never dropped by us. */
    public const LIMIT = 40;

    /** A fact is a few words. */
    public const LENGTH = 160;

    /** What change() says when the fact isn't theirs (any more). */
    public const GONE = 'That fact is gone.';

    /**
     * Words that would make a fact about a special category (Art. 9): health and disability, religion
     * and belief (and the food rules that say it), sexuality and gender identity, politics, a union.
     * English and German; whole words or word starts.
     */
    private const SENSITIVE = '/\b('
        // Health, disability, pregnancy.
        .'ill|illness|sick|disease|diagnos\w*|diabet\w*|asthma|allerg\w*|intoleran\w*|coeliac|celiac|pregnan\w*|schwanger\w*|depress\w*|anxiety|panic|adhd|autis\w*|bipolar|'
        .'cancer|krebs|hiv|aids|therap\w*|medicat\w*|medikament\w*|disab\w*|behinder\w*|wheelchair|rollstuhl|blind|deaf|gehörlos|chronic\w*|chronisch\w*|mental|psychiatr\w*|psychisch\w*|psychotherap\w*|'
        // Religion and belief.
        .'muslim\w*|islam\w*|christian\w*|christlich\w*|christ|catholic\w*|katholisch\w*|protestant\w*|evangelisch\w*|jew|jews|jewish|jüdisch\w*|hindu\w*|buddhis\w*|sikh\w*|atheis\w*|religio\w*|'
        .'pray|prays|prayer\w*|praying|beten|betet|gebet\w*|church|kirche|mosque|moschee|synagog\w*|temple|tempel|halal|kosher|koscher|ramadan|fasting|fasten|'
        // Sexuality and gender identity.
        .'gay|lesbian\w*|lesbisch\w*|bisexual\w*|queer|trans\w*gender|trans|nonbinary|non-binary|lgbt\w*|homosexu\w*|schwul\w*|'
        // Politics and unions.
        .'politic\w*|partei|party member|spd|cdu|csu|afd|fdp|grüne|linke|volt|bsw|union member|gewerkschaft\w*|verdi|ig metall'
        .')\b/iu';

    /** @return list<array{id: int, text: string, source: string, created_at: string}> their facts, oldest first */
    public function facts(User $user): array
    {
        return DB::table('user_facts')->where('subject_key', Pseudonym::for($user))->orderBy('created_at')->orderBy('id')
            ->get(['id', 'text', 'source', 'created_at'])
            ->map(fn (object $row): array => ['id' => (int) $row->id, 'text' => (string) $row->text, 'source' => (string) $row->source,
                'created_at' => CarbonImmutable::parse($row->created_at)->format(DATE_RFC3339_EXTENDED)])
            ->all();
    }

    /**
     * Why a fact can't be kept, in words the person reads; null when it can.
     */
    public function refusal(User $user, string $text): ?string
    {
        $text = self::clean($text);
        if (! $user->personalises()) {
            return 'Remembering things about you needs personalisation turned on.';
        }
        if ($text === '') {
            return 'There is nothing to remember.';
        }
        if (mb_strlen($text) > self::LENGTH) {
            return 'That is too long to remember; say it in a few words.';
        }
        if (self::sensitive($text)) {
            return 'Expadu never keeps anything about health, religion or beliefs, sexuality, politics or a union.';
        }
        $facts = $this->facts($user);
        // The same fact again, also with a note in brackets ("Has a partner (already known)").
        $bare = fn (string $words): string => trim(mb_strtolower((string) preg_replace('/\s*\([^)]*\)/u', '', $words)), " .\t");
        if (collect($facts)->contains(fn (array $fact): bool => $bare($fact['text']) === $bare($text))) {
            return 'Already remembered.';
        }
        if (count($facts) >= self::LIMIT) {
            return 'Memory is full. Forget something first.';
        }

        return null;
    }

    /**
     * Keeps a fact; the refusal's words when it can't.
     *
     * @return array{id: int, text: string}|string
     */
    public function remember(User $user, string $text, string $source = 'told'): array|string
    {
        $text = self::clean($text);
        if (($why = $this->refusal($user, $text)) !== null) {
            return $why;
        }
        $id = DB::table('user_facts')->insertGetId([
            'subject_key' => Pseudonym::for($user), 'text' => $text, 'source' => $source, 'created_at' => self::at(now()), 'updated_at' => self::at(now()),
        ]);

        return ['id' => (int) $id, 'text' => $text];
    }

    /** Changes a fact's words; the refusal's words when it can't. */
    public function change(User $user, int $id, string $text): array|string
    {
        $fact = $this->find($user, $id);
        if ($fact === null) {
            return self::GONE;
        }
        $text = self::clean($text);
        if (mb_strtolower($text) !== mb_strtolower($fact['text']) && ($why = $this->refusal($user, $text)) !== null && $why !== 'Memory is full. Forget something first.') {
            return $why;
        }
        DB::table('user_facts')->where('id', $id)->update(['text' => $text, 'updated_at' => self::at(now())]);

        return ['id' => $id, 'text' => $text];
    }

    /** @return array{id: int, text: string, source: string, created_at: string}|null theirs, by id */
    public function find(User $user, int $id): ?array
    {
        return collect($this->facts($user))->firstWhere('id', $id);
    }

    /** Forgets one; what it was, for Undo, or null when it wasn't theirs. */
    public function forget(User $user, int $id): ?array
    {
        $fact = $this->find($user, $id);
        if ($fact !== null) {
            DB::table('user_facts')->where('id', $id)->where('subject_key', Pseudonym::for($user))->delete();
        }

        return $fact;
    }

    /**
     * Puts back a fact Undo brought back, in its place in the list (its time kept) under a new id:
     * the one it had is never taken from what the phone sends. The refusal's words when it can't.
     *
     * @param  array{text: string, source?: string|null, created_at?: string|null}  $fact
     * @return array{id: int, text: string}|string
     */
    public function restore(User $user, array $fact): array|string
    {
        $text = self::clean((string) ($fact['text'] ?? ''));
        $why = $this->refusal($user, $text);
        if ($why === 'Already remembered.') {
            $kept = collect($this->facts($user))->first(fn (array $f): bool => mb_strtolower($f['text']) === mb_strtolower($text));

            return ['id' => $kept['id'], 'text' => $kept['text']];
        }
        if ($why !== null) {
            return $why;
        }
        $at = rescue(fn () => CarbonImmutable::parse((string) $fact['created_at']), null, false);
        $at = $at === null || $at->isFuture() ? now() : $at;
        $id = DB::table('user_facts')->insertGetId([
            'subject_key' => Pseudonym::for($user), 'text' => $text, 'source' => in_array($fact['source'] ?? null, ['told', 'added'], true) ? $fact['source'] : 'told',
            'created_at' => self::at($at), 'updated_at' => self::at(now()),
        ]);

        return ['id' => (int) $id, 'text' => $text];
    }

    /** Erases all of it (personalisation off, the account deleted). */
    public function erase(User|int $user): void
    {
        DB::table('user_facts')->where('subject_key', Pseudonym::for($user))->delete();
    }

    /** A time to the millisecond (the query builder would write whole seconds). */
    private static function at(CarbonInterface $time): string
    {
        return $time->format('Y-m-d H:i:s.vP');
    }

    public static function sensitive(string $text): bool
    {
        return preg_match(self::SENSITIVE, $text) === 1;
    }

    /** One line, trimmed, its first letter upper case: "vegetarian " is "Vegetarian". */
    public static function clean(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '', " \t\n\r\0\x0B.");

        return $text === '' ? '' : mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }
}
