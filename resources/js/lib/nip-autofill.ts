/**
 * State of the fields filled in from the NIP register lookup.
 * `filled` are the fields the lookup put values in; `failed` means the previous NIP was rejected.
 */
export type NipAutofill = { failed: boolean; filled: Set<string> };

export function newNipAutofill(): NipAutofill {
    return { failed: false, filled: new Set() };
}

/**
 * A wrong NIP: empties the fields the earlier lookup filled in (what the person typed themselves stays)
 * and remembers the failure, so the next successful lookup replaces everything it can.
 */
export function clearAutofilled<T extends Record<string, string>>(data: T, state: NipAutofill): T {
    const next = { ...data };

    state.filled.forEach((key) => {
        if (key in next) {
            (next as Record<string, string>)[key] = '';
        }
    });
    state.filled.clear();
    state.failed = true;

    return next;
}

/**
 * Puts the newly fetched values into the form. After a wrong NIP (or for fields the previous lookup filled in)
 * the values are replaced, including the empty ones; otherwise only empty fields are filled so that
 * what the person typed is kept.
 */
export function mergeLookup<T extends Record<string, string>>(data: T, values: Partial<Record<keyof T & string, string | null | undefined>>, state: NipAutofill): T {
    const next = { ...data };
    const replaceAll = state.failed;

    (Object.keys(values) as (keyof T & string)[]).forEach((key) => {
        const value = values[key] ?? '';

        if (replaceAll || state.filled.has(key) || next[key] === '') {
            (next as Record<string, string>)[key] = value;

            if (value !== '') {
                state.filled.add(key);
            } else {
                state.filled.delete(key);
            }
        }
    });

    state.failed = false;

    return next;
}
