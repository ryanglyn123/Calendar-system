/**
 * The named people on a booking, checked the way the server checks them
 * (PeopleList.php), so the form can answer before the round trip. The
 * server still decides.
 */
export const PEOPLE_MAX = 100;
export const NAME_MAX = 100;

/** No stray or doubled spaces, no invisible characters. */
export const tidyName = (name: string): string =>
    name
        .replace(/[\p{Cc}\p{Cf}]+/gu, '')
        .replace(/[\s\p{Z}]+/gu, ' ')
        .trim();

/**
 * What goes to the server: tidied names, with rows left blank dropped
 * (an "Add person" never typed into is not a mistake worth stopping for).
 */
export const peopleToSend = (people: string[]): string[] =>
    people.map(tidyName).filter((name) => name !== '');

/**
 * Problems keyed like the server's errors: `people` for the list,
 * `people.N` for row N of the list as sent.
 */
export function peopleProblems(
    people: string[],
    seats: { capacity: number | null | undefined; name?: string },
): Record<string, string[]> {
    const names = peopleToSend(people);
    const problems: Record<string, string[]> = {};
    const seen = new Set<string>();

    if (names.length === 0) {
        problems.people = ['Add at least one person.'];
    } else if (seats.capacity && names.length > seats.capacity) {
        problems.people = [
            `${seats.name ?? 'It'} holds at most ${seats.capacity} people; this lists ${names.length}.`,
        ];
    } else if (names.length > PEOPLE_MAX) {
        problems.people = [`A booking can list at most ${PEOPLE_MAX} people.`];
    }

    names.forEach((name, i) => {
        const key = name.toLocaleLowerCase();

        if (!/\p{L}/u.test(name)) {
            problems[`people.${i}`] = ['Enter a name with letters in it.'];
        } else if (name.length > NAME_MAX) {
            problems[`people.${i}`] = [
                `Keep each name under ${NAME_MAX} characters.`,
            ];
        } else if (seen.has(key)) {
            problems[`people.${i}`] = ['This name is already on the list.'];
        }

        seen.add(key);
    });

    return problems;
}

/**
 * Errors are keyed by position in the list as sent (blank rows dropped);
 * the editor shows them on its own rows, blanks included.
 */
export function rowErrors(
    people: string[],
    errors: Record<string, string[] | undefined>,
): (string | undefined)[] {
    let sent = -1;

    return people.map((name) => {
        if (tidyName(name) === '') {
            return undefined;
        }

        sent++;

        return errors[`people.${sent}`]?.[0];
    });
}
