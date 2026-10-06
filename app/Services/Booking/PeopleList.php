<?php

namespace App\Services\Booking;

use App\Models\Resource;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * The named people on a booking: attendees of a room, passengers of a
 * vehicle. One set of rules for every way a list arrives (a new request, a
 * pending one revised, a change to an approved one), so a name the form
 * accepts is a name the printed form can carry.
 */
final class PeopleList
{
    /** The most names one booking lists, whatever the room or vehicle seats. */
    public const MAX = 100;

    public const NAME_MAX = 100;

    /**
     * Tidy each name the way people expect: no stray or doubled spaces, no
     * invisible characters. Anything that isn't a list of strings passes
     * through untouched for validation to refuse.
     */
    public static function normalize(mixed $people): mixed
    {
        if (! is_array($people)) {
            return $people;
        }

        return array_values(array_map(
            fn ($name) => is_string($name)
                ? trim((string) preg_replace(['/[\p{Cc}\p{Cf}]+/u', '/[\s\p{Z}]+/u'], ['', ' '], $name))
                : $name,
            $people,
        ));
    }

    /**
     * @return array<string, list<string>>
     */
    public static function rules(bool $required = true): array
    {
        return [
            'people' => [$required ? 'required' : 'nullable', 'array', 'min:1', 'max:'.self::MAX],
            // A name needs at least one letter: "-", "123" and "." are not names.
            'people.*' => ['required', 'string', 'max:'.self::NAME_MAX, 'regex:/\p{L}/u'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'people.required' => 'Add at least one person.',
            'people.array' => 'Add at least one person.',
            'people.min' => 'Add at least one person.',
            'people.max' => 'A booking can list at most '.self::MAX.' people.',
            'people.*.required' => 'Enter a name, or remove this row.',
            'people.*.string' => 'Enter a name, or remove this row.',
            'people.*.max' => 'Keep each name under '.self::NAME_MAX.' characters.',
            'people.*.regex' => 'Enter a name with letters in it.',
        ];
    }

    /**
     * Flag a name listed twice (ignoring case and spacing) on its second
     * appearance, so the first stays valid and the error points at the row
     * to fix.
     */
    public static function flagDuplicates(Validator $validator, mixed $people): void
    {
        if (! is_array($people)) {
            return;
        }

        $seen = [];

        foreach ($people as $index => $name) {
            if (! is_string($name) || $name === '') {
                continue;
            }

            $key = mb_strtolower($name);

            if (isset($seen[$key])) {
                $validator->errors()->add("people.{$index}", 'This name is already on the list.');
            }

            $seen[$key] = true;
        }
    }

    /**
     * A room or vehicle seats so many; the list may not exceed it.
     *
     * @param  list<string>  $people
     */
    public static function assertFits(array $people, Resource $resource): void
    {
        $count = count($people);

        if ($resource->capacity !== null && $count > $resource->capacity) {
            throw ValidationException::withMessages([
                'people' => "{$resource->name} holds at most {$resource->capacity} people; this lists {$count}.",
            ]);
        }
    }
}
